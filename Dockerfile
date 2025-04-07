# 使用官方 PHP 镜像
FROM php:8.2-cli

# 将 stv.php 复制到容器
COPY smt2.php /app/smt2.php
COPY smart.txt /app/smart.txt

# 设置工作目录
WORKDIR /app

# 安装依赖（可选，如需要 curl 等）
RUN apt-get update && apt-get install -y libzip-dev

# 暴露端口（Koyeb 默认使用 $PORT 环境变量）
ENV PORT 8080

# 启动 PHP 内置服务器
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT smt2.php"]