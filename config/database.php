<?php
/**
 * Home Guardian - 数据库配置
 *
 * 基于 Illuminate/Database (Eloquent ORM) 的 PostgreSQL 连接配置。
 * 所有敏感参数通过 .env 环境变量注入，绝不硬编码。
 *
 * @see https://www.workerman.net/doc/webman/db/tutorial.html
 */

return [
    // 默认数据库连接
    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => getenv('DB_HOST') ?: 'postgres',
            'port'     => getenv('POSTGRES_PORT') ?: 5432,
            'database' => getenv('POSTGRES_DB') ?: 'home_guardian_db',
            'username' => getenv('POSTGRES_USER') ?: 'guardian_user',
            'password' => getenv('POSTGRES_PASSWORD') ?: '',
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
            // 时区与 PHP 保持一致（Asia/Shanghai），TimescaleDB 需要 TIMESTAMPTZ
            'timezone' => '+08:00',
        ],
    ],
];
