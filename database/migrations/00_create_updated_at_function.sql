-- 创建通用的 updated_at 自动更新触发器函数
-- 所有包含 updated_at 字段的表都可以复用此函数
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
