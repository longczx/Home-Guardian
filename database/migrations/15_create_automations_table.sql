CREATE TABLE automations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    trigger_type VARCHAR(20) NOT NULL,
    trigger_config JSONB NOT NULL,
    actions JSONB NOT NULL DEFAULT '[]',
    is_enabled BOOLEAN DEFAULT TRUE,
    last_triggered_at TIMESTAMPTZ,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN automations.trigger_type IS '触发类型: telemetry (遥测条件触发), schedule (定时计划触发)';
COMMENT ON COLUMN automations.trigger_config IS '触发条件配置，格式因 trigger_type 而异';
COMMENT ON COLUMN automations.actions IS '执行动作数组: [{"type": "device_command", ...}, {"type": "notify", ...}]';

-- trigger_config 示例:
-- telemetry: {"device_id": 1, "metric_key": "temperature", "condition": "GREATER_THAN", "value": 30, "duration_sec": 60}
-- schedule:  {"cron": "0 22 * * *", "timezone": "Asia/Shanghai"}

-- actions 示例:
-- [
--   {"type": "device_command", "device_id": 2, "payload": {"action": "turn_on", "mode": "cool"}},
--   {"type": "notify", "channel_ids": [1, 3]}
-- ]

CREATE INDEX idx_automations_trigger_type ON automations(trigger_type);
CREATE INDEX idx_automations_is_enabled ON automations(is_enabled);

CREATE TRIGGER trg_automations_updated_at
    BEFORE UPDATE ON automations
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
