CREATE TABLE notification_channels (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    config JSONB NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN notification_channels.type IS '渠道类型: email, webhook, telegram, wechat_work, dingtalk';
COMMENT ON COLUMN notification_channels.config IS '渠道配置，格式因类型而异，参见下方示例';

-- config JSONB 示例：
-- email:        {"smtp_host": "smtp.gmail.com", "smtp_port": 587, "smtp_user": "...", "smtp_pass_encrypted": "...", "to": ["dad@example.com"]}
-- webhook:      {"url": "https://hooks.example.com/xxx", "method": "POST", "headers": {"X-Token": "..."}}
-- telegram:     {"bot_token": "123456:ABC...", "chat_id": "-100123456"}
-- wechat_work:  {"webhook_url": "https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx"}
-- dingtalk:     {"webhook_url": "https://oapi.dingtalk.com/robot/send?access_token=xxx", "secret": "SEC..."}

CREATE INDEX idx_notification_channels_type ON notification_channels(type);

CREATE TRIGGER trg_notification_channels_updated_at
    BEFORE UPDATE ON notification_channels
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
