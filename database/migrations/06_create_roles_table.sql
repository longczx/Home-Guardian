CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    permissions JSONB
);

COMMENT ON COLUMN roles.permissions IS '权限定义，格式: {"资源": ["操作1", "操作2"]}，admin 角色使用 {"admin": true} 表示全部权限';

-- 初始化默认角色
INSERT INTO roles (name, description, permissions) VALUES
('admin', '超级管理员', '{"admin": true}'),
('member', '家庭成员', '{"devices": ["view", "control"], "dashboards": ["view", "create", "edit"], "alerts": ["view", "create", "edit"], "commands": ["send"], "users": ["view"]}'),
('guest', '访客', '{"devices": ["view"], "dashboards": ["view"], "alerts": ["view"]}');
