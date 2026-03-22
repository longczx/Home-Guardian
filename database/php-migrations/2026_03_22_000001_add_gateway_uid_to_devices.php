<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->string('gateway_uid', 64)->nullable()->default(null)
                ->comment('所属网关的 device_uid。NULL=独立设备，非 NULL=挂载在该网关下的传感器');
            $table->index('gateway_uid');
        });
    }

    public function down(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->dropIndex(['gateway_uid']);
            $table->dropColumn('gateway_uid');
        });
    }
};
