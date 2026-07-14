<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

/**
 * uniPush 推送设备表
 *
 * 记录用户在各端登录后上报的推送 cid（个推 clientId）。
 * 告警触发时，unipush 通知渠道据此向对应家庭成员的设备推送。
 *   - push_enabled: 该设备是否接收推送
 *   - min_severity: 仅推送 >= 此级别的告警（info < warning < critical）
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('user_push_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('home_id')->default(1)->comment('冗余归属家庭，便于按家庭定向推送');
            $table->string('cid', 128)->comment('个推 clientId');
            $table->string('platform', 20)->default('app')->comment('app / mp-weixin / h5');
            $table->boolean('push_enabled')->default(true);
            $table->string('min_severity', 20)->default('warning')->comment('info/warning/critical');
            $table->timestampTz('last_active_at')->nullable();
            $table->timestampsTz();

            $table->unique('cid');
            $table->index(['home_id', 'push_enabled']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_push_devices');
    }
};
