# syntax=docker/dockerfile:1

FROM php:8.4-cli-alpine

# ext-curl and ext-simplexml (both required by composer.json) need their -dev
# headers only at build time to compile against; libcurl/libxml2's runtime
# shared libraries are re-added explicitly afterward so the compiled
# extensions still load once the -dev packages (and their virtual group) are
# gone -- apk doesn't reliably keep transitive runtime deps around otherwise.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev libxml2-dev \
    && docker-php-ext-install curl simplexml \
    && apk del .build-deps \
    && apk add --no-cache curl libxml2

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Installed from just the lock files first so this (slow) layer only re-runs
# when composer.json/composer.lock actually change, not on every source edit.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .

# var/cache/ is created here (not left to FileCache's own mkdir) so it's
# owned by www-data before the process ever runs as that user -- see
# App\Cache\FileCache, which otherwise creates it lazily on first write.
RUN mkdir -p var/cache && chown -R www-data:www-data var
USER www-data

# Informational default; the actual bound port is controlled by $PORT below
# (see config/app.php) and whatever -p mapping you run the container with.
EXPOSE 8000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8000} -t public"]
