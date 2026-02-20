CREATE TABLE alert_logs (
    id BIGSERIAL PRIMARY KEY,
    rule_id INT NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    triggered_value JSONB NOT NULL,
    status VARCHAR(30) DEFAULT 'triggered',
    acknowledged_by INT REFERENCES users(id),
    acknowledged_at TIMESTAMPTZ
);

COMMENT ON COLUMN alert_logs.status IS '告警状态: triggered, acknowledged, resolved';

CREATE INDEX idx_alert_logs_rule_id ON alert_logs(rule_id);
CREATE INDEX idx_alert_logs_device_id ON alert_logs(device_id);
CREATE INDEX idx_alert_logs_triggered_at ON alert_logs(triggered_at DESC);
CREATE INDEX idx_alert_logs_status ON alert_logs(status);
