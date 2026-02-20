FROM php:8.3-fpm-alpine

RUN set -eux; \
    apk add --no-cache \
        bash \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libxml2-dev \
        freetype-dev \
        libzip-dev \
        oniguruma-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        xml \
        zip

COPY docker/php-fpm/php.ini /usr/local/etc/php/conf.d/zz-victoriapark.ini
COPY docker/php-fpm/www-health.conf /usr/local/etc/php-fpm.d/zz-health.conf

WORKDIR /var/www/html
