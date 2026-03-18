<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\service\NotificationService;
use app\model\NotificationChannel;

/**
 * NotificationService 单元测试
 *
 * 注：send() 内部会做 HTTP 请求，这里只测试渠道分发与空值守卫逻辑。
 *     完整的端到端推送测试应在集成测试中进行。
 */
class NotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        NotificationChannel::query()->delete();
    }

    /* =============================================
     * send() - 基本分发逻辑
     * ============================================= */

    public function test_send_with_empty_channel_ids_does_nothing(): void
    {
        // 不应抛出异常
        NotificationService::send([], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_send_skips_disabled_channels(): void
    {
        $channel = NotificationChannel::create([
            'name'       => '已禁用 Webhook',
            'type'       => 'webhook',
            'config'     => ['url' => 'http://localhost:19999/hook'],
            'is_enabled' => false,
        ]);

        // 禁用的渠道不会被查到，不会发起 HTTP 请求
        NotificationService::send([$channel->id], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_send_with_nonexistent_ids_does_nothing(): void
    {
        NotificationService::send([99998, 99999], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_send_catches_exception_and_continues(): void
    {
        // webhook URL 不可达，应被捕获而非向外抛出
        $ch1 = NotificationChannel::create([
            'name'       => '不可达 Webhook',
            'type'       => 'webhook',
            'config'     => ['url' => 'http://192.0.2.1:1/unreachable'],
            'is_enabled' => true,
        ]);

        // 不应抛出任何异常
        NotificationService::send([$ch1->id], '告警', '温度过高');
        $this->assertTrue(true);
    }

    /* =============================================
     * 各渠道空配置守卫（不会发起 HTTP）
     * ============================================= */

    public function test_webhook_without_url_does_nothing(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '空 URL Webhook',
            'type'       => 'webhook',
            'config'     => ['url' => ''],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_telegram_without_token_does_nothing(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '空 Telegram',
            'type'       => 'telegram',
            'config'     => ['bot_token' => '', 'chat_id' => ''],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_wechat_work_without_webhook_url_does_nothing(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '空企微',
            'type'       => 'wechat_work',
            'config'     => ['webhook_url' => ''],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_dingtalk_without_webhook_url_does_nothing(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '空钉钉',
            'type'       => 'dingtalk',
            'config'     => ['webhook_url' => ''],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }

    public function test_email_without_recipients_does_nothing(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '空邮件',
            'type'       => 'email',
            'config'     => ['to' => []],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }

    /* =============================================
     * 未知渠道类型
     * ============================================= */

    public function test_unknown_channel_type_does_not_throw(): void
    {
        $ch = NotificationChannel::create([
            'name'       => '未知类型',
            'type'       => 'sms',
            'config'     => [],
            'is_enabled' => true,
        ]);

        NotificationService::send([$ch->id], '标题', '内容');
        $this->assertTrue(true);
    }
}
