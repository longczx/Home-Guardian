<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('metric_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 50)->unique();
            $table->string('label', 100);
            $table->string('unit', 20)->default('');
            $table->string('icon', 10)->default('📊');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        // 自动更新 updated_at 触发器
        $this->db()->unprepared("
            CREATE TRIGGER trg_metric_definitions_updated_at
                BEFORE UPDATE ON metric_definitions
                FOR EACH ROW
                EXECUTE FUNCTION update_updated_at();
        ");

        // 预置 6 种常用指标
        $this->db()->table('metric_definitions')->insert([
            ['metric_key' => 'temperature', 'label' => '温度',   'unit' => '°C',    'icon' => '🌡', 'description' => '环境温度',     'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['metric_key' => 'humidity',    'label' => '湿度',   'unit' => '%',     'icon' => '💧', 'description' => '环境湿度',     'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['metric_key' => 'pressure',    'label' => '气压',   'unit' => 'hPa',   'icon' => '🌀', 'description' => '大气压力',     'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['metric_key' => 'co2',         'label' => 'CO₂',   'unit' => 'ppm',   'icon' => '☁️', 'description' => '二氧化碳浓度', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['metric_key' => 'light',       'label' => '光照',   'unit' => 'lux',   'icon' => '☀️', 'description' => '光照强度',     'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['metric_key' => 'pm25',        'label' => 'PM2.5', 'unit' => 'μg/m³', 'icon' => '🌫', 'description' => '细颗粒物浓度', 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('metric_definitions');
    }
};
