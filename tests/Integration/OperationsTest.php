<?php
namespace Tests\Integration;

require_once __DIR__ . '/../../app/functions.php';

use app\model\{Automation, AutomationRun, CommandLog, Device, NotificationChannel, NotificationDelivery};
use app\service\{AutomationService, AutomationRunService, MqttCommandService};
use app\controller\DeviceDiagnosticsController;
use PHPUnit\Framework\TestCase;
use support\Request;

class OperationsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        AutomationRun::query()->delete();
    }
    public function test_command_ack_and_notification_delivery_determine_run_outcome(): void
    {
        $device = Device::create(['device_uid'=>'ops-' . bin2hex(random_bytes(4)), 'name'=>'switch', 'is_online'=>true,
            'home_id'=>1, 'location'=>'bedroom', 'capability'=>['control_mode'=>'discrete','controls'=>[
                ['command'=>'set_power','param'=>'on','value_type'=>'bool','state_key'=>'power']]]]);
        $channel = NotificationChannel::create(['name'=>'ops', 'type'=>'webhook','config'=>[]]);
        $automation = Automation::create(['name'=>'ops', 'trigger_type'=>'schedule', 'trigger_config'=>['cron'=>'* * * * *'],
            'actions'=>[['type'=>'device_command','device_id'=>$device->id,'payload'=>['action'=>'set_power','params'=>['on'=>true]]],
                        ['type'=>'notify','channel_ids'=>[$channel->id]]]]);
        AutomationService::executeActions($automation);
        $run = AutomationRun::first();
        self::assertSame('running', $run->status);
        self::assertSame(['bedroom'], $run->locations);
        $action = $run->action_results[0];
        MqttCommandService::handleCommandReply($action['request_id'], 'replied_ok', ['firmware'=>'1.2'], 'wrong-uid');
        self::assertSame('running', AutomationRunService::refresh($run)->status);
        MqttCommandService::handleCommandReply($action['request_id'], 'replied_ok', ['firmware'=>'1.2'], $device->device_uid);
        self::assertSame(['firmware'=>'1.2'], CommandLog::find($action['command_id'])->reply);
        $notification = $run->fresh()->action_results[1];
        NotificationDelivery::create(['home_id'=>1,'delivery_key'=>hash('sha256',$notification['task_id'].':'.$channel->id),
            'channel_id'=>$channel->id,'title'=>'test','content'=>'test','status'=>'failed','attempts'=>5]);
        self::assertSame('partial_failed', AutomationRunService::refresh($run->fresh())->status);
        self::assertNotNull($run->fresh()->finished_at);
        $automation->delete(); $device->delete(); $channel->delete();
    }
    public function test_invalid_action_is_recorded_and_does_not_stop_other_actions(): void
    {
        $automation = Automation::create(['name'=>'invalid ops', 'trigger_type'=>'schedule', 'trigger_config'=>[],
            'actions'=>[['type'=>'unknown'], ['type'=>'device_command','device_id'=>999999,'payload'=>[]]]]);
        AutomationService::executeActions($automation);
        $run = AutomationRun::first();
        self::assertSame('failed', $run->status);
        self::assertCount(2, $run->action_results);
        $automation->delete();
    }
    public function test_diagnostics_cannot_probe_another_family_or_location(): void
    {
        $controller = new DeviceDiagnosticsController();
        $request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->user = (object)['home_id'=>1, 'locations'=>['bedroom']];
        foreach ([['home_id'=>2, 'location'=>'bedroom'], ['home_id'=>1,'location'=>'kitchen']] as $fields) {
            $device = Device::create($fields + ['device_uid'=>'private-' . bin2hex(random_bytes(4)), 'name'=>'private', 'type'=>'gateway']);
            self::assertSame(404, $controller->show($request, $device->id)->getStatusCode());
            self::assertSame(404, $controller->probe($request, $device->id)->getStatusCode());
            $device->delete();
        }
    }
    public function test_unfinished_submission_cannot_be_finalized_between_actions(): void
    {
        $run = AutomationRun::create(['home_id'=>1,'automation_id'=>1,'automation_name'=>'race','trigger_type'=>'schedule',
            'trigger_context'=>[], 'locations'=>[], 'action_results'=>[['status'=>'failed']], 'started_at'=>now(), 'status'=>'running']);
        self::assertSame('running', AutomationRunService::refresh($run)->status);
        $run->update(['submission_finished'=>true, 'action_results'=>[['status'=>'failed'],['status'=>'success']]]);
        self::assertSame('partial_failed', AutomationRunService::refresh($run)->status);
    }
    public function test_diagnostics_exposes_only_safe_information_and_preserves_last_known_values(): void
    {
        $device = Device::create(['device_uid'=>'diagnose-'.bin2hex(random_bytes(4)), 'name'=>'gateway',
            'home_id'=>1, 'location'=>'bedroom', 'type'=>'gateway', 'mqtt_password_hash'=>'private']);
        CommandLog::create(['request_id'=>'diagnose-'.bin2hex(random_bytes(4)), 'device_id'=>$device->id, 'topic'=>'test',
            'payload'=>['action'=>'get_info'], 'status'=>'replied_ok', 'sent_at'=>now(), 'replied_at'=>now(),
            'reply'=>['wifi_rssi'=>-60, 'firmware'=>'1.1', 'secret'=>'private']]);
        $request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->user = (object)['home_id'=>1, 'locations'=>['bedroom']];
        $response = (new DeviceDiagnosticsController)->show($request, $device->id);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true)['data'];
        self::assertSame(-60, $body['info']['wifi_rssi']);
        self::assertArrayNotHasKey('secret', $body['info']);
        self::assertArrayNotHasKey('mqtt_password_hash', $body['device']);
        self::assertFalse($body['device']['is_online']);
        $device->delete();
    }
    public function test_postgres_migration_and_execution_history_location_isolation(): void
    {
        if (!getenv('TEST_PGSQL_HOST')) $this->markTestSkipped('Requires PostgreSQL');
        $capsule = $GLOBALS['test_capsule'];
        $capsule->addConnection(['driver'=>'pgsql','host'=>getenv('TEST_PGSQL_HOST'), 'database'=>'guardian_test',
            'username'=>'guardian_test','password'=>'guardian_test','charset'=>'utf8'], 'ops_migration');
        $db = $capsule->getConnection('ops_migration');
        $schema = 'hg_ops_' . bin2hex(random_bytes(4));
        $db->statement("CREATE SCHEMA {$schema}"); $db->statement("SET search_path TO {$schema}, public");
        $manager = $capsule->getDatabaseManager(); $previous = $manager->getDefaultConnection();
        try {
            $db->statement('CREATE TABLE command_logs(id bigint PRIMARY KEY, payload jsonb)');
            $db->statement("INSERT INTO command_logs VALUES(1, '{}')");
            $migration = require __DIR__ . '/../../database/php-migrations/2026_10_02_000001_add_operations_records.php';
            $migration->db = $db; $migration->up();
            self::assertNull($db->selectOne('SELECT reply FROM command_logs WHERE id=1')->reply);
            $manager->setDefaultConnection('ops_migration');
            foreach ([[1,['bedroom']], [1,['bedroom','kitchen']], [2,['bedroom']]] as [$home, $locations]) {
                AutomationRun::create(['home_id'=>$home,'automation_id'=>1,'automation_name'=>'history', 'trigger_type'=>'schedule',
                    'trigger_context'=>[], 'locations'=>$locations,'action_results'=>[], 'status'=>'failed','started_at'=>now()]);
            }
            $request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $request->user = (object)['home_id'=>1,'locations'=>['bedroom']];
            $response = (new \app\controller\AutomationRunController)->index($request);
            $body = json_decode($response->rawBody(), true)['data'];
            self::assertSame(1, $body['total']);
            self::assertSame(['bedroom'], $body['items'][0]['locations']);
            $migration->down();
            self::assertSame(1, $db->table('command_logs')->count());
        } finally {
            $manager->setDefaultConnection($previous); $db->statement('SET search_path TO public');
            $db->statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
