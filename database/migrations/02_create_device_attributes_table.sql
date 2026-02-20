CREATE TABLE device_attributes (
    id SERIAL PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE, -- 级联删除，主设备删除时，其属性也一并删除
    attribute_key VARCHAR(100) NOT NULL,            -- 属性名 (例如 'model', 'mac_address', 'ip_address')
    attribute_value TEXT NOT NULL,                  -- 属性值 (例如 'DHT22', 'A8:B1:E9:1A:2B:3C', '192.168.1.101')
    
    UNIQUE (device_id, attribute_key)               -- 确保同一个设备不能有重复的属性名
);

COMMENT ON TABLE device_attributes IS '存储设备的静态属性，如型号、MAC地址等，提供极高扩展性';

-- 添加索引
CREATE INDEX idx_device_attributes_device_id ON device_attributes(device_id);
