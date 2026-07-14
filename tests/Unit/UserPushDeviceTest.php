<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\model\UserPushDevice;

/**
 * 推送设备级别过滤逻辑（acceptsSeverity）测试 —— 纯内存，不依赖 DB。
 */
class UserPushDeviceTest extends TestCase
{
    private function device(bool $enabled, string $min): UserPushDevice
    {
        $d = new UserPushDevice();
        $d->push_enabled = $enabled;
        $d->min_severity = $min;
        return $d;
    }

    public function test_disabled_rejects_everything(): void
    {
        $d = $this->device(false, 'info');
        $this->assertFalse($d->acceptsSeverity('critical'));
    }

    public function test_threshold_warning(): void
    {
        $d = $this->device(true, 'warning');
        $this->assertTrue($d->acceptsSeverity('critical'));
        $this->assertTrue($d->acceptsSeverity('warning'));
        $this->assertFalse($d->acceptsSeverity('info'));
    }

    public function test_threshold_critical_only(): void
    {
        $d = $this->device(true, 'critical');
        $this->assertTrue($d->acceptsSeverity('critical'));
        $this->assertFalse($d->acceptsSeverity('warning'));
        $this->assertFalse($d->acceptsSeverity('info'));
    }

    public function test_threshold_info_accepts_all(): void
    {
        $d = $this->device(true, 'info');
        foreach (['info', 'warning', 'critical'] as $sev) {
            $this->assertTrue($d->acceptsSeverity($sev));
        }
    }

    public function test_null_severity_defaults_to_warning(): void
    {
        $this->assertTrue($this->device(true, 'warning')->acceptsSeverity(null));
        $this->assertFalse($this->device(true, 'critical')->acceptsSeverity(null));
    }
}
