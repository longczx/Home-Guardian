<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('provision_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique()->comment('配对码（一次性、短时有效）');
            $table->unsignedBigInteger('user_id')->comment('生成该码的用户');
            $table->string('location', 100)->nullable()->comment('预设设备位置');
            $table->string('status', 20)->default('pending')->comment('pending / registered / expired');
            $table->string('device_uid', 64)->nullable()->comment('注册成功后回填的网关 device_uid');
            $table->timestampTz('expires_at')->comment('过期时间');
            $table->timestampTz('used_at')->nullable()->comment('被设备使用的时间');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('provision_codes');
    }
};
