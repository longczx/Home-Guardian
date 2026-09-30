<?php

namespace Tests\Integration;

use app\model\Automation;
use app\model\CommandLog;
use app\model\Device;
use app\model\NotificationChannel;
use app\model\NotificationDelivery;
use app\process\DataIngestProcess;
use app\process\NotificationProcess;
use app\service\AutomationService;
use app\service\MqttCommandService;
use app\service\ReliableQueue;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Redis;

class ReliabilityTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Set TEST_REDIS_HOST to run isolated integration tests');
        $this->redis = new Redis();
        $this->redis->connect(getenv('TEST_REDIS_HOST'));
        $this->redis->select(1);
        $this->redis->del('hg:q:data_ingest_queue', 'hg:q:data_ingest_processing', 'hg:q:data_ingest_deadletter',
            'hg:q:notify:queue', 'hg:q:notify:queue:processing', 'hg:q:notify:queue:deadletter', 'hg:q:mqtt:command:send', 'hg:q:mqtt:command:processing');
        NotificationDelivery::query()->delete();
    }

    public function test_reservations_survive_consumer_restart_and_ack_only_committed_items(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $this->redis->lPush($key, 'a', 'b');
        $first = new ReliableQueue($this->redis, $key, "$key:pending");
        self::assertCount(2, $first->reserve(2));
        $first->acknowledge('a');
        $next = new ReliableQueue($this->redis, $key, "$key:pending");
        self::assertSame(['b'], $next->reserve(2));
        $next->move('b', "$key:dead");
        self::assertSame([], $next->reserve(2));
        self::assertSame(['b'], $this->redis->lRange("$key:dead", 0, -1));
        $this->redis->del($key, "$key:pending", "$key:dead");
    }

    public function test_recovered_commands_keep_original_fifo_order(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $this->redis->lPush($key, 'first', 'second', 'third');
        $queue = new ReliableQueue($this->redis, $key, "$key:pending");
        self::assertSame(['first', 'second', 'third'], $queue->reserve(3));
        $restarted = new ReliableQueue($this->redis, $key, "$key:pending");
        self::assertSame(['first', 'second'], $restarted->reserve(2));
        $restarted->acknowledge('first');
        self::assertSame(['second', 'third'], $restarted->reserve(2));
        $this->redis->del($key, "$key:pending");
    }

    public function test_postgres_ingest_replay_is_idempotent_and_bad_rows_do_not_discard_good_rows(): void
    {
        if (!getenv('TEST_PGSQL_HOST')) $this->markTestSkipped('Set TEST_PGSQL_HOST');
        $manager = $GLOBALS['test_capsule']->getDatabaseManager();
        $GLOBALS['test_capsule']->addConnection(['driver' => 'pgsql', 'host' => getenv('TEST_PGSQL_HOST'),
            'database' => 'guardian_test', 'username' => 'guardian_test', 'password' => 'guardian_test',
            'schema' => 'public', 'charset' => 'utf8'], 'integration');
        $previous = $manager->getDefaultConnection();
        $manager->setDefaultConnection('integration');
        try {
            $db = (new Device)->getConnection();
            $db->statement('CREATE TABLE IF NOT EXISTS telemetry_logs (ts timestamptz NOT NULL, device_id bigint NOT NULL,
                metric_key text NOT NULL, value jsonb NOT NULL, event_id varchar(32), UNIQUE(ts,event_id))');
            $event = bin2hex(random_bytes(16));
            $item = ['ts' => '2026-09-30 12:00:00+08', 'device_id' => 1, 'metric_key' => 'temperature', 'value' => '25', 'event_id' => $event];
            $raw = json_encode($item);
            $this->redis->lPush('hg:q:data_ingest_queue', $raw, json_encode([...$item, 'ts' => 'bad-date']));
            $process = new DataIngestProcess();
            (new \ReflectionProperty($process, 'redis'))->setValue($process, $this->redis);
            $process->processBatch();
            self::assertSame(1, $db->table('telemetry_logs')->where('event_id', $event)->count());
            self::assertSame(1, $this->redis->lLen('hg:q:data_ingest_deadletter'));
            $this->redis->lPush('hg:q:data_ingest_processing', $raw); // commit-before-ACK crash
            (new DataIngestProcess())->processBatch();
            self::assertSame(1, $db->table('telemetry_logs')->where('event_id', $event)->count());
            self::assertSame(0, $this->redis->lLen('hg:q:data_ingest_processing'));
            $db->table('telemetry_logs')->where('event_id', $event)->delete();
        } finally { $manager->setDefaultConnection($previous); }
    }

    public function test_notifications_persist_independent_results_and_retry_only_failed_channel(): void
    {
        if (!getenv('TEST_SMTP_HOST')) $this->markTestSkipped('Set TEST_SMTP_HOST');
        $email = NotificationChannel::create(['name' => 'integration SMTP', 'type' => 'email', 'is_enabled' => true,
            'config' => ['smtp_host' => getenv('TEST_SMTP_HOST'), 'smtp_port' => 1025, 'smtp_tls' => false,
                'from' => 'test@example.com', 'to' => ['recipient@example.com']]]);
        $broken = NotificationChannel::create(['name' => 'missing webhook', 'type' => 'webhook', 'is_enabled' => true, 'config' => []]);
        $task = json_encode(['task_id' => bin2hex(random_bytes(16)), 'channel_ids' => [$email->id, $broken->id], 'title' => 'SMTP regression', 'content' => 'Delivered by real SMTP']);
        $this->redis->lPush('hg:q:notify:queue', $task);
        $this->redis->lPush('hg:q:notify:queue', '{invalid');
        $worker = new NotificationProcess();
        (new \ReflectionProperty($worker, 'redis'))->setValue($worker, $this->redis);
        $worker->processQueue();
        self::assertSame(1, $this->redis->lLen('hg:q:notify:queue:deadletter'));
        $sent = NotificationDelivery::where('channel_id', $email->id)->firstOrFail();
        $failed = NotificationDelivery::where('channel_id', $broken->id)->firstOrFail();
        self::assertSame('sent', $sent->status);
        self::assertSame('pending', $failed->status);
        self::assertNotEmpty($failed->last_error);
        $this->redis->lPush('hg:q:notify:queue:processing', $task);
        $worker->processQueue();
        self::assertSame(2, NotificationDelivery::count());
        self::assertSame(1, (int)$sent->fresh()->attempts);
        $failed->update(['attempts' => 4, 'next_attempt_at' => now()->subSecond()]);
        $worker->deliverPending();
        self::assertSame('failed', $failed->fresh()->status);
        self::assertSame(5, (int)$failed->fresh()->attempts);
        $email->delete(); $broken->delete();
    }

    public function test_matching_telemetry_executes_once_until_condition_recovers(): void
    {
        $device = Device::create(['device_uid' => 'automation-' . bin2hex(random_bytes(4)), 'name' => 'sensor']);
        $rule = Automation::create(['name' => 'edge test', 'trigger_type' => 'telemetry',
            'trigger_config' => ['device_id' => $device->id, 'metric_key' => 'temp', 'condition' => 'GREATER_THAN', 'value' => 30], 'actions' => []]);
        \support\Redis::connection('default')->del("automation:latch:{$rule->id}", "automation:duration:{$rule->id}", "automation:last:{$rule->id}");
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        $first = $rule->fresh()->last_triggered_at;
        self::assertNotNull($first);
        $rule->update(['last_triggered_at' => '2000-01-01 00:00:00']);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 41);
        self::assertSame('2000', $rule->fresh()->last_triggered_at->format('Y'));
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 20);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        self::assertNotSame('2000', $rule->fresh()->last_triggered_at->format('Y'));
        $rule->delete(); $device->delete();
    }

    public function test_duration_and_cooldown_are_required_before_rearming(): void
    {
        $device = Device::create(['device_uid' => 'duration-' . bin2hex(random_bytes(4)), 'name' => 'sensor']);
        $rule = Automation::create(['name' => 'duration test', 'trigger_type' => 'telemetry', 'actions' => [],
            'trigger_config' => ['device_id' => $device->id, 'metric_key' => 'temp', 'condition' => 'GREATER_THAN',
                'value' => 30, 'duration_sec' => 10, 'cooldown_sec' => 60]]);
        $redis = \support\Redis::connection('default');
        $redis->del("automation:duration:{$rule->id}", "automation:latch:{$rule->id}", "automation:last:{$rule->id}");
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        self::assertNull($rule->fresh()->last_triggered_at);
        $redis->set("automation:duration:{$rule->id}", time() - 11);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        self::assertNotNull($rule->fresh()->last_triggered_at);
        $rule->update(['last_triggered_at' => '2000-01-01 00:00:00']);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 20);
        $redis->set("automation:duration:{$rule->id}", time() - 11);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        self::assertSame('2000', $rule->fresh()->last_triggered_at->format('Y'));
        $redis->set("automation:last:{$rule->id}", time() - 61);
        AutomationService::evaluateTelemetry([$rule], $device->id, 'temp', 40);
        self::assertNotSame('2000', $rule->fresh()->last_triggered_at->format('Y'));
        $redis->del("automation:duration:{$rule->id}", "automation:latch:{$rule->id}", "automation:last:{$rule->id}");
        $rule->delete(); $device->delete();
    }

    public function test_reply_from_other_device_cannot_complete_command(): void
    {
        $device = Device::create(['device_uid' => 'reply-' . bin2hex(random_bytes(4)), 'name' => 'target']);
        $log = CommandLog::create(['request_id' => 'test-' . bin2hex(random_bytes(4)), 'device_id' => $device->id,
            'topic' => 'test/topic', 'payload' => [], 'status' => 'queued', 'sent_at' => now()]);
        MqttCommandService::handleCommandReply($log->request_id, 'replied_ok', [], 'another-device');
        self::assertSame('queued', $log->fresh()->status);
        MqttCommandService::handleCommandReply($log->request_id, 'replied_ok', [], $device->device_uid);
        self::assertSame('replied_ok', $log->fresh()->status);
        $log->delete(); $device->delete();
    }

    public function test_command_stays_reserved_until_broker_puback(): void
    {
        $device = Device::create(['device_uid' => 'puback-' . bin2hex(random_bytes(4)), 'name' => 'target', 'is_online' => true]);
        $command = MqttCommandService::sendCommand($device->id, ['action' => 'ping']);
        self::assertSame('queued', $command->status);
        $callback = null;
        $client = $this->getMockBuilder(\Workerman\Mqtt\Client::class)->disableOriginalConstructor()->onlyMethods(['publish'])->getMock();
        $client->expects(self::once())->method('publish')->willReturnCallback(
            function ($topic, $payload, $options, $onAck) use (&$callback): void { $callback = $onAck; });
        $worker = new \app\process\MqttSubscriber();
        foreach (['redis' => $this->redis, 'mqttClient' => $client, 'mqttConnected' => true] as $property => $value) {
            (new \ReflectionProperty($worker, $property))->setValue($worker, $value);
        }
        $worker->processCommandQueue();
        self::assertSame(1, $this->redis->lLen('hg:q:mqtt:command:processing'));
        self::assertSame('queued', $command->fresh()->status);
        $callback(null);
        self::assertSame(0, $this->redis->lLen('hg:q:mqtt:command:processing'));
        self::assertSame('sent', $command->fresh()->status);
        $command->delete(); $device->delete();
    }

    public function test_migrations_apply_and_rollback_on_timescale_hypertable(): void
    {
        if (!getenv('TEST_PGSQL_HOST')) $this->markTestSkipped('Set TEST_PGSQL_HOST');
        $capsule = $GLOBALS['test_capsule'];
        $capsule->addConnection(['driver' => 'pgsql', 'host' => getenv('TEST_PGSQL_HOST'),
            'database' => 'guardian_test', 'username' => 'guardian_test', 'password' => 'guardian_test',
            'charset' => 'utf8'], 'migration_test');
        $db = $capsule->getConnection('migration_test');
        $schema = 'hg_migration_' . bin2hex(random_bytes(4));
        $db->statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
        $db->statement("CREATE SCHEMA {$schema}");
        $db->statement("SET search_path TO {$schema}, public");
        try {
            $db->statement('CREATE TABLE users (id bigserial PRIMARY KEY)');
            $db->statement('CREATE TABLE telemetry_logs (ts timestamptz NOT NULL, device_id bigint NOT NULL, metric_key text, value jsonb)');
            $db->select("SELECT create_hypertable('{$schema}.telemetry_logs', 'ts')");
            $migrations = [];
            foreach (glob(__DIR__ . '/../../database/php-migrations/2026_09_30_*.php') as $file) {
                $migration = require $file;
                $migration->db = $db;
                $migration->up();
                $migrations[] = $migration;
            }
            $db->insert('INSERT INTO users DEFAULT VALUES');
            self::assertSame(0, (int)$db->table('users')->value('auth_version'));
            $db->insert("INSERT INTO telemetry_logs VALUES (now(), 1, 'temp', '25', 'migration-event')");
            self::assertSame(1, $db->table('telemetry_logs')->count());
            $db->insert("INSERT INTO notification_deliveries (home_id, delivery_key, channel_id, title, content) VALUES (1, 'migration', 1, 'test', 'test')");
            self::assertSame('pending', $db->table('notification_deliveries')->value('status'));
            foreach (array_reverse($migrations) as $migration) $migration->down();
            self::assertSame(1, $db->table('telemetry_logs')->count());
        } finally {
            $db->statement('SET search_path TO public');
            $db->statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
