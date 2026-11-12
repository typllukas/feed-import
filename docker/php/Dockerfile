FROM php:8.4-fpm-trixie AS base

# pcntl lets queue:work finish the current job on SIGTERM
# librdkafka1 listed, or the autoremove takes it with librdkafka-dev
RUN apt-get update && apt-get install -y --no-install-recommends librdkafka1 librdkafka-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl \
    && pecl install rdkafka-6.0.5 \
    && docker-php-ext-enable rdkafka \
    && apt-get purge -y --auto-remove librdkafka-dev \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

# passwd entry and HOME for the host UID compose runs as; a macOS GID 20 is dialout in Debian
ARG UID=1000
ARG GID=1000
RUN groupadd --non-unique --gid "$GID" app \
    && useradd --non-unique --uid "$UID" --gid "$GID" --create-home app

# above max:10240 in StoreFeedImportRequest, PHP's 2M and 8M refuse the feed before the rule
RUN printf 'upload_max_filesize = 11M\npost_max_size = 11M\n' > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app

FROM base AS tools
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# compose mounts the code
FROM tools AS dev

FROM node:26-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources/css resources/css
COPY resources/js resources/js
RUN npm run build

FROM tools AS production-build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
COPY --from=assets /app/public/build public/build
# storage/ is in .dockerignore; config:cache reads the environment, so it runs at container start
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan route:cache && php artisan event:cache && php artisan view:cache

# php-fpm and both queue workers run this image
FROM base AS production
# expose_php is On even in php.ini-production
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps = 0\nexpose_php = Off\n' > /usr/local/etc/php/conf.d/production.ini
ENV LOG_CHANNEL=stderr
COPY docker/php/production-entrypoint.sh /usr/local/bin/production-entrypoint
COPY --from=production-build /app /app
# group 0: OpenShift runs the image as a random UID in that group
RUN chown -R app:0 storage bootstrap/cache && chmod -R g+w storage bootstrap/cache
USER app
ENTRYPOINT ["production-entrypoint"]
CMD ["php-fpm"]

# default.conf passes PHP to php:9000, the fpm service must be named php
FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production-build /app/public /app/public
