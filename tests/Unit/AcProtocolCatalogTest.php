<?php
namespace Tests\Unit;

use app\service\{AcProtocolCatalog, ActuatorService};
use app\exception\BusinessException;
use PHPUnit\Framework\TestCase;

class AcProtocolCatalogTest extends TestCase
{
    public function test_catalog_accepts_all_advertised_protocols_and_models(): void
    {
        $controls = AcProtocolCatalog::controls();
        $cap = ['control_mode'=>'merge', 'controls'=>$controls];
        $names = array_column(AcProtocolCatalog::protocols(), 'value');
        self::assertSame(count($names), count(array_unique($names)));
        self::assertNotContains('YORK', $names, 'The locked library advertises YORK without implementing sendAc');
        foreach (['KELON168', 'KELON', 'LG', 'SHARP_AC', 'TOSHIBA_AC', 'HAIER_AC176', 'HITACHI_AC424', 'DAIKIN216'] as $name) self::assertContains($name, $names);
        foreach (AcProtocolCatalog::protocols() as $spec) {
            foreach ($spec['models'] as $model) {
                $params = ['protocol'=>$spec['value'], 'model'=>$model['value']];
                ActuatorService::validate($cap, 'set_state', $params);
                self::assertSame($params, AcProtocolCatalog::mergeState([], $params));
            }
        }
    }

    public function test_switching_protocol_resets_model_but_preserves_other_desired_settings(): void
    {
        $next = AcProtocolCatalog::mergeState(['protocol'=>'PANASONIC_AC', 'model'=>6, 'power'=>true, 'temp'=>25], ['protocol'=>'KELON168']);
        self::assertSame(['protocol'=>'KELON168', 'model'=>1, 'power'=>true, 'temp'=>25], $next);
        self::assertSame(3, AcProtocolCatalog::mergeState(['protocol'=>'GREE', 'model'=>3], ['temp'=>26])['model']);
        self::assertSame(1, AcProtocolCatalog::mergeState(['protocol'=>'COOLIX'], ['power'=>false])['model']);
    }

    public function test_invalid_protocol_and_wrong_model_are_rejected(): void
    {
        foreach ([['protocol'=>'NEC', 'model'=>1], ['protocol'=>'KELON168', 'model'=>6], ['protocol'=>'GREE', 'model'=>1.5]] as $params) {
            try { AcProtocolCatalog::mergeState([], $params); self::fail('Unsupported IR input was accepted'); }
            catch (BusinessException $e) { self::assertContains($e->getBusinessCode(), [2114, 2115]); }
        }
    }
}
