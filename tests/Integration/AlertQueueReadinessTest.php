<?php
namespace Tests\Integration;

use app\model\{AlertLog, AlertRule, CommandLog, Device};
use app\process\AlertEngineProcess;
use app\service\{AlertService, HealthService, MqttCommandService};
use PHPUnit\Framework\TestCase;
use support\Redis;

class AlertQueueReadinessTest extends TestCase
{
    private array $deviceIds = [];

    private function device(): Device
    {
        $device = Device::create(['device_uid'=>'review-'.bin2hex(random_bytes(6)), 'name'=>'test', 'type'=>'gateway', 'is_online'=>true]);
        $this->deviceIds[] = $device->id;
        return $device;
    }

    protected function tearDown(): void
    {
        $requests = CommandLog::whereIn('device_id', $this->deviceIds)->pluck('request_id');
        (new Device)->getConnection()->table('command_outbox')->whereIn('request_id', $requests)->delete();
        CommandLog::whereIn('device_id', $this->deviceIds)->delete();
        AlertLog::whereIn('device_id', $this->deviceIds)->delete();
        Device::whereIn('id', $this->deviceIds)->delete();
    }

    public function test_events_stay_reserved_until_both_rule_sets_load_then_are_consumed(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $redis = new \Redis(); $redis->connect(getenv('TEST_REDIS_HOST')); $redis->select(1);
        $ready = 'hg:q:alert_stream'; $processing = 'hg:q:alert_processing';
        $redis->del($ready, $processing);
        $redis->lPush($ready, json_encode(['device_id'=>1, 'metric_key'=>'temp', 'value'=>40]));
        $redis->lPush($processing, json_encode(['device_id'=>1, 'metric_key'=>'temp', 'value'=>41]));
        $engine = new AlertEngineProcess();
        (new \ReflectionProperty($engine, 'redis'))->setValue($engine, $redis);
        try {
            $engine->processAlertStream();
            self::assertSame(1, $redis->lLen($ready), 'Startup must not reserve ready events');
            self::assertSame(1, $redis->lLen($processing), 'Startup must not acknowledge reservations');
            (new \ReflectionProperty($engine, 'rulesLoaded'))->setValue($engine, true);
            $engine->processAlertStream();
            self::assertSame(1, $redis->lLen($processing), 'Automation policy must also be loaded');
            (new \ReflectionProperty($engine, 'automationsLoaded'))->setValue($engine, true);
            $engine->processAlertStream();
            self::assertSame(0, $redis->lLen($processing), 'Loaded empty rule sets are valid');
            $engine->processAlertStream();
            self::assertSame(0, $redis->lLen($ready));
            self::assertSame(0, $redis->lLen($processing));
        } finally { $redis->del($ready, $processing); }
    }

    public function test_legacy_resolution_does_not_block_new_incident_or_fabricate_recovery(): void
    {
        $device = $this->device();
        $rule = new AlertRule(['home_id'=>1, 'name'=>'temperature']); $rule->id = 934441;
        $legacy = AlertLog::create(['rule_id'=>$rule->id, 'device_id'=>$device->id, 'status'=>'resolved',
            'triggered_at'=>now()->subDays(60), 'resolved_at'=>now()->subDays(59), 'incident_active'=>false]);
        // Adding a note to historical data must not reopen its incident.
        $legacy->update(['handled_at'=>now(), 'handling_note'=>'reviewed old record']);
        $current = AlertService::triggerAlert($rule, $device->id, 40);
        self::assertNotNull($current, 'Legacy resolution must not suppress a new incident');
        AlertService::resolveAlert($current->id, 1);
        self::assertNull(AlertService::triggerAlert($rule, $device->id, 40), 'Manual resolution does not mean sensor recovery');
        AlertService::resolveActive($rule, $device->id, 20);
        self::assertFalse($current->fresh()->incident_active);
        self::assertNotNull($current->fresh()->recovered_at);
        self::assertNull($legacy->fresh()->recovered_at, 'Unknown historical recovery must stay unknown');
        self::assertNotNull(AlertService::triggerAlert($rule, $device->id, 40));
    }

    public function test_incident_migration_preserves_manual_incidents_and_historical_timestamps(): void
    {
        $capsule = $GLOBALS['test_capsule'];
        $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:'], 'incident_migration');
        $db = $capsule->getConnection('incident_migration');
        $db->getSchemaBuilder()->create('alert_logs', function ($t) {
            $t->id(); $t->string('status'); $t->timestamp('handled_at')->nullable();
            $t->timestamp('recovered_at')->nullable(); $t->timestamp('resolved_at')->nullable();
        });
        $db->table('alert_logs')->insert([
            ['id'=>1, 'status'=>'resolved', 'handled_at'=>null, 'recovered_at'=>null, 'resolved_at'=>'2026-09-01 10:00:00'],
            ['id'=>2, 'status'=>'resolved', 'handled_at'=>'2026-11-01 10:00:00', 'recovered_at'=>null, 'resolved_at'=>'2026-11-01 10:00:00'],
            ['id'=>3, 'status'=>'triggered', 'handled_at'=>null, 'recovered_at'=>null, 'resolved_at'=>null],
            ['id'=>4, 'status'=>'resolved', 'handled_at'=>null, 'recovered_at'=>'2026-11-01 10:00:00', 'resolved_at'=>'2026-11-01 10:00:00'],
        ]);
        $migration = require __DIR__.'/../../database/php-migrations/2026_11_08_000001_track_active_alert_incidents.php';
        $migration->db = $db;
        try {
            $migration->up();
            self::assertSame([0, 1, 1, 0], $db->table('alert_logs')->orderBy('id')->pluck('incident_active')->map(fn ($v) => (int)$v)->all());
            self::assertNull($db->table('alert_logs')->where('id', 1)->value('recovered_at'));
            self::assertSame('2026-09-01 10:00:00', $db->table('alert_logs')->where('id', 1)->value('resolved_at'));
            $migration->down();
            self::assertFalse($db->getSchemaBuilder()->hasColumn('alert_logs', 'incident_active'));
            self::assertSame(4, $db->table('alert_logs')->count());
        } finally { $capsule->getDatabaseManager()->purge('incident_migration'); }
    }

    public function test_health_detects_stale_legacy_commands_and_unknown_queue_age(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $queue = Redis::connection('queue'); $key = 'mqtt:command:send';
        $queue->del($key);
        $device = $this->device();
        $command = MqttCommandService::sendCommand($device->id, ['action'=>'get_info']);
        try {
            $envelope = json_decode($queue->rPop($key), true);
            self::assertArrayHasKey('ts', $envelope, 'New queue messages must carry their original enqueue time');
            unset($envelope['ts']);
            $command->update(['sent_at'=>now()->subMinutes(3)]);
            $queue->lPush($key, json_encode($envelope));
            $health = HealthService::snapshot();
            self::assertFalse($health['checks']['queue:'.$key], 'Legacy commands use the durable log timestamp');
            self::assertGreaterThanOrEqual(180, $health['queues'][$key]['oldest_age_sec']);
            $queue->del($key);
            $queue->lPush($key, json_encode(['request_id'=>'missing-record']));
            self::assertFalse(HealthService::snapshot()['checks']['queue:'.$key], 'Nonempty queues with unknown age are unhealthy');
            $queue->del($key);
            self::assertTrue(HealthService::snapshot()['checks']['queue:'.$key], 'An empty queue is healthy');
        } finally { $queue->del($key); }
    }
}
