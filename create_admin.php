#!/usr/bin/env php
<?php
/**
 * 创建管理员用户
 *
 * 在 Docker 容器内执行:
 *   docker exec -it home-guardian-app php create_admin.php
 *   docker exec -it home-guardian-app php create_admin.php myuser mypass123
 */

require_once __DIR__ . '/vendor/autoload.php';

// 加载 .env
if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// 手动初始化 Eloquent
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => 'pgsql',
    'host'     => getenv('DB_HOST') ?: 'postgres',
    'port'     => getenv('POSTGRES_PORT') ?: 5432,
    'database' => getenv('POSTGRES_DB') ?: 'home_guardian_db',
    'username' => getenv('POSTGRES_USER') ?: 'guardian_user',
    'password' => getenv('POSTGRES_PASSWORD') ?: 'home_guardian_password',
    'charset'  => 'utf8',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// ─── 创建用户 ───

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';

echo "Home Guardian - 创建管理员\n";
echo str_repeat('─', 35) . "\n";

$existing = Capsule::table('users')->where('username', $username)->first();

if ($existing) {
    echo "用户 '{$username}' 已存在，是否重置密码? (y/N): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "已取消\n";
        exit(0);
    }
    Capsule::table('users')
        ->where('username', $username)
        ->update(['password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
    echo "密码已重置\n";
} else {
    $userId = Capsule::table('users')->insertGetId([
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'email'         => "{$username}@home-guardian.local",
        'full_name'     => '系统管理员',
        'is_active'     => true,
        'created_at'    => date('c'),
        'updated_at'    => date('c'),
    ]);

    $adminRole = Capsule::table('roles')->where('name', 'admin')->first();
    if ($adminRole) {
        Capsule::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $adminRole->id,
        ]);
        echo "管理员用户创建成功\n";
    } else {
        echo "用户已创建（未找到 admin 角色，请手动分配）\n";
    }
}

echo "\n";
echo "  用户名: {$username}\n";
echo "  密  码: {$password}\n";
echo "\n";
echo "请尽快登录并修改默认密码！\n";
