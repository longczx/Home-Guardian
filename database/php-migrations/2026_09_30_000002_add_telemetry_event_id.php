<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('telemetry_logs', function (Blueprint $table) {
            $table->string('event_id', 32)->nullable();
            // TimescaleDB unique indexes must include the time partition column.
            $table->unique(['ts', 'event_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('telemetry_logs', function (Blueprint $table) {
            $table->dropUnique(['ts', 'event_id']);
            $table->dropColumn('event_id');
        });
    }
};
