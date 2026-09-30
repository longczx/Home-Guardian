<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('auth_version')->default(0);
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
