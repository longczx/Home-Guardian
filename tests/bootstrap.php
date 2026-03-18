<?php
/**
 * PHPUnit 测试引导文件
 *
 * 初始化 Composer 自动加载、SQLite 内存数据库、全局辅助函数和环境变量，
 * 使单元测试无需启动 Webman Worker 即可运行。
 */

require __DIR__ . '/../vendor/autoload.php';

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
// 2. 手动设置 Webman Config（仅加载 log，避免 route/database 副作用）
// -------------------------------------------------------
if (class_exists(\Webman\Config::class)) {
    \Webman\Config::clear();
    // 跳过 route（需要 Router）、database（会覆盖 SQLite）、redis（需要连接）
    \Webman\Config::load(config_path(), ['route', 'database', 'redis']);
}

// -------------------------------------------------------
// 3. SQLite 内存数据库（覆盖 Webman 默认的 PgSQL 连接）
// -------------------------------------------------------
$capsule = new Capsule;
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
    $table->string('device_uid')->unique();
    $table->string('name');
    $table->string('type')->default('sensor');
    $table->string('location')->nullable();
    $table->string('firmware_version')->nullable();
    $table->string('mqtt_username')->default('');
    $table->string('mqtt_password_hash')->nullable();
    $table->boolean('is_online')->default(false);
    $table->timestamp('last_seen')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// notification_channels 表
$capsule->schema()->create('notification_channels', function ($table) {
    $table->id();
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
