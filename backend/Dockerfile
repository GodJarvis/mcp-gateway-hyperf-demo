FROM hub.wozhangwan.com/zwwl/php8512-swoole623-hyperf:v7

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .
RUN mkdir -p data runtime && chmod +x bin/hyperf.php

EXPOSE 9501

CMD ["php", "bin/hyperf.php", "start"]
