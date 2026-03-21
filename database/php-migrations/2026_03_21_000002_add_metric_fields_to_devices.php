<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->jsonb('metric_fields')->nullable()->default(null)
                ->comment('设备指标配置，JSONB 数组。NULL=使用全局定义，[]=无指标');
        });
    }

    public function down(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->dropColumn('metric_fields');
        });
    }
};
