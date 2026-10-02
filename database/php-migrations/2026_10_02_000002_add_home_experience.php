<?php
use Eloquent\Migrations\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void {
        $this->schema()->table('homes', function (Blueprint $t) { $t->string('mode', 20)->default('home'); $t->timestampTz('mode_changed_at')->nullable(); });
        $this->schema()->table('devices', function (Blueprint $t) { $t->timestampTz('manual_override_until')->nullable(); $t->unsignedInteger('report_interval_sec')->default(300); });
        $this->schema()->table('device_states', function (Blueprint $t) { $t->jsonb('reported_state')->nullable(); });
        $this->schema()->table('alert_logs', function (Blueprint $t) { $t->text('handling_note')->nullable(); $t->unsignedBigInteger('handled_by')->nullable(); $t->timestampTz('handled_at')->nullable(); $t->timestampTz('recovered_at')->nullable(); });
        $this->schema()->create('device_preferences', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('device_id'); $t->boolean('is_favorite')->default(false); $t->unique(['user_id', 'device_id']); });
        $this->schema()->create('rooms', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('home_id'); $t->string('name', 100); $t->integer('sort_order')->default(0); $t->unique(['home_id','name']); });
        foreach ($this->db->table('devices')->whereNotNull('location')->where('location', '!=', '')->select('home_id','location')->distinct()->get() as $row) {
            $this->db->table('rooms')->insertOrIgnore(['home_id'=>$row->home_id, 'name'=>$row->location, 'sort_order'=>0]);
        }
        // Historical reports cannot be reconstructed from a row containing desired state.
    }
    public function down(): void {
        $this->schema()->dropIfExists('rooms');
        $this->schema()->dropIfExists('device_preferences');
        $this->schema()->table('alert_logs', fn (Blueprint $t) => $t->dropColumn(['handling_note','handled_by','handled_at','recovered_at']));
        $this->schema()->table('device_states', fn (Blueprint $t) => $t->dropColumn('reported_state'));
        $this->schema()->table('devices', fn (Blueprint $t) => $t->dropColumn(['manual_override_until','report_interval_sec']));
        $this->schema()->table('homes', fn (Blueprint $t) => $t->dropColumn(['mode','mode_changed_at']));
    }
};
