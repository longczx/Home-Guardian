CREATE TABLE command_logs (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) UNIQUE,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    topic VARCHAR(255) NOT NULL,
    payload JSONB,
    status VARCHAR(30) DEFAULT 'sent',
    sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    replied_at TIMESTAMPTZ
);

COMMENT ON COLUMN command_logs.request_id IS '由Webman生成，并由设备在响应时原样返回';
COMMENT ON COLUMN command_logs.status IS '用于追踪指令的完整生命周期: sent, delivered, replied_ok, replied_error, timeout';

CREATE INDEX idx_command_logs_device_id ON command_logs(device_id);
CREATE INDEX idx_command_logs_status ON command_logs(status);
CREATE INDEX idx_command_logs_sent_at ON command_logs(sent_at DESC);
