<?php
/**
 * PHPUnit 测试引导文件
 *
 * 初始化 Composer 自动加载、SQLite 内存数据库、全局辅助函数和环境变量，
 * 使单元测试无需启动 Webman Worker 即可运行。
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/Request.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// -------------------------------------------------------
// 1. 补充 now() 全局函数（与 app/functions.php 保持一致）
// -------------------------------------------------------
if (!function_exists('now')) {
    function now(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now();
    }
}

// -------------------------------------------------------
if (getenv('TEST_REDIS_HOST')) putenv('REDIS_HOST=' . getenv('TEST_REDIS_HOST'));

// 2. 手动设置 Webman Config（仅加载 log，避免 route/database 副作用）
// -------------------------------------------------------
if (class_exists(\Webman\Config::class)) {
    \Webman\Config::clear();
    // 跳过 route（需要 Router）、database（会覆盖 SQLite）、redis（需要连接）
    \Webman\Config::load(config_path(), getenv('TEST_REDIS_HOST') ? ['route', 'database'] : ['route', 'database', 'redis']);
}

// -------------------------------------------------------
// 3. SQLite 内存数据库（覆盖 Webman 默认的 PgSQL 连接）
// -------------------------------------------------------
$capsule = new Capsule;
$GLOBALS['test_capsule'] = $capsule;
$capsule->addConnection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
    'prefix'   => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// devices 表
$capsule->schema()->create('devices', function ($table) {
    $table->id();
    $table->unsignedBigInteger('home_id')->default(1);
    $table->string('device_uid')->unique();
    $table->string('name');
    $table->string('type')->default('sensor');
    $table->string('location')->nullable();
    $table->string('firmware_version')->nullable();
    $table->string('mqtt_username')->default('');
    $table->string('mqtt_password_hash')->nullable();
    $table->string('gateway_uid')->nullable();
    $table->text('metric_fields')->nullable();
    $table->text('capability')->nullable();
    $table->boolean('is_online')->default(false);
    $table->timestamp('last_seen')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// provision_codes 表（设备配网）
$capsule->schema()->create('provision_codes', function ($table) {
    $table->id();
    $table->unsignedBigInteger('home_id')->default(1);
    $table->string('code')->unique();
    $table->unsignedBigInteger('user_id');
    $table->string('location')->nullable();
    $table->string('status')->default('pending');
    $table->string('device_uid')->nullable();
    $table->timestamp('expires_at');
    $table->timestamp('used_at')->nullable();
    $table->timestamps();
});

// notification_channels 表
$capsule->schema()->create('notification_channels', function ($table) {
    $table->id();
    $table->unsignedBigInteger('home_id')->default(1);
    $table->string('name');
    $table->string('type');
    $table->json('config')->nullable();
    $table->boolean('is_enabled')->default(true);
    $table->unsignedBigInteger('created_by')->nullable();
    $table->timestamps();
});

// -------------------------------------------------------
// 4. 环境变量
// -------------------------------------------------------
putenv('JWT_SECRET=unit_test_jwt_secret_key_32chars!');
putenv('JWT_ACCESS_TTL=7200');
putenv('MQTT_SUPER_USERNAME=hg_test_super');
putenv('MQTT_SUPER_PASSWORD=test_super_pass_123');

// Minimal auth schema for durable session revocation regressions.
$capsule->schema()->create('users', function ($table) {
    $table->id(); $table->string('username')->unique(); $table->string('password_hash');
    $table->string('email')->nullable(); $table->string('full_name')->nullable();
    $table->boolean('is_active')->default(true); $table->unsignedBigInteger('auth_version')->default(0); $table->timestamps();
});
$capsule->schema()->create('roles', function ($table) {
    $table->id(); $table->string('name'); $table->string('description')->nullable(); $table->json('permissions')->nullable(); $table->timestamps();
});
$capsule->schema()->create('user_roles', function ($table) {
    $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('role_id');
});
$capsule->schema()->create('user_allowed_locations', function ($table) {
    $table->id(); $table->unsignedBigInteger('user_id'); $table->string('location');
});
$capsule->schema()->create('home_users', function ($table) {
    $table->id(); $table->unsignedBigInteger('home_id'); $table->unsignedBigInteger('user_id');
    $table->string('role'); $table->timestamps(); $table->unique(['home_id', 'user_id']);
});
$capsule->schema()->create('refresh_tokens', function ($table) {
    $table->id(); $table->unsignedBigInteger('user_id'); $table->string('token_hash')->unique();
    $table->string('device_info')->default(''); $table->timestamp('expires_at'); $table->timestamp('created_at')->nullable();
});
$capsule->schema()->create('command_logs', function ($table) {
    $table->id(); $table->string('request_id')->unique(); $table->unsignedBigInteger('device_id');
    $table->string('topic'); $table->json('payload'); $table->string('status');
    $table->timestamp('sent_at'); $table->timestamp('replied_at')->nullable();
});
$capsule->schema()->create('automations', function ($table) {
    $table->id(); $table->unsignedBigInteger('home_id')->default(1); $table->string('name');
    $table->text('description')->nullable(); $table->string('trigger_type'); $table->json('trigger_config');
    $table->json('actions'); $table->boolean('is_enabled')->default(true); $table->timestamp('last_triggered_at')->nullable();
    $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
});

$capsule->schema()->create('homes', function ($table) {
    $table->id(); $table->string('name'); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
});
$capsule->schema()->create('notification_deliveries', function ($table) {
    $table->id(); $table->unsignedBigInteger('home_id'); $table->string('delivery_key', 64)->unique();
    $table->unsignedBigInteger('channel_id'); $table->string('title'); $table->text('content');
    $table->json('extra')->nullable(); $table->string('status')->default('pending');
    $table->unsignedInteger('attempts')->default(0); $table->text('last_error')->nullable();
    $table->timestamp('next_attempt_at')->nullable(); $table->timestamp('sent_at')->nullable(); $table->timestamps();
});
