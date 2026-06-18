<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\service\ActuatorService;
use app\model\Device;
use app\exception\BusinessException;

/**
 * 执行器控制服务测试
 *
 * 校验逻辑（validate）在 applyCommand 中先于任何 Redis/MQTT 调用执行，
 * 因此所有「拒绝」用例都可通过公开 API applyCommand 触发，无需外部依赖。
 * 成功校验与纯辅助方法（defaultState/statePatchFor）通过反射单独验证。
 */
class ActuatorServiceTest extends TestCase
{
    /** 空调风格的 merge 能力定义 */
    private function acCapability(): array
    {
        return [
            'control_mode' => 'merge',
            'controls' => [
                ['command' => 'set_state', 'param' => 'power', 'state_key' => 'power', 'value_type' => 'bool', 'default' => false],
                ['command' => 'set_state', 'param' => 'mode',  'value_type' => 'enum', 'default' => 'cool',
                 'options' => [['value' => 'cool'], ['value' => 'heat'], ['value' => 'dry']]],
                ['command' => 'set_state', 'param' => 'temp',  'value_type' => 'int', 'min' => 16, 'max' => 30, 'default' => 26],
            ],
        ];
    }

    private function deviceWith(?array $cap): Device
    {
        $d = new Device();
        $d->capability = $cap;
        return $d;
    }

    /**
     * 断言 applyCommand 以指定 businessCode 拒绝
     */
    private function assertRejects(?array $cap, string $action, array $params, int $businessCode): void
    {
        try {
            ActuatorService::applyCommand($this->deviceWith($cap), $action, $params);
            $this->fail("预期抛出 BusinessException($businessCode)，但未抛出");
        } catch (BusinessException $e) {
            $this->assertSame($businessCode, $e->getBusinessCode(), $e->getMessage());
        }
    }

    /* ============ 拒绝用例（公开 API） ============ */

    public function test_rejects_device_without_capability(): void
    {
        $this->assertRejects(null, 'set_state', ['power' => true], 2100);
        $this->assertRejects([], 'set_state', ['power' => true], 2100);
    }

    public function test_rejects_unsupported_action(): void
    {
        $this->assertRejects($this->acCapability(), 'launch_rocket', [], 2101);
    }

    public function test_rejects_unknown_param(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['brightness' => 50], 2102);
    }

    public function test_rejects_wrong_bool_type(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['power' => 'yes'], 2103);
    }

    public function test_rejects_non_numeric_int(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['temp' => 'hot'], 2103);
    }

    public function test_rejects_value_above_max(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['temp' => 40], 2104);
    }

    public function test_rejects_value_below_min(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['temp' => 10], 2104);
    }

    public function test_rejects_invalid_enum(): void
    {
        $this->assertRejects($this->acCapability(), 'set_state', ['mode' => 'turbo'], 2105);
    }

    /* ============ 成功校验（反射，隔离纯逻辑） ============ */

    public function test_accepts_valid_params(): void
    {
        $m = new \ReflectionMethod(ActuatorService::class, 'validate');
        $m->setAccessible(true);
        // 未抛异常即通过
        $m->invoke(null, $this->acCapability(), 'set_state', ['power' => true, 'mode' => 'cool', 'temp' => 26]);
        $this->assertTrue(true);
    }

    public function test_accepts_boundary_values(): void
    {
        $m = new \ReflectionMethod(ActuatorService::class, 'validate');
        $m->setAccessible(true);
        $m->invoke(null, $this->acCapability(), 'set_state', ['temp' => 16]); // 下界
        $m->invoke(null, $this->acCapability(), 'set_state', ['temp' => 30]); // 上界
        $this->assertTrue(true);
    }

    /* ============ 纯辅助方法 ============ */

    public function test_default_state_built_from_controls(): void
    {
        $m = new \ReflectionMethod(ActuatorService::class, 'defaultState');
        $m->setAccessible(true);
        $state = $m->invoke(null, $this->acCapability());

        $this->assertSame(['power' => false, 'mode' => 'cool', 'temp' => 26], $state);
    }

    public function test_state_patch_for_discrete_uses_state_key(): void
    {
        $cap = [
            'control_mode' => 'discrete',
            'controls' => [
                ['command' => 'set_brightness', 'param' => 'value', 'state_key' => 'brightness', 'value_type' => 'int'],
            ],
        ];

        $m = new \ReflectionMethod(ActuatorService::class, 'statePatchFor');
        $m->setAccessible(true);
        $patch = $m->invoke(null, $cap, 'set_brightness', ['value' => 80]);

        $this->assertSame(['brightness' => 80], $patch);
    }
}
