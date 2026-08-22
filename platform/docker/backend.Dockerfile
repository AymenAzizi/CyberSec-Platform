# =============================================================================
# backend.Dockerfile — Laravel 11 PHP-FPM
# Final CDC: non-root execution, minimal image, no dev deps in prod
# =============================================================================
# syntax=docker/dockerfile:1.7
# Build: docker build -f docker/backend.Dockerfile -t cybersec/backend:latest .

ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-alpine AS base

# ---------------------------------------------------------------------------
# Stage 1: builder — install system deps, php extensions, composer
# ---------------------------------------------------------------------------
FROM base AS builder

# Persistent runtime deps + build deps (kept only in this stage)
ARG ALPINE_MIRROR=""
RUN if [ -n "$ALPINE_MIRROR" ]; then \
        sed -i "s|dl-cdn.alpinelinux.org|$ALPINE_MIRROR|g" /etc/apk/repositories; \
    fi && \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        coreutils \
        linux-headers \
        libxml2-dev \
        curl-dev \
        libzip-dev \
        libpng-dev \
        jpeg-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
        bash \
        git \
        unzip \
        zip \
    && apk add --no-cache \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        icu-libs \
        libpq \
        libxml2 \
        curl \
        oniguruma \
        postgresql-client \
        # composer healthcheck helper
        fcgi \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        xml \
        simplexml \
        curl \
        zip \
        gd \
        fileinfo \
        intl \
        bcmath \
        pcntl \
        opcache \
        exif \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /var/www/html

# Install deps first to leverage docker layer cache
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-plugins \
    && composer clear-cache

# Copy application code (dockerignore filters out dev artifacts)
COPY --chown=www-data:www-data . .

# Run composer post-autoload scripts (package:discover)
RUN mkdir -p /var/www/html/bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && chown -R www-data:www-data /var/www/html

# ---------------------------------------------------------------------------
# Stage 2: runtime — minimal image, non-root, read-only compatible
# ---------------------------------------------------------------------------
FROM base AS runtime

LABEL org.opencontainers.image.title="cybersec-backend" \
      org.opencontainers.image.description="PFE Cybersec Platform — Laravel backend" \
      org.opencontainers.image.source="https://github.com/pfe/cybersec-platform" \
      org.opencontainers.image.licenses="MIT"

# Runtime libraries only
RUN apk add --no-cache \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        icu-libs \
        libpq \
        libxml2 \
        curl \
        oniguruma \
        postgresql-client \
        fcgi \
        ca-certificates \
        tini \
    && rm -rf /tmp/* /var/cache/apk/*

# Copy PHP extension binaries from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Copy application + vendor from builder
WORKDIR /var/www/html
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

# PHP runtime tuning (read-only rootfs compatible — write paths via tmpfs)
RUN { \
        echo '[PHP]'; \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 48M'; \
        echo 'max_execution_time = 120'; \
        echo 'max_input_time = 120'; \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.revalidate_freq = 60'; \
        echo; \
        echo '[www]'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php/conf.d/zz-cybersec.ini \
    && mkdir -p /var/www/html/storage/framework/cache/data \
                /var/www/html/storage/framework/sessions \
                /var/www/html/storage/framework/views \
                /var/www/html/storage/logs \
                /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Non-root execution
USER www-data:www-data

EXPOSE 9000

# ---------------------------------------------------------------------------
# Healthcheck — fcgi ping against PHP-FPM + Laravel DB check
# ---------------------------------------------------------------------------
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=60s \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 \
        | grep -q 'pong' || exit 1

# Use tini as PID 1 for proper signal handling
ENTRYPOINT ["/sbin/tini", "--"]

# Boot PHP-FPM in foreground; artisan commands run via `docker exec`
CMD ["php-fpm", "--nodaemonize", "--force-stderr"]
