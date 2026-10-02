<?php

use Eloquent\Migrations\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('command_logs', function (Blueprint $t) {
            $t->jsonb('reply')->nullable();
        });
        $this->schema()->create('automation_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('home_id')->index();
            $t->unsignedBigInteger('automation_id')->index();
            $t->string('automation_name');
            $t->string('trigger_type', 30);
            $t->jsonb('trigger_context');
            $t->jsonb('locations')->default('[]');
            $t->jsonb('action_results')->default('[]');
            $t->string('status', 30)->default('running');
            $t->boolean('submission_finished')->default(false);
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->index(['home_id', 'started_at']);
        });
    }
    public function down(): void
    {
        $this->schema()->dropIfExists('automation_runs');
        $this->schema()->table('command_logs', fn (Blueprint $t) => $t->dropColumn('reply'));
    }
};
