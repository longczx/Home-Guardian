-- 初始化管理员用户
-- 默认密码: admin123 (PHP bcrypt hash, cost=10)
-- 首次登录后请立即修改密码！

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin') THEN
        INSERT INTO users (username, password_hash, email, full_name, is_active)
        VALUES (
            'admin',
            -- php -r "echo password_hash('admin123', PASSWORD_BCRYPT);"
            '$2y$10$kuFBsSWoqNG/M2MEvhDQ0uKBnEJfDJ0P3.5LVvnaKyZLj8NYvae.a',
            'admin@home-guardian.local',
            '系统管理员',
            TRUE
        );

        INSERT INTO user_roles (user_id, role_id)
        SELECT u.id, r.id
        FROM users u, roles r
        WHERE u.username = 'admin' AND r.name = 'admin';
    END IF;
END $$;
