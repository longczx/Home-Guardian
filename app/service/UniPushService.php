<?php
/**
 * Home Guardian - uniPush（个推 GeTui REST v2）推送
 *
 * uni-app 的 App 端推送走个推通道。链路：
 *   1. auth 拿 token（sign = sha256(appkey + timestamp + mastersecret)），缓存 ~23h
 *   2. push/single/cid 按 cid 逐个下发（家庭规模设备数少，逐条足够且实现简单）
 *
 * 凭证由 unipush 通知渠道的 config 提供：{app_id, app_key, master_secret}。
 * 自托管用户需自行在 DCloud/个推申请并填入（见 docs / README）。
 */

namespace app\service;

use support\Redis;
use support\Log;

class UniPushService
{
    private const BASE = 'https://restapi.getui.com/v2';
    private const TOKEN_KEY = 'unipush:token:';

    /**
     * 向一批 cid 推送通知
     *
     * @param array  $config {app_id, app_key, master_secret}
     * @param array  $cids   目标 clientId 列表
     * @param string $title  通知标题
     * @param string $body   通知正文
     * @param array  $payload 透传数据（点击打开时携带，如 {type:'alert', alert_id}）
     */
    public static function push(array $config, array $cids, string $title, string $body, array $payload = []): void
    {
        $appId = $config['app_id'] ?? '';
        if ($appId === '' || empty($cids)) {
            return;
        }

        $token = self::getToken($config);
        if (!$token) {
            Log::error('[uniPush] 获取 token 失败，跳过推送');
            return;
        }

        foreach ($cids as $cid) {
            try {
                self::pushSingle($appId, $token, $cid, $title, $body, $payload);
            } catch (\Throwable $e) {
                Log::error("[uniPush] 推送失败 cid={$cid}: {$e->getMessage()}");
            }
        }
    }

    private static function pushSingle(string $appId, string $token, string $cid, string $title, string $body, array $payload): void
    {
        $reqId = bin2hex(random_bytes(12));
        $data = [
            'request_id' => $reqId,
            'audience'   => ['cid' => [$cid]],
            'push_message' => [
                'notification' => [
                    'title'      => $title,
                    'body'       => $body,
                    'click_type' => 'startapp',
                ],
            ],
        ];
        if ($payload) {
            // payload 以透传形式带上，App 端 onPushMessage 可读取
            $data['push_message']['notification']['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        self::http("/{$appId}/push/single/cid", json_encode($data, JSON_UNESCAPED_UNICODE), [
            'Content-Type: application/json;charset=utf-8',
            "token: {$token}",
        ]);
    }

    /**
     * 获取并缓存 token（Redis，提前 1h 过期防临界）
     */
    private static function getToken(array $config): ?string
    {
        $appId = $config['app_id'] ?? '';
        $appKey = $config['app_key'] ?? '';
        $masterSecret = $config['master_secret'] ?? '';
        if ($appId === '' || $appKey === '' || $masterSecret === '') {
            return null;
        }

        $cacheKey = self::TOKEN_KEY . $appId;
        try {
            $cached = Redis::connection('default')->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        } catch (\Throwable) { /* 缓存不可用则直接请求 */ }

        $timestamp = (string)(time() * 1000);
        $sign = hash('sha256', $appKey . $timestamp . $masterSecret);
        $resp = self::http("/{$appId}/auth", json_encode([
            'sign'      => $sign,
            'timestamp' => $timestamp,
            'appkey'    => $appKey,
        ]), ['Content-Type: application/json;charset=utf-8']);

        $json = json_decode($resp, true);
        $token = $json['data']['token'] ?? null;
        if ($token) {
            try {
                Redis::connection('default')->setex($cacheKey, 82800, $token); // 23h
            } catch (\Throwable) { /* ignore */ }
        }
        return $token;
    }

    /**
     * 发 HTTP 请求，返回响应体（非 2xx 抛异常）
     */
    private static function http(string $path, string $body, array $headers): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::BASE . $path,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            throw new \RuntimeException("curl 错误: {$err}");
        }
        if ($code >= 400) {
            throw new \RuntimeException("HTTP {$code}: {$resp}");
        }
        return (string)$resp;
    }
}
