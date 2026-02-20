-- ============================================================
-- TimescaleDB 数据保留策略
-- 依赖 03_create_telemetry_logs_table.sql 中创建的超表
-- ============================================================

-- 1. 启用压缩
--    按 device_id 和 metric_key 分段压缩，保留 ts 的排序
ALTER TABLE telemetry_logs SET (
    timescaledb.compress,
    timescaledb.compress_segmentby = 'device_id, metric_key',
    timescaledb.compress_orderby = 'ts DESC'
);

-- 2. 自动压缩策略：7 天前的数据自动压缩
SELECT add_compression_policy('telemetry_logs', INTERVAL '7 days');

-- 3. 自动保留策略：180 天前的数据自动删除
SELECT add_retention_policy('telemetry_logs', INTERVAL '180 days');

-- ============================================================
-- 连续聚合：按小时预计算统计数据
-- 用于 Dashboard 历史图表，避免查询百万级原始数据
-- ============================================================

-- 4. 创建按小时聚合的物化视图
CREATE MATERIALIZED VIEW telemetry_hourly
WITH (timescaledb.continuous) AS
SELECT
    time_bucket('1 hour', ts) AS bucket,
    device_id,
    metric_key,
    avg((value ->> 0)::NUMERIC)   AS avg_value,
    min((value ->> 0)::NUMERIC)   AS min_value,
    max((value ->> 0)::NUMERIC)   AS max_value,
    count(*)                       AS sample_count
FROM telemetry_logs
WHERE jsonb_typeof(value) = 'number'
   OR (jsonb_typeof(value) = 'array' AND jsonb_typeof(value -> 0) = 'number')
GROUP BY bucket, device_id, metric_key
WITH NO DATA;

-- 5. 自动刷新策略：每小时刷新，覆盖最近 3 小时的数据（允许迟到数据）
SELECT add_continuous_aggregate_policy('telemetry_hourly',
    start_offset    => INTERVAL '3 hours',
    end_offset      => INTERVAL '1 hour',
    schedule_interval => INTERVAL '1 hour'
);

-- 6. 聚合数据也启用压缩（30 天后压缩）
ALTER MATERIALIZED VIEW telemetry_hourly SET (
    timescaledb.compress
);
SELECT add_compression_policy('telemetry_hourly', INTERVAL '30 days');
