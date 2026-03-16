# ─── Base stage ───────────────────────────────────────────
FROM php:8.3-fpm-alpine AS base

# System dependencies
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    linux-headers \
    $PHPIZE_DEPS

# PHP extensions
RUN docker-php-ext-install \
    intl \
    opcache \
    pdo_mysql \
    zip \
    bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

# PHP-FPM healthcheck script
RUN echo '#!/bin/sh' > /usr/local/bin/php-fpm-healthcheck \
    && echo 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1' >> /usr/local/bin/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck \
    && apk add --no-cache fcgi

# PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/oci.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ─── Dev stage ────────────────────────────────────────────
FROM base AS dev

# Xdebug for coverage + debugging
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dev: run FPM as root to avoid permission issues with Windows bind mounts
RUN sed -i 's/^user = www-data/user = root/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf

# Dev PHP config overrides (disable OPcache for bind-mount file change detection)
RUN echo "xdebug.mode=develop,debug,coverage" >> /usr/local/etc/php/conf.d/oci.ini \
    && echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/oci.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/oci.ini \
    && echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/oci.ini

EXPOSE 9000
CMD ["php-fpm", "-R"]

# ─── Prod stage ───────────────────────────────────────────
FROM base AS prod

# Production PHP config (overrides dev php.ini from base)
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/oci.ini

# Copy application code
COPY . /var/www/html

# Install production dependencies only
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && composer clear-cache \
    && rm /usr/bin/composer

# Ensure correct permissions for writable directories
RUN mkdir -p /var/www/html/var /var/www/html/public/sites_data \
    && chown -R www-data:www-data /var/www/html/var /var/www/html/public/sites_data

# Entrypoint fixes volume permissions at startup
COPY docker/php/docker-entrypoint-prod.sh /usr/local/bin/docker-entrypoint-prod.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-prod.sh

EXPOSE 9000
ENTRYPOINT ["docker-entrypoint-prod.sh"]
CMD []
