<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('device_states', function (Blueprint $table) {
            $table->integer('device_id')->primary();
            $table->jsonb('state')->default('{}')->comment('当前/期望状态');
            $table->timestampTz('reported_at')->nullable()->comment('设备最后一次上报状态时间');
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('device_states');
    }
};
