<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

/**
 * 告警闭环与降噪：
 *  - alert_rules: severity(分级) / notify_cooldown_sec(通知冷却) /
 *    notify_on_recovery(恢复通知) / trigger_type(telemetry|offline) / offline_timeout_sec
 *  - alert_logs:  severity / resolved_at(自动恢复时间)
 *  - 放开 telemetry_key/condition/threshold_value 的 NOT NULL（offline 规则不需要它们）
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('alert_rules', function (Blueprint $table) {
            $table->string('severity', 20)->default('warning')->comment('info/warning/critical');
            $table->integer('notify_cooldown_sec')->default(600)->comment('通知冷却秒数(防轰炸)');
            $table->boolean('notify_on_recovery')->default(true)->comment('恢复时是否发通知');
            $table->string('trigger_type', 20)->default('telemetry')->comment('telemetry|offline');
            $table->integer('offline_timeout_sec')->default(120)->comment('offline 规则：心跳超时秒数');
        });

        // offline 规则不需要遥测三件套 → 放开非空约束（PG 原生，避免依赖 doctrine/dbal）
        $this->db()->unprepared('ALTER TABLE alert_rules ALTER COLUMN telemetry_key DROP NOT NULL');
        $this->db()->unprepared('ALTER TABLE alert_rules ALTER COLUMN condition DROP NOT NULL');
        $this->db()->unprepared('ALTER TABLE alert_rules ALTER COLUMN threshold_value DROP NOT NULL');

        $this->schema()->table('alert_logs', function (Blueprint $table) {
            $table->string('severity', 20)->nullable();
            $table->timestampTz('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('alert_rules', function (Blueprint $table) {
            $table->dropColumn(['severity', 'notify_cooldown_sec', 'notify_on_recovery',
                                'trigger_type', 'offline_timeout_sec']);
        });
        $this->schema()->table('alert_logs', function (Blueprint $table) {
            $table->dropColumn(['severity', 'resolved_at']);
        });
    }
};
