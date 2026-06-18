<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('capability_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('模板名，如 空调/调光灯/开关');
            $table->string('device_category', 50)->nullable()->comment('归类: switch/light/ac/curtain，用于图标');
            $table->string('control_mode', 20)->default('discrete')->comment('merge | discrete');
            $table->jsonb('controls')->default('[]')->comment('控制点数组');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        // 自动更新 updated_at 触发器
        $this->db()->unprepared("
            CREATE TRIGGER trg_capability_templates_updated_at
                BEFORE UPDATE ON capability_templates
                FOR EACH ROW
                EXECUTE FUNCTION update_updated_at();
        ");

        // 预置 3 个起步模板
        $now = now();
        $rows = [
            [
                'name' => '开关', 'device_category' => 'switch', 'control_mode' => 'discrete',
                'description' => '单路开关/插座',
                'controls' => [
                    ['key' => 'power', 'label' => '电源', 'widget' => 'switch', 'value_type' => 'bool',
                     'command' => 'set_power', 'param' => 'on', 'state_key' => 'power', 'default' => false, 'icon' => '🔌', 'order' => 1],
                ],
            ],
            [
                'name' => '调光灯', 'device_category' => 'light', 'control_mode' => 'discrete',
                'description' => '可开关 + 调亮度',
                'controls' => [
                    ['key' => 'power', 'label' => '电源', 'widget' => 'switch', 'value_type' => 'bool',
                     'command' => 'set_power', 'param' => 'on', 'state_key' => 'power', 'default' => false, 'icon' => '💡', 'order' => 1],
                    ['key' => 'brightness', 'label' => '亮度', 'widget' => 'slider', 'value_type' => 'int',
                     'command' => 'set_brightness', 'param' => 'value', 'state_key' => 'brightness',
                     'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => 50,
                     'depends_on' => ['power' => true], 'order' => 2],
                ],
            ],
            [
                'name' => '空调', 'device_category' => 'ac', 'control_mode' => 'merge',
                'description' => '红外/智能空调，全量状态下发',
                'controls' => [
                    ['key' => 'power', 'label' => '电源', 'widget' => 'switch', 'value_type' => 'bool',
                     'command' => 'set_state', 'param' => 'power', 'state_key' => 'power', 'default' => false, 'icon' => '❄️', 'order' => 1],
                    ['key' => 'mode', 'label' => '模式', 'widget' => 'enum', 'value_type' => 'enum',
                     'command' => 'set_state', 'param' => 'mode', 'state_key' => 'mode', 'default' => 'cool',
                     'options' => [
                         ['label' => '制冷', 'value' => 'cool'], ['label' => '制热', 'value' => 'heat'],
                         ['label' => '除湿', 'value' => 'dry'], ['label' => '送风', 'value' => 'fan'],
                     ],
                     'depends_on' => ['power' => true], 'order' => 2],
                    ['key' => 'temp', 'label' => '温度', 'widget' => 'stepper', 'value_type' => 'int',
                     'command' => 'set_state', 'param' => 'temp', 'state_key' => 'temp',
                     'min' => 16, 'max' => 30, 'step' => 1, 'unit' => '°C', 'default' => 26,
                     'depends_on' => ['power' => true], 'order' => 3],
                    ['key' => 'fan', 'label' => '风速', 'widget' => 'enum', 'value_type' => 'enum',
                     'command' => 'set_state', 'param' => 'fan', 'state_key' => 'fan', 'default' => 'auto',
                     'options' => [
                         ['label' => '自动', 'value' => 'auto'], ['label' => '低', 'value' => 'low'],
                         ['label' => '中', 'value' => 'mid'], ['label' => '高', 'value' => 'high'],
                     ],
                     'depends_on' => ['power' => true], 'order' => 4],
                    ['key' => 'swing', 'label' => '扫风', 'widget' => 'switch', 'value_type' => 'bool',
                     'command' => 'set_state', 'param' => 'swing', 'state_key' => 'swing', 'default' => false,
                     'depends_on' => ['power' => true], 'order' => 5],
                ],
            ],
        ];

        foreach ($rows as $r) {
            $this->db()->table('capability_templates')->insert([
                'name'            => $r['name'],
                'device_category' => $r['device_category'],
                'control_mode'    => $r['control_mode'],
                'controls'        => json_encode($r['controls'], JSON_UNESCAPED_UNICODE),
                'description'     => $r['description'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('capability_templates');
    }
};
