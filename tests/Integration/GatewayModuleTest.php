<?php
namespace Tests\Integration;

use app\exception\BusinessException;
use app\model\{Device, Home, CommandLog, DeviceState};
use app\service\{DeviceControlService, GatewayModuleService, MqttCommandService};
use PHPUnit\Framework\TestCase;

class GatewayModuleTest extends TestCase
{
    private Home $home;
    protected function setUp(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $this->home = Home::create(['name'=>'modules']);
    }
    protected function tearDown(): void
    {
        if (!isset($this->home)) return;
        $ids = Device::withoutGlobalScopes()->where('home_id', $this->home->id)->pluck('id');
        $logs = CommandLog::whereIn('device_id', $ids)->pluck('request_id');
        (new Device)->getConnection()->table('command_outbox')->whereIn('request_id', $logs)->delete();
        CommandLog::whereIn('device_id', $ids)->delete();
        DeviceState::whereIn('device_id', $ids)->delete();
        Device::withoutGlobalScopes()->whereIn('id', $ids)->delete();
        $this->home->delete();
    }
    private function gateway(): Device
    {
        return Device::create(['home_id'=>$this->home->id, 'device_uid'=>'modules-'.bin2hex(random_bytes(4)),
            'name'=>'gateway', 'type'=>'gateway', 'is_online'=>true, 'capability'=>null]);
    }
    public function test_gateway_management_does_not_require_actuator_capability_or_change_state(): void
    {
        $device = $this->gateway();
        foreach ([['list_modules', []], ['add_module', ['type'=>'ac_ir', 'pin'=>25]],
            ['update_module', ['uid'=>'existing-ac','pin'=>26]], ['remove_module', ['uid'=>'existing-ac']]] as [$action, $params]) {
            $command = DeviceControlService::send($device, $action, $params, true);
            self::assertSame($action, $command->payload['action']);
            self::assertSame($params, $command->payload['params']);
            self::assertNull($device->fresh()->manual_override_until);
            MqttCommandService::handleCommandReply($command->request_id, 'replied_ok',
                ['status'=>'ok', 'uid'=>'existing-ac', 'applied'=>true], $device->device_uid);
            self::assertTrue($command->fresh()->reply['applied']);
        }
        self::assertSame(0, DeviceState::where('device_id', $device->id)->count());
    }
    public function test_pending_module_queries_do_not_block_mutations_but_pending_mutations_do(): void
    {
        $device = $this->gateway();
        DeviceControlService::send($device, 'list_modules', [], true);
        $mutation = DeviceControlService::send($device, 'add_module', ['type'=>'sound','pin'=>34], true);
        // More than the recent-history window must not hide the pending mutation.
        for ($i = 0; $i < 21; $i++) self::assertNotNull(DeviceControlService::send($device, 'list_modules', [], true));
        try {
            DeviceControlService::send($device, 'update_module', ['uid'=>'sound','pin'=>35], true);
            self::fail('Pending mutation must prevent another mutation');
        } catch (BusinessException $e) { self::assertSame(2112, $e->getBusinessCode()); }
        $mutation->update(['status'=>'replied_ok']);
    }
    public function test_rejects_child_targets_unsupported_types_and_invalid_parameters(): void
    {
        $device = $this->gateway();
        foreach ([['add_module',['type'=>'relay','pin'=>25]], ['update_module',['uid'=>'ac','pin'=>260]],
            ['update_module',['uid'=>'ac','pin'=>'26']], ['update_module',['uid'=>'ac','pin'=>26.5]],
            ['update_module',['uid'=>'ac','pin'=>true]], ['update_module',['pin'=>26]],
            ['list_modules',['pin'=>25]], ['remove_module',['uid'=>'ac','type'=>'ac_ir']]] as [$action, $params]) {
            try { GatewayModuleService::validate($device, $action, $params); self::fail('Invalid module command accepted'); }
            catch (BusinessException $e) { self::assertSame(2121, $e->getBusinessCode()); }
        }
        $device->update(['gateway_uid'=>'parent', 'type'=>'ac']);
        $this->expectException(BusinessException::class);
        GatewayModuleService::validate($device, 'list_modules', []);
    }
}
