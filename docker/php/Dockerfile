FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql intl opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Ảnh gốc không nạp php.ini nào: display_errors bật, nên một fatal xảy ra trước
# khi Laravel bootstrap sẽ in đường dẫn thật ra cho client.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -f "$PHP_INI_DIR/php.ini-development" \
    # php.ini-production để expose_php mặc định (On) — tắt tay để header
    # X-Powered-By không quảng cáo phiên bản PHP chính xác ra ngoài.
    && printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/99-hanora.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
