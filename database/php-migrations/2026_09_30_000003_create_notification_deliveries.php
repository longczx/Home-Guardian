<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('home_id');
            $table->string('delivery_key', 64)->unique();
            $table->unsignedBigInteger('channel_id');
            $table->string('title');
            $table->text('content');
            $table->json('extra')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['home_id', 'created_at']);
        });
    }
    public function down(): void { $this->schema()->dropIfExists('notification_deliveries'); }
};
