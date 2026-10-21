<?php
namespace app\service;

class AcIrProtocolCatalog
{
    public static function upgrade(array $controls): array
    {
        foreach ($controls as &$control) {
            if (($control['command'] ?? '') !== 'set_state' || ($control['param'] ?? '') !== 'protocol') continue;
            $options = $control['options'] ?? [];
            foreach ($options as &$option) {
                // Correct old brand assumptions; preserve custom labels.
                if (($option['label'] ?? '') === 'COOLIX（通用/海信常见）') $option['label'] = 'COOLIX（需匹配具体机型）';
                if (($option['label'] ?? '') === 'HITACHI（海信/日立系）') $option['label'] = '日立 HITACHI_AC（需匹配具体机型）';
                if (($option['label'] ?? '') === '格兰仕 KELVINATOR') $option['label'] = 'KELVINATOR（需匹配具体机型）';
            }
            unset($option);
            if (!in_array('KELON168', array_column($options, 'value'), true)) {
                array_unshift($options, ['label'=>'海信/科龙 KELON168（需固件 1.2.0+）', 'value'=>'KELON168']);
            }
            $control['options'] = $options;
        }
        unset($control);
        return $controls;
    }
}
