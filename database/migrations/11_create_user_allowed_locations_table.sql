-- 用户位置作用域表
-- 限定用户可访问的设备位置范围，为空表示不限制（可访问所有位置）
CREATE TABLE user_allowed_locations (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    location VARCHAR(100) NOT NULL,
    PRIMARY KEY (user_id, location)
);

COMMENT ON TABLE user_allowed_locations IS '限定用户可访问的设备位置，关联 devices.location 字段。无记录表示不限制。';

CREATE INDEX idx_user_allowed_locations_user_id ON user_allowed_locations(user_id);
