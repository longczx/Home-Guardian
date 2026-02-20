CREATE TABLE devices (
    id SERIAL PRIMARY KEY,
    device_uid VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    location VARCHAR(100),
    firmware_version VARCHAR(30),
    mqtt_username VARCHAR(64) UNIQUE,
    mqtt_password_hash VARCHAR(255),
    last_seen TIMESTAMPTZ,
    is_online BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN devices.device_uid IS '用于MQTT主题和API调用的设备唯一ID';
COMMENT ON COLUMN devices.mqtt_username IS 'MQTT 连接用户名，默认可与 device_uid 相同';
COMMENT ON COLUMN devices.mqtt_password_hash IS 'MQTT 连接密码的 bcrypt 哈希';
COMMENT ON COLUMN devices.last_seen IS '由设备心跳或MQTT LWT(最后遗嘱)消息更新';

CREATE INDEX idx_devices_type ON devices(type);
CREATE INDEX idx_devices_location ON devices(location);
CREATE INDEX idx_devices_mqtt_username ON devices(mqtt_username);

-- updated_at 自动更新触发器
CREATE TRIGGER trg_devices_updated_at
    BEFORE UPDATE ON devices
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
