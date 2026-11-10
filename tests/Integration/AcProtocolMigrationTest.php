<?php
namespace Tests\Integration;

use app\service\AcProtocolCatalog;
use PHPUnit\Framework\TestCase;

class AcProtocolMigrationTest extends TestCase
{
    public function test_firmware_config_page_recognizes_ac_children_and_renders_ir_build_settings(): void
    {
        $view = new \think\Template(array_merge(config('view.options'), [
            'view_path'=>dirname(__DIR__, 2).'/app/view/', 'cache_path'=>sys_get_temp_dir().'/hg-ir-template-check/',
        ]));
        ob_start();
        try {
            $view->fetch('admin/device/firmware-config', [
                'device'=>['name'=>'gateway', 'device_uid'=>'test-gateway'],
                'sensors'=>[['id'=>1, 'name'=>'空调', 'device_uid'=>'test-ac', 'type'=>'ac', 'metric_fields'=>null]],
                'mqttHost'=>'mqtt.invalid', 'mqttPort'=>1883, 'nav'=>'devices',
                'adminUser'=>['username'=>'test', 'fullName'=>'test'],
            ]);
            $html = ob_get_contents();
        } finally { ob_end_clean(); }
        self::assertStringContainsString("module: 'ac_ir'", $html);
        self::assertStringContainsString('#define AC_IR_ENABLED', $html);
        self::assertStringContainsString('#define AC_PROTOCOL', $html);
        self::assertStringContainsString('#define AC_IR_UID', $html);
    }

    public function test_catalog_expands_existing_ir_devices_without_rewriting_custom_controls(): void
    {
        $capsule = $GLOBALS['test_capsule'];
        $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:'], 'ir_catalog_migration');
        $db = $capsule->getConnection('ir_catalog_migration');
        $db->getSchemaBuilder()->create('capability_templates', function ($t) {
            $t->id(); $t->string('device_category'); $t->string('control_mode'); $t->json('controls'); $t->timestamp('updated_at')->nullable();
        });
        $db->getSchemaBuilder()->create('devices', function ($t) { $t->id(); $t->json('capability')->nullable(); });
        $custom = ['key'=>'power', 'command'=>'set_state', 'param'=>'power', 'label'=>'我的开关', 'default'=>false];
        $controls = [$custom, ['key'=>'protocol', 'command'=>'set_state', 'param'=>'protocol', 'options'=>[['value'=>'COOLIX', 'label'=>'旧选项']]]];
        $db->table('capability_templates')->insert(['device_category'=>'ac', 'control_mode'=>'merge', 'controls'=>json_encode($controls)]);
        $cap = ['control_mode'=>'merge', 'custom_metadata'=>'preserve', 'controls'=>$controls];
        $db->table('devices')->insert([['id'=>1, 'capability'=>json_encode($cap)], ['id'=>2, 'capability'=>null], ['id'=>3, 'capability'=>json_encode(['control_mode'=>'merge', 'controls'=>[$custom]])]]);
        $migration = require __DIR__.'/../../database/php-migrations/2026_11_10_000001_expand_ac_ir_protocols.php';
        $migration->db = $db;
        try {
            $migration->up(); $migration->up();
            $updated = json_decode($db->table('devices')->where('id', 1)->value('capability'), true);
            self::assertSame('preserve', $updated['custom_metadata']);
            self::assertSame($custom, $updated['controls'][0]);
            self::assertCount(3, $updated['controls'], 'Re-running the migration must not duplicate controls');
            self::assertCount(count(AcProtocolCatalog::protocols()), $updated['controls'][1]['options']);
            self::assertSame('model', $updated['controls'][2]['param']);
            self::assertNull($db->table('devices')->where('id', 2)->value('capability'));
            self::assertSame([$custom], json_decode($db->table('devices')->where('id', 3)->value('capability'), true)['controls']);
            self::assertCount(3, json_decode($db->table('capability_templates')->value('controls'), true));
            $migration->down();
            self::assertSame(3, $db->table('devices')->count(), 'Rollback must preserve device data and selected protocols');
        } finally { $capsule->getDatabaseManager()->purge('ir_catalog_migration'); }
    }
}
