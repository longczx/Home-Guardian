<?php
namespace app\service;
use app\model\{Automation, Device, Home};
use app\exception\BusinessException;
class AutomationPolicyService
{
    public static function validate(array $config, callable $checkDevice): void
    {
        $modes = $config['modes'] ?? [];
        if (!is_array($modes) || count($modes) > 3 || array_filter($modes, fn ($mode) => !is_string($mode) || !in_array($mode, ['home','away','sleep'], true))) throw new BusinessException('家庭模式配置无效', 422, 4002);
        $conditions = $config['conditions'] ?? [];
        if (!is_array($conditions) || count($conditions) > 10 || !in_array($config['condition_logic'] ?? 'all', ['all','any'], true)) throw new BusinessException('组合条件最多 10 项，关系必须为 all/any', 422, 4002);
        foreach ($conditions as $condition) {
            if (!is_array($condition)) throw new BusinessException('组合条件格式无效', 422, 4002);
            $checkDevice($condition['device_id'] ?? 0);
            if (!is_string($condition['metric_key'] ?? null) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $condition['metric_key'])
                || !in_array($condition['condition'] ?? '', ['GREATER_THAN','LESS_THAN','EQUALS','NOT_EQUALS'], true)
                || !(is_numeric($condition['value'] ?? null) || is_bool($condition['value'] ?? null))) throw new BusinessException('组合条件指标/比较值无效', 422, 4002);
            self::validateAge($condition['max_age_sec'] ?? 900);
        }
        self::validateAge($config['max_age_sec'] ?? 900);
        if (isset($config['time_window'])) {
            $w = $config['time_window'];
            if (!is_array($w) || !is_string($w['start'] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $w['start'] ?? '') || !is_string($w['end'] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $w['end'] ?? '')) throw new BusinessException('时间窗口格式必须为 HH:MM', 422, 4002);
        }
        if (isset($config['timezone']) && (!is_string($config['timezone']) || !in_array($config['timezone'], \DateTimeZone::listIdentifiers(), true))) throw new BusinessException('时区无效', 422, 4002);
    }
    private static function validateAge(mixed $age): void
    {
        if (filter_var($age, FILTER_VALIDATE_INT) === false || $age < 10 || $age > 86400) throw new BusinessException('数据有效期必须为 10-86400 秒', 422, 4002);
    }
    public static function deviceIds(Automation $automation): array
    {
        return array_values(array_unique(array_filter(array_merge(array_column($automation->actions ?? [], 'device_id'),
            [$automation->trigger_config['device_id'] ?? null], array_column($automation->trigger_config['conditions'] ?? [], 'device_id')))));
    }
    public static function check(Automation $automation): array
    {
        $config = $automation->trigger_config ?? []; $checks = [];
        $add = static function (string $label, bool $passed, string $reason) use (&$checks) { $checks[] = compact('label','passed','reason'); };
        $add('规则启用', (bool)$automation->is_enabled, $automation->is_enabled ? '规则已启用' : '规则已停用');
        $home = Home::find($automation->home_id);
        $mode = $home?->mode ?? 'home';
        $add('家庭模式', empty($config['modes']) || in_array($mode, $config['modes'], true), '当前模式：' . $mode);
        if (isset($config['time_window'])) {
            $time = now()->setTimezone($config['timezone'] ?? 'Asia/Shanghai')->format('H:i');
            $start = $config['time_window']['start']; $end = $config['time_window']['end'];
            $inside = $start === $end || ($start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end);
            $add('时间窗口', $inside, "{$time}，允许 {$start}-{$end}");
        }
        $conditionChecks = [];
        foreach ($config['conditions'] ?? [] as $condition) {
            $device = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->find($condition['device_id']);
            $reading = $device ? ObservationService::latest($device->id, $condition['metric_key']) : null;
            $fresh = ObservationService::fresh($reading, (int)($condition['max_age_sec'] ?? 900));
            $passed = $device && $fresh && ObservationService::compare($reading['value'], $condition['condition'], $condition['value']);
            $conditionChecks[] = ['label'=>($device?->name ?? '设备已删除') . '/' . $condition['metric_key'], 'passed'=>(bool)$passed,
                'reason'=>!$fresh ? '数据缺失或过期' : '最新值：' . json_encode($reading['value'], JSON_UNESCAPED_UNICODE), 'observed_at'=>$reading['ts'] ?? null];
        }
        if ($conditionChecks) {
            $values = array_column($conditionChecks, 'passed');
            $passed = ($config['condition_logic'] ?? 'all') === 'any' ? in_array(true, $values, true) : !in_array(false, $values, true);
            $add('组合条件', $passed, ($config['condition_logic'] ?? 'all') === 'any' ? '任一满足' : '全部满足');
        }
        foreach ($automation->actions ?? [] as $action) {
            if (($action['type'] ?? '') !== 'device_command') continue;
            $device = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->find($action['device_id'] ?? 0);
            if ($device?->manual_override_until?->isFuture()) $add('人工接管', false, $device->name . ' 暂停至 ' . $device->manual_override_until->toIso8601String());
        }
        return ['eligible'=>!in_array(false, array_column($checks, 'passed'), true), 'checks'=>$checks, 'conditions'=>$conditionChecks];
    }
    public static function preview(Automation $automation): array
    {
        $result = self::check($automation); $config = $automation->trigger_config;
        if ($automation->trigger_type === 'telemetry') {
            $triggerDevice = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->find((int)$config['device_id']);
            $reading = $triggerDevice ? ObservationService::latest($triggerDevice->id, $config['metric_key']) : null;
            $passed = ObservationService::fresh($reading, (int)($config['max_age_sec'] ?? 900)) && ObservationService::compare($reading['value'], $config['condition'], $config['value']);
            $result['checks'][] = ['label'=>'触发阈值', 'passed'=>$passed, 'reason'=>!$triggerDevice ? '触发设备已删除' : ($reading ? '最新值：' . json_encode($reading['value']) : '暂无数据'), 'observed_at'=>$reading['ts'] ?? null];
            if (!$passed) $result['eligible'] = false;
        } elseif ($automation->trigger_type === 'schedule') {
            $due = (new \Cron\CronExpression($config['cron']))->isDue(now()->setTimezone($config['timezone'] ?? 'Asia/Shanghai'));
            $result['checks'][] = ['label'=>'计划到期', 'passed'=>$due, 'reason'=>$config['cron']];
            if (!$due) $result['eligible'] = false;
        }
        $redis = \support\Redis::connection('default');
        if ($automation->trigger_type === 'telemetry') {
            $latched = (bool)$redis->exists("automation:latch:{$automation->id}");
            $result['checks'][] = ['label'=>'重新触发', 'passed'=>!$latched, 'reason'=>$latched ? '本次持续满足已执行，需条件恢复后再满足' : '尚未执行本次条件'];
            if ($latched) $result['eligible'] = false;
            $last = (int)$redis->get("automation:last:{$automation->id}");
            $remaining = max(0, (int)($config['cooldown_sec'] ?? 0) - (time() - $last));
            $result['checks'][] = ['label'=>'冷却间隔', 'passed'=>$remaining === 0, 'reason'=>$remaining ? "还需等待 {$remaining} 秒" : '已满足'];
            if ($remaining) $result['eligible'] = false;
            $duration = (int)($config['duration_sec'] ?? 0);
            $first = (int)$redis->get("automation:duration:{$automation->id}");
            $durationRemaining = $duration ? max(0, $duration - ($first ? time()-$first : 0)) : 0;
            $result['checks'][] = ['label'=>'持续满足', 'passed'=>$durationRemaining === 0, 'reason'=>$durationRemaining ? "尚需持续 {$durationRemaining} 秒" : '已满足'];
            if ($durationRemaining) $result['eligible'] = false;
        }
        foreach ($automation->actions ?? [] as $index => $action) {
            if (($action['type'] ?? '') !== 'device_command') continue;
            $device = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->find($action['device_id']);
            $reason = !$device ? '设备已删除' : (!$device->is_online ? '设备离线' : ($device->manual_override_until?->isFuture() ? '人工接管中' : '可以提交控制'));
            $passed = $device && $device->is_online && !$device->manual_override_until?->isFuture();
            if ($passed && \app\model\CommandLog::where('device_id', $device->id)->pending()->exists()) { $passed = false; $reason = '上一条指令仍在等待回执'; }
            $result['checks'][] = ['label'=>'动作 ' . ($index+1), 'passed'=>(bool)$passed, 'reason'=>$reason];
            if (!$passed) $result['eligible'] = false;
        }
        $result['note'] = '只读取当前快照，不发送指令。所有条件在实际触发时再次判断；本次不会启动持续计时或改变冷却状态。';
        return $result;
    }
}
