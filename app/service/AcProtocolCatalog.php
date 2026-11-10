<?php
namespace app\service;

use app\exception\BusinessException;

class AcProtocolCatalog
{
    public static function protocols(): array
    {
        static $rows;
        return $rows ??= json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/ac-ir-protocols.json'), true, 512, JSON_THROW_ON_ERROR)['protocols'];
    }

    public static function find(string $protocol): ?array
    {
        foreach (self::protocols() as $row) if ($row['value'] === $protocol) return $row;
        return null;
    }

    public static function controls(): array
    {
        $rows = self::protocols();
        $models = [];
        foreach ($rows as $row) $models[$row['value']] = $row['models'];
        return [
            ['key'=>'protocol', 'label'=>'红外协议（需匹配原遥控器）', 'widget'=>'enum', 'value_type'=>'enum',
                'command'=>'set_state', 'param'=>'protocol', 'state_key'=>'protocol', 'default'=>'COOLIX',
                'options'=>array_map(fn ($r) => ['value'=>$r['value'], 'label'=>$r['label']], $rows), 'group'=>'advanced', 'order'=>9],
            ['key'=>'model', 'label'=>'遥控器型号', 'widget'=>'enum', 'value_type'=>'enum', 'command'=>'set_state',
                'param'=>'model', 'state_key'=>'model', 'default'=>1,
                'options'=>array_map(fn ($n) => ['value'=>$n, 'label'=>(string)$n], range(0, 6)),
                'options_by'=>['field'=>'protocol', 'values'=>$models], 'group'=>'advanced', 'order'=>10],
        ];
    }

    public static function isInfrared(array $cap): bool
    {
        if (($cap['control_mode'] ?? '') !== 'merge') return false;
        foreach ($cap['controls'] ?? [] as $control) {
            if (($control['command'] ?? '') === 'set_state' && ($control['param'] ?? '') === 'protocol') return true;
        }
        return false;
    }

    public static function mergeState(array $current, array $params): array
    {
        $next = array_merge($current, $params);
        $spec = self::find($next['protocol'] ?? 'COOLIX');
        if (!$spec) throw new BusinessException('不支持的红外协议，请更新设备控制配置', 422, 2114);
        if (!array_key_exists('model', $params) && (($current['protocol'] ?? null) !== $spec['value'] || !isset($next['model']))) {
            $next['model'] = $spec['default_model'];
        }
        $model = $next['model'];
        if (!is_int($model) || !in_array($model, array_column($spec['models'], 'value'), true)) {
            throw new BusinessException('遥控器型号与所选红外协议不匹配', 422, 2115);
        }
        return $next;
    }
}
