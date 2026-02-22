<?php
/**
 * Home Guardian - Admin 系统管理控制器
 *
 * 展示各依赖服务的运行状态与版本信息：
 *   - PostgreSQL 连接检测 + 版本查询
 *   - Redis 连接检测 + 版本查询（需要 ext-redis）
 *   - MQTT Broker TCP 端口连通性检测
 *   - PHP 版本及关键扩展列表
 *   - 关键 Composer 依赖版本（读取 composer.lock）
 */

namespace app\controller\admin;

use support\Db;
use support\Request;

class SystemController
{
    public function index(Request $request)
    {
        $services = [];

        // ── PostgreSQL ──────────────────────────────────────────────────────
        try {
            $pdo = Db::connection()->getPdo();
            $raw = $pdo->query('SELECT version()')->fetchColumn();
            preg_match('/PostgreSQL ([\d.]+)/', $raw, $m);
            $cfg = config('database.connections.pgsql');
            $services[] = [
                'name'    => 'PostgreSQL',
                'ok'      => true,
                'version' => $m[1] ?? 'unknown',
                'detail'  => ($cfg['host'] ?? '-') . ':' . ($cfg['port'] ?? 5432),
            ];
        } catch (\Throwable $e) {
            $cfg = config('database.connections.pgsql');
            $services[] = [
                'name'    => 'PostgreSQL',
                'ok'      => false,
                'version' => '-',
                'detail'  => $e->getMessage(),
            ];
        }

        // ── Redis ───────────────────────────────────────────────────────────
        $redisHost = getenv('REDIS_HOST') ?: 'redis';
        $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
        $redisPass = getenv('REDIS_PASSWORD') ?: null;
        try {
            if (!extension_loaded('redis')) {
                throw new \RuntimeException('PHP redis 扩展未加载');
            }
            $r = new \Redis();
            if (!$r->connect($redisHost, $redisPort, 2)) {
                throw new \RuntimeException('连接超时');
            }
            if ($redisPass) {
                $r->auth($redisPass);
            }
            $info       = $r->info('server');
            $redisVer   = $info['redis_version'] ?? '-';
            $r->close();
            $services[] = [
                'name'    => 'Redis',
                'ok'      => true,
                'version' => $redisVer,
                'detail'  => $redisHost . ':' . $redisPort,
            ];
        } catch (\Throwable $e) {
            $services[] = [
                'name'    => 'Redis',
                'ok'      => false,
                'version' => '-',
                'detail'  => $e->getMessage(),
            ];
        }

        // ── MQTT Broker ─────────────────────────────────────────────────────
        $mqttHost = getenv('MQTT_HOST') ?: 'emqx';
        $mqttPort = (int)(getenv('MQTT_PORT') ?: 1883);
        $sock     = @fsockopen($mqttHost, $mqttPort, $errno, $errstr, 2);
        $mqttOk   = $sock !== false;
        if ($sock) {
            fclose($sock);
        }
        $services[] = [
            'name'    => 'MQTT (EMQX)',
            'ok'      => $mqttOk,
            'version' => '-',
            'detail'  => $mqttHost . ':' . $mqttPort . ($mqttOk ? '' : "（$errstr）"),
        ];

        // ── PHP 扩展 ────────────────────────────────────────────────────────
        $checkExts = ['pdo_pgsql', 'redis', 'pcntl', 'posix', 'json', 'mbstring', 'openssl', 'sockets'];
        $loadedExts = array_values(array_filter($checkExts, 'extension_loaded'));

        return view('admin/system/index', [
            'services'   => $services,
            'packages'   => $this->readPackageVersions(),
            'phpVersion' => phpversion(),
            'loadedExts' => $loadedExts,
            'checkExts'  => $checkExts,
            'nav'        => 'system',
            'adminUser'  => $request->adminUser,
        ]);
    }

    private function readPackageVersions(): array
    {
        $lockFile = base_path() . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        $lock = json_decode(file_get_contents($lockFile), true);
        if (empty($lock['packages'])) {
            return [];
        }

        $interested = [
            'workerman/webman-framework' => 'Webman Framework',
            'workerman/workerman'         => 'Workerman',
            'workerman/mqtt'              => 'Workerman MQTT',
            'illuminate/database'         => 'Eloquent ORM',
            'topthink/think-template'     => 'ThinkPHP Template',
            'firebase/php-jwt'            => 'Firebase JWT',
            'monolog/monolog'             => 'Monolog',
        ];

        $result = [];
        foreach ($lock['packages'] as $pkg) {
            if (isset($interested[$pkg['name']])) {
                $result[] = [
                    'package' => $pkg['name'],
                    'label'   => $interested[$pkg['name']],
                    'version' => $pkg['version'],
                ];
            }
        }

        return $result;
    }
}
