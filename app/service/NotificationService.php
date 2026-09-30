<?php
/**
 * Home Guardian - 通知服务
 *
 * 根据通知渠道配置，向不同的渠道发送告警通知。
 * 支持的渠道类型：email / webhook / telegram / wechat_work / dingtalk / in_app
 *
 * 此服务被告警引擎和自动化系统调用，通知发送失败不应影响主业务流程，
 * 所有发送异常都会被捕获并记录日志。
 */

namespace app\service;

use app\model\NotificationChannel;
use app\model\UserPushDevice;
use support\Log;

class NotificationService
{
    /**
     * 向指定的通知渠道发送消息
     *
     * @param  array  $channelIds 通知渠道 ID 列表
     * @param  string $title      通知标题
     * @param  string $content    通知正文
     * @param  array  $extra      附加数据（如设备 ID、告警值等）
     */
    public static function send(array $channelIds, string $title, string $content, array $extra = []): array
    {
        if (empty($channelIds)) {
            return [];
        }

        // 批量查询所有目标渠道（仅启用的）
        $query = NotificationChannel::enabled()->whereIn('id', $channelIds);
        if (isset($extra['home_id'])) $query->where('home_id', (int)$extra['home_id']);
        $channels = $query->get();

        $results = array_fill_keys($channelIds, ['status' => 'skipped', 'error' => null]);
        foreach ($channels as $channel) {
            try {
                $channelExtra = array_merge($extra, ['home_id' => $channel->home_id]);
                match ($channel->type) {
                    NotificationChannel::TYPE_EMAIL       => self::sendEmail($channel->config, $title, $content),
                    NotificationChannel::TYPE_WEBHOOK     => self::sendWebhook($channel->config, $title, $content, $channelExtra),
                    NotificationChannel::TYPE_TELEGRAM    => self::sendTelegram($channel->config, $title, $content),
                    NotificationChannel::TYPE_WECHAT_WORK => self::sendWechatWork($channel->config, $title, $content),
                    NotificationChannel::TYPE_DINGTALK    => self::sendDingtalk($channel->config, $title, $content),
                    NotificationChannel::TYPE_IN_APP      => self::sendInApp($title, $content, $channelExtra),
                    NotificationChannel::TYPE_UNIPUSH     => self::sendUniPush($channel, $title, $content, $channelExtra),
                    default => throw new \RuntimeException("未知的通知渠道类型"),
                };
                $results[$channel->id] = ['status' => 'sent', 'error' => null];
            } catch (\Throwable $e) {
                $results[$channel->id] = ['status' => 'failed', 'error' => $e->getMessage()];
                // 单个渠道发送失败不影响其他渠道
                Log::error("通知发送失败 [渠道:{$channel->name}({$channel->type})]: {$e->getMessage()}");
            }
        }
        return $results;
    }

