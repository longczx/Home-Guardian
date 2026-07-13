<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\model\AlertRule;

/**
 * 告警规则条件判断（AlertRule::evaluate）测试
 *
 * 纯内存逻辑，不依赖数据库 / Redis。重点覆盖新增的 BETWEEN / NOT_BETWEEN
 * 以及各比较条件、非法输入的边界。
 */
class AlertRuleTest extends TestCase
{
    private function rule(string $condition, array $threshold): AlertRule
    {
        $r = new AlertRule();
        $r->condition = $condition;
        $r->threshold_value = $threshold;
        return $r;
    }

    /* ============ 单阈值条件 ============ */

    public function test_greater_than(): void
    {
        $r = $this->rule(AlertRule::CONDITION_GREATER_THAN, [30]);
        $this->assertTrue($r->evaluate(31));
        $this->assertFalse($r->evaluate(30));
        $this->assertFalse($r->evaluate(29));
    }

    public function test_less_than(): void
    {
        $r = $this->rule(AlertRule::CONDITION_LESS_THAN, [30]);
        $this->assertTrue($r->evaluate(29));
        $this->assertFalse($r->evaluate(30));
        $this->assertFalse($r->evaluate(31));
    }

    public function test_equals(): void
    {
        $r = $this->rule(AlertRule::CONDITION_EQUALS, [25]);
        $this->assertTrue($r->evaluate(25));
        $this->assertTrue($r->evaluate(25.00001)); // 容差内
        $this->assertFalse($r->evaluate(26));
    }

    public function test_not_equals(): void
    {
        $r = $this->rule(AlertRule::CONDITION_NOT_EQUALS, [25]);
        $this->assertTrue($r->evaluate(26));
        $this->assertFalse($r->evaluate(25));
    }

    /* ============ 区间条件（#12 新增） ============ */

    public function test_between_inclusive_bounds(): void
    {
        $r = $this->rule(AlertRule::CONDITION_BETWEEN, [16, 30]);
        $this->assertTrue($r->evaluate(16));   // 下界含
        $this->assertTrue($r->evaluate(30));   // 上界含
        $this->assertTrue($r->evaluate(23));
        $this->assertFalse($r->evaluate(15.9));
        $this->assertFalse($r->evaluate(30.1));
    }

    public function test_between_auto_swaps_reversed_bounds(): void
    {
        $r = $this->rule(AlertRule::CONDITION_BETWEEN, [30, 16]); // 顺序写反
        $this->assertTrue($r->evaluate(20));
        $this->assertFalse($r->evaluate(40));
    }

    public function test_not_between(): void
    {
        $r = $this->rule(AlertRule::CONDITION_NOT_BETWEEN, [16, 30]);
        $this->assertTrue($r->evaluate(15));
        $this->assertTrue($r->evaluate(31));
        $this->assertFalse($r->evaluate(16));
        $this->assertFalse($r->evaluate(23));
    }

    public function test_between_with_insufficient_bounds_is_false(): void
    {
        $r = $this->rule(AlertRule::CONDITION_BETWEEN, [16]); // 缺上界
        $this->assertFalse($r->evaluate(20));
    }

    public function test_between_with_nonnumeric_bound_is_false(): void
    {
        $r = $this->rule(AlertRule::CONDITION_BETWEEN, [16, 'x']);
        $this->assertFalse($r->evaluate(20));
    }

    /* ============ 非法输入 ============ */

    public function test_nonnumeric_value_is_false(): void
    {
        $r = $this->rule(AlertRule::CONDITION_GREATER_THAN, [30]);
        $this->assertFalse($r->evaluate('not-a-number'));
        $this->assertFalse($r->evaluate(null));
    }

    public function test_nonnumeric_threshold_is_false(): void
    {
        $r = $this->rule(AlertRule::CONDITION_GREATER_THAN, ['abc']);
        $this->assertFalse($r->evaluate(5));
    }

    public function test_unknown_condition_is_false(): void
    {
        $r = $this->rule('NOT_A_REAL_CONDITION', [30]);
        $this->assertFalse($r->evaluate(99));
    }

    /* ============ 告警分级标签（闭环/降噪） ============ */

    public function test_severity_label(): void
    {
        $this->assertSame('提醒', AlertRule::severityLabel(AlertRule::SEVERITY_INFO));
        $this->assertSame('警告', AlertRule::severityLabel(AlertRule::SEVERITY_WARNING));
        $this->assertSame('严重', AlertRule::severityLabel(AlertRule::SEVERITY_CRITICAL));
        // 未知/空 → 默认「警告」
        $this->assertSame('警告', AlertRule::severityLabel(null));
        $this->assertSame('警告', AlertRule::severityLabel('bogus'));
    }

    public function test_valid_enum_sets(): void
    {
        $this->assertContains('critical', AlertRule::VALID_SEVERITIES);
        $this->assertContains('offline', AlertRule::VALID_TRIGGER_TYPES);
        $this->assertContains('telemetry', AlertRule::VALID_TRIGGER_TYPES);
    }
}
