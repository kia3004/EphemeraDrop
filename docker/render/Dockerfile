FROM node:22-alpine AS frontend

WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext-envsubst \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        curl-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && mkdir -p /run/nginx /var/log/supervisor /var/lib/nginx/tmp/client_body \
    && chown -R nginx:nginx /var/lib/nginx

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database/migrations ./database/migrations
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./
COPY --from=frontend /src/public/build ./public/build

RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/render/nginx.conf.template /etc/nginx/http.d/default.conf.template
COPY docker/render/supervisord.conf /etc/supervisord.conf
COPY docker/render/php.ini /usr/local/etc/php/conf.d/99-ephemeradrop.ini
COPY docker/render/entrypoint.sh /usr/local/bin/ephemeradrop-entrypoint

RUN chmod 0755 /usr/local/bin/ephemeradrop-entrypoint

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/ephemeradrop-entrypoint"]

FROM runtime AS testing

COPY tests ./tests
COPY phpunit.xml ./
RUN touch .env
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader

FROM runtime AS production
