-- 确保 TimescaleDB 扩展已安装
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- 创建遥测数据表（不使用外键，因为超表不支持）
CREATE TABLE telemetry_logs (
    ts TIMESTAMPTZ NOT NULL,
    device_id INT NOT NULL,
    metric_key VARCHAR(50) NOT NULL,
    value JSONB NOT NULL
);

COMMENT ON TABLE telemetry_logs IS 'device_id 引用 devices(id)，因超表限制不使用外键约束，由应用层保证一致性';
COMMENT ON COLUMN telemetry_logs.value IS '使用JSONB以支持数字、字符串、布尔和复杂的JSON对象';

-- 将表转换为 TimescaleDB 超表
SELECT create_hypertable('telemetry_logs', 'ts', chunk_time_interval => INTERVAL '7 days');

-- 为超表创建高效索引
CREATE INDEX idx_telemetry_device_key_ts ON telemetry_logs (device_id, metric_key, ts DESC);
CREATE INDEX idx_telemetry_key_ts ON telemetry_logs (metric_key, ts DESC);
CREATE INDEX idx_telemetry_value_gin ON telemetry_logs USING GIN (value);
