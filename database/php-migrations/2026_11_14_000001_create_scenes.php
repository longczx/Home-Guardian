<?php
use Eloquent\Migrations\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void {
        $this->db->transaction(function () {
        $this->schema()->create('scenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('home_id')->index(); $t->string('name', 100);
            $t->string('enter_mode', 20)->nullable(); $t->jsonb('actions'); $t->timestampsTz();
            $t->unique(['home_id', 'enter_mode']);
        });
        $this->schema()->create('scene_runs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('home_id')->index(); $t->unsignedBigInteger('scene_id')->index();
            $t->string('scene_name'); $t->string('request_key', 64); $t->string('trigger_type', 30);
            $t->jsonb('trigger_context'); $t->jsonb('locations')->default('[]'); $t->jsonb('action_results')->default('[]');
            $t->string('status', 30)->default('running'); $t->boolean('submission_finished')->default(false);
            $t->timestampTz('started_at'); $t->timestampTz('finished_at')->nullable();
            $t->unique(['home_id','request_key']);
        });
        });
    }
    public function down(): void { $this->schema()->dropIfExists('scene_runs'); $this->schema()->dropIfExists('scenes'); }
};
