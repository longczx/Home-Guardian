CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id INT,
    detail JSONB,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN audit_logs.action IS '操作类型: login, logout, create, update, delete, control, command_send';
COMMENT ON COLUMN audit_logs.resource_type IS '资源类型: user, device, alert_rule, automation, dashboard, notification_channel';
COMMENT ON COLUMN audit_logs.resource_id IS '被操作资源的 ID，登录/注销等操作可为 NULL';
COMMENT ON COLUMN audit_logs.detail IS '操作详情，如修改前后的差异、指令内容等';
COMMENT ON COLUMN audit_logs.ip_address IS '支持 IPv6 地址，最长 45 字符';
COMMENT ON COLUMN audit_logs.user_id IS '操作人，用户被删除后保留日志但 user_id 置 NULL';

-- detail JSONB 示例:
-- 登录:       {"method": "password"}
-- 控制设备:   {"device_uid": "esp32-livingroom-01", "command": {"action": "turn_off"}}
-- 修改规则:   {"before": {"threshold_value": 30}, "after": {"threshold_value": 35}}
-- 删除设备:   {"device_uid": "esp32-bedroom-01", "name": "卧室传感器"}

CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);
CREATE INDEX idx_audit_logs_resource ON audit_logs(resource_type, resource_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);
