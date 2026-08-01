<?php

use Eloquent\Migrations\Migrations\Migration;

/**
 * 给"空调"能力追加「红外协议」枚举控件。
 *
 * 红外空调无需接收器识别协议：在 App 里逐个下发 protocol 试，空调有反应即选定，
 * 固件切换并持久化到 NVS。协议作为 set_state 的一个参数，走既有 merge 下发链路。
 *
 * 同时给 capability_templates 的空调模板、以及已存在的空调型设备（merge + set_state/power）
 * 补上该控件，避免历史设备发 protocol 被 ActuatorService 当非法参数拒绝。
 */
return new class extends Migration
{
    /** 红外协议控件定义（value 须与 IRremoteESP8266 的 typeToString 名称一致） */
    private function protocolControl(): array
    {
        return [
            'key' => 'protocol', 'label' => '红外协议', 'widget' => 'enum', 'value_type' => 'enum',
            'command' => 'set_state', 'param' => 'protocol', 'state_key' => 'protocol', 'default' => 'COOLIX',
            'options' => [
                ['label' => 'COOLIX（通用/海信常见）', 'value' => 'COOLIX'],
                ['label' => 'HITACHI（海信/日立系）',   'value' => 'HITACHI_AC'],
                ['label' => '格力 GREE',                'value' => 'GREE'],
                ['label' => '美的 MIDEA',               'value' => 'MIDEA'],
                ['label' => '海尔 HAIER',               'value' => 'HAIER_AC'],
                ['label' => 'TCL',                      'value' => 'TCL112AC'],
                ['label' => '大金 DAIKIN',              'value' => 'DAIKIN'],
                ['label' => '格兰仕 KELVINATOR',        'value' => 'KELVINATOR'],
                ['label' => '三菱 MITSUBISHI',          'value' => 'MITSUBISHI_AC'],
                ['label' => '松下 PANASONIC',           'value' => 'PANASONIC_AC'],
                ['label' => '三星 SAMSUNG',             'value' => 'SAMSUNG_AC'],
                ['label' => '富士通 FUJITSU',           'value' => 'FUJITSU_AC'],
            ],
            'group' => 'advanced', 'order' => 9,
        ];
    }

    /** 判断一个 capability 是否为空调型（merge 且有 set_state/power 控制点） */
    private function isAcCapability(?array $cap): bool
    {
        if (!is_array($cap) || empty($cap['controls']) || !is_array($cap['controls'])) {
            return false;
        }
        if (($cap['control_mode'] ?? '') !== 'merge') {
            return false;
        }
        foreach ($cap['controls'] as $c) {
            if (($c['command'] ?? '') === 'set_state' && ($c['param'] ?? '') === 'power') {
                return true;
            }
        }
        return false;
    }

    /** 若尚无 protocol 控件则追加；返回新 controls，或 null 表示无需改动 */
    private function withProtocol(array $cap): ?array
    {
        foreach ($cap['controls'] as $c) {
            if (($c['param'] ?? null) === 'protocol') {
                return null; // 已存在
            }
        }
        $cap['controls'][] = $this->protocolControl();
        return $cap;
    }

    public function up(): void
    {
        // 1. capability_templates 中的空调模板
        $templates = $this->db()->table('capability_templates')->where('device_category', 'ac')->get();
        foreach ($templates as $t) {
            $controls = json_decode($t->controls ?? '[]', true) ?: [];
            $cap = ['control_mode' => $t->control_mode, 'controls' => $controls];
            $next = $this->withProtocol($cap);
            if ($next !== null) {
                $this->db()->table('capability_templates')->where('id', $t->id)->update([
                    'controls'   => json_encode($next['controls'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. 已存在的空调型设备
        $devices = $this->db()->table('devices')->whereNotNull('capability')->get();
        foreach ($devices as $d) {
            $cap = json_decode($d->capability ?? 'null', true);
            if (!$this->isAcCapability($cap)) {
                continue;
            }
            $next = $this->withProtocol($cap);
            if ($next !== null) {
                $this->db()->table('devices')->where('id', $d->id)->update([
                    'capability' => json_encode($next, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 回滚：从空调模板与设备中移除 protocol 控件
        $strip = function (?array $cap): ?array {
            if (!is_array($cap) || empty($cap['controls']) || !is_array($cap['controls'])) {
                return null;
            }
            $filtered = array_values(array_filter(
                $cap['controls'],
                fn ($c) => ($c['param'] ?? null) !== 'protocol'
            ));
            if (count($filtered) === count($cap['controls'])) {
                return null; // 没有 protocol，无需改
            }
            $cap['controls'] = $filtered;
            return $cap;
        };

        $templates = $this->db()->table('capability_templates')->where('device_category', 'ac')->get();
        foreach ($templates as $t) {
            $controls = json_decode($t->controls ?? '[]', true) ?: [];
            $next = $strip(['control_mode' => $t->control_mode, 'controls' => $controls]);
            if ($next !== null) {
                $this->db()->table('capability_templates')->where('id', $t->id)->update([
                    'controls'   => json_encode($next['controls'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }

        $devices = $this->db()->table('devices')->whereNotNull('capability')->get();
        foreach ($devices as $d) {
            $cap = json_decode($d->capability ?? 'null', true);
            $next = $strip($cap);
            if ($next !== null) {
                $this->db()->table('devices')->where('id', $d->id)->update([
                    'capability' => json_encode($next, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }
};
