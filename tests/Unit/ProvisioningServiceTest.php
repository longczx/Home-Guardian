<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\service\ProvisioningService;
use app\model\ProvisionCode;
use app\model\Device;
use app\exception\BusinessException;

/**
 * 设备自助配网服务测试（基于 SQLite 内存库）
 */
class ProvisioningServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Device::query()->delete();
        ProvisionCode::query()->delete();
    }

    /* ============ createCode ============ */

    public function test_create_code_format_and_persist(): void
    {
        $rec = ProvisioningService::createCode(1, '客厅');

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $rec->code);
        $this->assertEquals('pending', $rec->status);
        $this->assertEquals('客厅', $rec->location);
        $this->assertEquals(1, $rec->user_id);
        // 未来某个时间过期
        $this->assertGreaterThan(time(), strtotime((string)$rec->expires_at));
        // 落库
        $this->assertTrue(ProvisionCode::where('code', $rec->code)->exists());
    }

    public function test_create_code_empty_location_is_null(): void
    {
        $rec = ProvisioningService::createCode(1, '');
        $this->assertNull($rec->location);
    }

    /* ============ register 成功 ============ */

    public function test_register_creates_gateway_and_sensors(): void
    {
        $code = ProvisioningService::createCode(7, '书房')->code;

        $result = ProvisioningService::register([
            'provision_code' => $code,
            'gateway' => ['device_uid' => 'esp32-aabbcc', 'name' => '书房网关', 'firmware_version' => '1.2.0'],
            'sensors' => [
                ['device_uid' => 'esp32-aabbcc-temp', 'name' => '温湿度', 'type' => 'sensor'],
                ['name' => '噪音', 'type' => 'sensor'], // 不给 uid → 自动生成
            ],
        ]);

        // 返回 MQTT 凭证
        $this->assertSame('esp32-aabbcc', $result['mqtt']['username']);
        $this->assertNotEmpty($result['mqtt']['password']);
        $this->assertSame('esp32-aabbcc', $result['gateway_uid']);
        $this->assertCount(3, $result['devices']); // 1 网关 + 2 传感器

        // 网关落库正确
        $gw = Device::where('device_uid', 'esp32-aabbcc')->first();
        $this->assertNotNull($gw);
        $this->assertSame('gateway', $gw->type);
        $this->assertSame('书房', $gw->location);
        $this->assertNotEmpty($gw->mqtt_password_hash);
        // 明文不入库，仅哈希；且能校验通过
        $this->assertTrue(password_verify($result['mqtt']['password'], $gw->mqtt_password_hash));

        // 传感器挂在网关下、继承位置、自动 uid
        $s1 = Device::where('device_uid', 'esp32-aabbcc-temp')->first();
        $this->assertSame('esp32-aabbcc', $s1->gateway_uid);
        $this->assertSame('书房', $s1->location);
        $this->assertNotNull(Device::where('device_uid', 'esp32-aabbcc-s2')->first());

        // 配对码标记已用
        $rec = ProvisionCode::where('code', $code)->first();
        $this->assertSame('registered', $rec->status);
        $this->assertSame('esp32-aabbcc', $rec->device_uid);
        $this->assertNotNull($rec->used_at);
    }

    public function test_register_is_idempotent_upsert(): void
    {
        $code1 = ProvisioningService::createCode(1, 'A')->code;
        ProvisioningService::register([
            'provision_code' => $code1,
            'gateway' => ['device_uid' => 'esp32-dup', 'name' => '旧名'],
        ]);

        // 同一设备重新配网（新码）→ upsert，不报错，名称/位置更新
        $code2 = ProvisioningService::createCode(1, 'B')->code;
        ProvisioningService::register([
            'provision_code' => $code2,
            'gateway' => ['device_uid' => 'esp32-dup', 'name' => '新名'],
        ]);

        $this->assertSame(1, Device::where('device_uid', 'esp32-dup')->count());
        $gw = Device::where('device_uid', 'esp32-dup')->first();
        $this->assertSame('新名', $gw->name);
        $this->assertSame('B', $gw->location);
    }

    /* ============ register 拒绝 ============ */

    public function test_register_rejects_missing_code(): void
    {
        $this->expectException(BusinessException::class);
        ProvisioningService::register(['gateway' => ['device_uid' => 'x']]);
    }

    public function test_register_rejects_invalid_code(): void
    {
        $this->expectException(BusinessException::class);
        ProvisioningService::register([
            'provision_code' => 'NOTACODE',
            'gateway' => ['device_uid' => 'x'],
        ]);
    }

    public function test_register_rejects_used_code(): void
    {
        $code = ProvisioningService::createCode(1, null)->code;
        ProvisioningService::register([
            'provision_code' => $code,
            'gateway' => ['device_uid' => 'esp32-first'],
        ]);

        // 再次使用同一码 → 已注册，拒绝
        $this->expectException(BusinessException::class);
        ProvisioningService::register([
            'provision_code' => $code,
            'gateway' => ['device_uid' => 'esp32-second'],
        ]);
    }

    public function test_register_rejects_expired_code(): void
    {
        // 手动造一个已过期的 pending 码
        $rec = ProvisionCode::create([
            'code'       => 'EXPIRED1',
            'user_id'    => 1,
            'status'     => ProvisionCode::STATUS_PENDING,
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);

        try {
            ProvisioningService::register([
                'provision_code' => 'EXPIRED1',
                'gateway' => ['device_uid' => 'esp32-x'],
            ]);
            $this->fail('过期码应被拒绝');
        } catch (BusinessException $e) {
            // 并被标记为 expired
            $this->assertSame(ProvisionCode::STATUS_EXPIRED, ProvisionCode::where('code', 'EXPIRED1')->first()->status);
        }
    }

    public function test_register_rejects_missing_gateway_uid(): void
    {
        $code = ProvisioningService::createCode(1, null)->code;
        $this->expectException(BusinessException::class);
        ProvisioningService::register(['provision_code' => $code, 'gateway' => []]);
    }

    /* ============ statusOf ============ */

    public function test_status_pending_then_registered(): void
    {
        $code = ProvisioningService::createCode(5, '厨房')->code;

        $s1 = ProvisioningService::statusOf($code, 5);
        $this->assertSame('pending', $s1['status']);
        $this->assertNull($s1['device']);

        ProvisioningService::register([
            'provision_code' => $code,
            'gateway' => ['device_uid' => 'esp32-kitchen', 'name' => '厨房网关'],
        ]);

        $s2 = ProvisioningService::statusOf($code, 5);
        $this->assertSame('registered', $s2['status']);
        $this->assertSame('esp32-kitchen', $s2['device']['device_uid']);
    }

    public function test_status_rejects_other_users_code(): void
    {
        $code = ProvisioningService::createCode(5, null)->code;
        $this->expectException(BusinessException::class);
        ProvisioningService::statusOf($code, 999); // 不是本人
    }
}
