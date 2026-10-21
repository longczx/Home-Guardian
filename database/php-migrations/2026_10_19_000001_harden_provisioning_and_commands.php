<?php
use Eloquent\Migrations\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('provision_codes', function (Blueprint $t) {
            $t->string('rebind_uid')->nullable();
            $t->string('registration_token_hash', 64)->nullable();
        });
        $this->schema()->create('alert_incident_locks', fn (Blueprint $t) => $t->string('incident_key', 64)->primary());
        $this->schema()->create('command_outbox', function (Blueprint $t) {
            $t->string('request_id')->primary();
            $t->json('message');
            $t->timestampTz('created_at');
            $t->timestampTz('dispatched_at')->nullable()->index();
        });
    }
    public function down(): void
    {
        $this->schema()->dropIfExists('command_outbox');
        $this->schema()->dropIfExists('alert_incident_locks');
        $this->schema()->table('provision_codes', fn (Blueprint $t) => $t->dropColumn(['rebind_uid', 'registration_token_hash']));
    }
};
