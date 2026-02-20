FROM php:8.2-cli

# 安装系统依赖和 PHP 扩展
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libssl-dev \
    libzip-dev \
    zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip sockets bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 安装 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 设置工作目录
WORKDIR /app

# 先拷贝依赖描述文件，利用 Docker 构建缓存
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

# 再拷贝项目代码
COPY . .

# 执行 composer 后置脚本（如有）
RUN composer dump-autoload --optimize

# 暴露 Webman 默认端口
EXPOSE 8787

# 启动 Webman
CMD ["php", "start.php", "start"]
