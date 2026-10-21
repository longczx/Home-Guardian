<?php
namespace Tests\Unit;

use app\service\AcIrProtocolCatalog;
use app\service\ActuatorService;
use PHPUnit\Framework\TestCase;

class AcIrProtocolCatalogTest extends TestCase
{
    public function test_existing_devices_gain_a_valid_candidate_without_changing_defaults_or_custom_choices(): void
    {
        $controls = [
            ['command'=>'set_state', 'param'=>'power', 'value_type'=>'bool', 'default'=>false],
            ['command'=>'set_state', 'param'=>'protocol', 'value_type'=>'enum', 'default'=>'COOLIX', 'options'=>[
                ['label'=>'COOLIX（通用/海信常见）', 'value'=>'COOLIX'],
                ['label'=>'my custom remote', 'value'=>'GREE'],
            ]],
        ];
        $next = AcIrProtocolCatalog::upgrade($controls);
        self::assertSame($controls[0], $next[0]);
        self::assertSame('COOLIX', $next[1]['default']);
        self::assertSame('KELON168', $next[1]['options'][0]['value']);
        self::assertSame('COOLIX（需匹配具体机型）', $next[1]['options'][1]['label']);
        self::assertSame($controls[1]['options'][1], $next[1]['options'][2]);
        self::assertSame($next, AcIrProtocolCatalog::upgrade($next));
        ActuatorService::validate(['control_mode'=>'merge', 'controls'=>$next], 'set_state', ['protocol'=>'KELON168']);
    }

    public function test_other_protocol_controls_and_custom_kelons_are_not_overwritten(): void
    {
        $controls = [['command'=>'custom', 'param'=>'protocol', 'options'=>[]],
            ['command'=>'set_state', 'param'=>'protocol', 'options'=>[['label'=>'custom', 'value'=>'KELON168']]]];
        self::assertSame($controls, AcIrProtocolCatalog::upgrade($controls));
    }

    public function test_migration_updates_existing_device_and_template_without_sending_commands(): void
    {
        $db = (new \app\model\Device)->getConnection();
        $db->getSchemaBuilder()->create('capability_templates', function ($table) {
            $table->id(); $table->string('device_category'); $table->text('controls');
        });
        $controls = [['command'=>'set_state', 'param'=>'protocol', 'default'=>'COOLIX',
            'options'=>[['label'=>'COOLIX（通用/海信常见）', 'value'=>'COOLIX']]]];
        $device = \app\model\Device::create(['device_uid'=>'ir-migration-test', 'name'=>'ac', 'type'=>'ac',
            'capability'=>['control_mode'=>'merge', 'controls'=>$controls]]);
        try {
            $id = $db->table('capability_templates')->insertGetId(['device_category'=>'ac', 'controls'=>json_encode($controls)]);
            $count = $db->table('command_logs')->count();
            $migration = require __DIR__ . '/../../database/php-migrations/2026_10_21_000001_add_hisense_kelon168_protocol.php';
            $migration->db = $db;
            $migration->up(); $migration->up();
            self::assertSame('KELON168', $device->fresh()->capability['controls'][0]['options'][0]['value']);
            self::assertSame('COOLIX', $device->fresh()->capability['controls'][0]['default']);
            $template = json_decode($db->table('capability_templates')->where('id', $id)->value('controls'), true);
            self::assertCount(2, $template[0]['options']);
            self::assertSame($count, $db->table('command_logs')->count());
        } finally {
            $device->delete();
            $db->getSchemaBuilder()->drop('capability_templates');
        }
    }
}
