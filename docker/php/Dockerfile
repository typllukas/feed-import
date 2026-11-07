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
