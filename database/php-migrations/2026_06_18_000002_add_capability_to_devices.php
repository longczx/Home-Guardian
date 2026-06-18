<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->jsonb('capability')->nullable()
                ->comment('执行器能力 {control_mode, controls:[...]}，NULL=无控制能力');
        });
    }

    public function down(): void
    {
        $this->schema()->table('devices', function (Blueprint $table) {
            $table->dropColumn('capability');
        });
    }
};