    /**
     * 发送邮件通知
     *
     * @param array  $config  渠道配置（smtp_host, smtp_port, smtp_user, smtp_pass_encrypted, to）
     * @param string $title   邮件主题
     * @param string $content 邮件正文
     */
    private static function sendEmail(array $config, string $title, string $content): void
    {
        $host = $config['smtp_host'] ?? '';
        $to = (array)($config['to'] ?? []);
        $user = $config['smtp_user'] ?? '';
        if (!$host || !$to || !($config['from'] ?? $user)) {
            throw new \RuntimeException('SMTP 主机、发件人和收件人不能为空');
        }
        $port = (int)($config['smtp_port'] ?? 587);
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $host, $port, $port === 465
        );
        if (isset($config['smtp_tls'])) $transport->setAutoTls((bool)$config['smtp_tls']);
        $transport->getStream()->setTimeout(10);
        if ($user !== '') {
            $transport->setUsername($user);
            $transport->setPassword($config['smtp_pass'] ?? $config['smtp_pass_encrypted'] ?? '');
        }
        $email = (new \Symfony\Component\Mime\Email())
            ->from($config['from'] ?? $user)->to(...$to)->subject($title)->text($content);
        (new \Symfony\Component\Mailer\Mailer($transport))->send($email);
        Log::info('SMTP 邮件已提交: ' . $title);
    }

    /**
     * 发送 Webhook 通知
     *
     * @param array  $config  渠道配置（url, method, headers）
     * @param string $title   通知标题
     * @param string $content 通知正文
     * @param array  $extra   附加数据
     */
    private static function sendWebhook(array $config, string $title, string $content, array $extra = []): void
    {
        $url = $config['url'] ?? '';
        if (empty($url)) {
            throw new \RuntimeException('Webhook URL 未配置');
        }

        $method = strtoupper($config['method'] ?? 'POST');
        $customHeaders = $config['headers'] ?? [];

        $body = json_encode([
            'title'   => $title,
            'content' => $content,
            'extra'   => $extra,
            'time'    => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        self::httpRequest($url, $method, $body, array_merge(
            ['Content-Type: application/json'],
            self::formatHeaders($customHeaders)
        ));

        Log::info("Webhook 通知已发送: {$title} → {$url}");
    }

    /**
     * 发送 Telegram Bot 通知
     *
     * @param array  $config  渠道配置（bot_token, chat_id）
     * @param string $title   通知标题
     * @param string $content 通知正文
     */
    private static function sendTelegram(array $config, string $title, string $content): void
    {
        $botToken = $config['bot_token'] ?? '';
        $chatId = $config['chat_id'] ?? '';

        if (empty($botToken) || empty($chatId)) {
            throw new \RuntimeException('Telegram 参数未配置');
        }

        $text = "<b>{$title}</b>\n\n{$content}";
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);

        self::httpRequest($url, 'POST', $body, ['Content-Type: application/json']);

        Log::info("Telegram 通知已发送: {$title} → chat_id:{$chatId}");
    }

    /**
     * 发送企业微信群机器人通知
     *
     * @param array  $config  渠道配置（webhook_url）
     * @param string $title   通知标题
     * @param string $content 通知正文
     */
    private static function sendWechatWork(array $config, string $title, string $content): void
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            throw new \RuntimeException('机器人 Webhook 未配置');
        }

        $body = json_encode([
            'msgtype'  => 'markdown',
            'markdown' => [
                'content' => "### {$title}\n\n{$content}",
            ],
        ], JSON_UNESCAPED_UNICODE);

        self::httpRequest($webhookUrl, 'POST', $body, ['Content-Type: application/json']);

        Log::info("企业微信通知已发送: {$title}");
    }

    /**
     * 发送钉钉群机器人通知
     *
     * @param array  $config  渠道配置（webhook_url, secret）
     * @param string $title   通知标题
     * @param string $content 通知正文
     */
    private static function sendDingtalk(array $config, string $title, string $content): void
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            throw new \RuntimeException('机器人 Webhook 未配置');
        }

        // 如果配置了加签密钥，需要在 URL 中附加签名参数
        if (!empty($config['secret'])) {
            $timestamp = time() * 1000;
            $sign = urlencode(base64_encode(
                hash_hmac('sha256', $timestamp . "\n" . $config['secret'], $config['secret'], true)
            ));
            $webhookUrl .= "&timestamp={$timestamp}&sign={$sign}";
        }

        $body = json_encode([
            'msgtype'  => 'markdown',
            'markdown' => [
                'title' => $title,
                'text'  => "### {$title}\n\n{$content}",
            ],
        ], JSON_UNESCAPED_UNICODE);

        self::httpRequest($webhookUrl, 'POST', $body, ['Content-Type: application/json']);

        Log::info("钉钉通知已发送: {$title}");
    }

    /**
     * 站内通知
     *
     * 通过 WebSocket 推送站内通知消息，前端实时展示。
     * 告警日志已由 AlertService::triggerAlert 写入数据库，
     * 此处额外推送一条 notification 类型的 WS 消息用于前端弹窗提醒。
     */
    private static function sendInApp(string $title, string $content, array $extra = []): void
    {
        $wsPayload = json_encode([
            'type' => 'notification',
            'home_id' => $extra['home_id'] ?? null,
            'device_id' => $extra['device_id'] ?? null,
            'data' => [
                'title'   => $title,
                'content' => $content,
                'extra'   => $extra,
                'time'    => date('Y-m-d H:i:s'),
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            \support\Redis::connection('pubsub')->publish('ws:broadcast', $wsPayload);
        } catch (\Throwable $e) {
            throw $e;
        }

        Log::info("站内通知已推送: {$title}");
    }

    /**
     * uniPush App 推送
     *
     * 向渠道所属家庭的推送设备下发通知，按各设备的 push_enabled + min_severity 过滤。
     * cid 来自 user_push_devices（App 端登录后上报）。
     *
     * @param NotificationChannel $channel unipush 渠道（config: app_id/app_key/master_secret）
     * @param array               $extra   附加数据，其中 severity 用于级别过滤、alert 相关字段透传
     */
    private static function sendUniPush(NotificationChannel $channel, string $title, string $content, array $extra = []): void
    {
        $config = $channel->config ?? [];
        if (empty($config['app_id']) || empty($config['app_key']) || empty($config['master_secret'])) {
            Log::warning("[uniPush] 渠道 {$channel->name} 未配置 app_id/app_key/master_secret");
            throw new \RuntimeException('uniPush 参数未配置');
        }

        $severity = $extra['severity'] ?? 'warning';

        // 按家庭取推送设备，逐台判断是否接收该级别
        $devices = UserPushDevice::where('home_id', $channel->home_id)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)
                ->whereHas('homeMemberships', fn ($m) => $m->where('home_id', $channel->home_id)))->get();
        $cids = $devices->filter(fn ($d) => $d->acceptsSeverity($severity))->pluck('cid')->all();

        if (empty($cids)) {
            return;
        }

        $payload = [];
        if (isset($extra['alert_id'])) {
            $payload = ['type' => 'alert', 'alert_id' => $extra['alert_id']];
        }

        UniPushService::push($config, $cids, $title, $content, $payload);
        Log::info("uniPush 已推送: {$title} → " . count($cids) . ' 台设备');
    }

    /**
     * 通用 HTTP 请求方法
     *
     * @param string $url     请求 URL
     * @param string $method  HTTP 方法
     * @param string $body    请求体
     * @param array  $headers 请求头数组
     */
    private static function httpRequest(string $url, string $method, string $body, array $headers = []): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,        // 10 秒超时，避免阻塞
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        if ($error || $response === false) throw new \RuntimeException('通知 HTTP 连接失败');
        if ($httpCode < 200 || $httpCode >= 300) throw new \RuntimeException("通知 HTTP 返回 {$httpCode}");
        $result = json_decode($response, true);
        if (is_array($result) && ((isset($result['errcode']) && (int)$result['errcode'] !== 0)
            || isset($result['ok']) && $result['ok'] === false)) {
            throw new \RuntimeException('通知渠道拒绝请求: ' . ($result['errcode'] ?? 'ok=false'));
        }
    }

    /**
     * 格式化自定义请求头
     *
     * 将关联数组格式 {"X-Token": "abc"} 转为 curl 需要的 ["X-Token: abc"] 格式。
     *
     * @param  array $headers 关联数组格式的请求头
     * @return array curl 格式的请求头数组
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }
}
