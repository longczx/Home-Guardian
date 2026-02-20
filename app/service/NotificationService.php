<?php
/**
 * Home Guardian - 通知服务
 *
 * 根据通知渠道配置，向不同的渠道发送告警通知。
 * 支持的渠道类型：email / webhook / telegram / wechat_work / dingtalk
 *
 * 此服务被告警引擎和自动化系统调用，通知发送失败不应影响主业务流程，
 * 所有发送异常都会被捕获并记录日志。
 */

namespace app\service;

use app\model\NotificationChannel;
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
    public static function send(array $channelIds, string $title, string $content, array $extra = []): void
    {
        if (empty($channelIds)) {
            return;
        }

        // 批量查询所有目标渠道（仅启用的）
        $channels = NotificationChannel::enabled()
            ->whereIn('id', $channelIds)
            ->get();

        foreach ($channels as $channel) {
            try {
                match ($channel->type) {
                    NotificationChannel::TYPE_EMAIL       => self::sendEmail($channel->config, $title, $content),
                    NotificationChannel::TYPE_WEBHOOK     => self::sendWebhook($channel->config, $title, $content, $extra),
                    NotificationChannel::TYPE_TELEGRAM    => self::sendTelegram($channel->config, $title, $content),
                    NotificationChannel::TYPE_WECHAT_WORK => self::sendWechatWork($channel->config, $title, $content),
                    NotificationChannel::TYPE_DINGTALK    => self::sendDingtalk($channel->config, $title, $content),
                    default => Log::warning("未知的通知渠道类型: {$channel->type}"),
                };
            } catch (\Throwable $e) {
                // 单个渠道发送失败不影响其他渠道
                Log::error("通知发送失败 [渠道:{$channel->name}({$channel->type})]: {$e->getMessage()}");
            }
        }
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
        $to = $config['to'] ?? [];
        if (empty($to)) {
            return;
        }

        $smtpHost = $config['smtp_host'] ?? '';
        $smtpPort = $config['smtp_port'] ?? 587;
        $smtpUser = $config['smtp_user'] ?? '';
        $smtpPass = $config['smtp_pass_encrypted'] ?? '';

        // 使用 PHP 内置 mail() 作为基础实现
        // 生产环境建议替换为 PHPMailer 或 SwiftMailer 以支持 SMTP 认证
        $headers = [
            'From'         => $smtpUser,
            'Content-Type' => 'text/html; charset=UTF-8',
            'MIME-Version' => '1.0',
        ];

        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= "{$key}: {$value}\r\n";
        }

        foreach ((array)$to as $recipient) {
            mail($recipient, $title, $content, $headerStr);
        }

        Log::info("邮件通知已发送: {$title} → " . implode(', ', (array)$to));
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
            return;
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
            return;
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
            return;
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
            return;
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
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP 请求失败: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP 请求返回 {$httpCode}: {$response}");
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
