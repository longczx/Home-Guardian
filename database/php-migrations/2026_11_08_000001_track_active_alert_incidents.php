<?php
use Eloquent\Migrations\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('alert_logs', fn (Blueprint $t) => $t->boolean('incident_active')->default(true));
        // Old resolved rows have no recovery evidence. Retire their incident without
        // inventing a recovery time; modern manual resolutions retain their incident.
        $this->db->table('alert_logs')->where(function ($query) {
            $query->whereNotNull('recovered_at')->orWhere(function ($legacy) {
                $legacy->where('status', 'resolved')->whereNull('handled_at');
            });
        })->update(['incident_active'=>false]);
    }

    public function down(): void
    {
        $this->schema()->table('alert_logs', fn (Blueprint $t) => $t->dropColumn('incident_active'));
    }
};
