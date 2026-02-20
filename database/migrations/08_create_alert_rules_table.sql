CREATE TABLE alert_rules (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    telemetry_key VARCHAR(50) NOT NULL,
    condition VARCHAR(20) NOT NULL,
    threshold_value JSONB NOT NULL,
    trigger_duration_sec INT DEFAULT 0,
    notification_channel_ids JSONB NOT NULL DEFAULT '[]',
    is_enabled BOOLEAN DEFAULT TRUE,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN alert_rules.condition IS '比较条件: GREATER_THAN, LESS_THAN, EQUALS, NOT_EQUALS';
COMMENT ON COLUMN alert_rules.trigger_duration_sec IS '条件需要持续满足多少秒才触发告警，用于防抖';
COMMENT ON COLUMN alert_rules.notification_channel_ids IS '通知渠道ID数组，引用 notification_channels.id，如 [1, 3]';
