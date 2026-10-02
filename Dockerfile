# Efe Otomotiv Adana — Symfony 8.1 + FrankenPHP (PHP 8.4)
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_PROCESS_TIMEOUT=1200

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions \
        pdo_mysql \
        intl \
        zip \
        opcache \
        gd \
        exif

# Docker Desktop bind-mount'larında dosya stat çağrıları pahalı (~180µs);
# Symfony boot'ta on binlerce stat yapar. Önbellekleri büyüt.
RUN printf 'realpath_cache_size=64M\nrealpath_cache_ttl=600\nopcache.memory_consumption=256\n' \
    > /usr/local/etc/php/conf.d/zzz-perf.ini

# Session hardening.
#
# PHP's default for session.use_strict_mode is 0, which accepts a session id the server never
# issued. That is the session-fixation window, and it opens before any application code runs, so
# no controller or listener can close it. Symfony 8 removed `use_strict_mode` from framework
# configuration for exactly this reason: it is a PHP INI setting. It is therefore set here, in
# the image, where it takes effect, and
# tests/Security/SessionCookieSettingsTest asserts the live ini value rather than asserting a
# configuration key that no longer exists.
#
# Strict mode does not disturb a real shopper: the guest cart, the login redirect and the CSRF
# token store all read the session the application hands back, never the one that was offered.
RUN printf 'session.use_strict_mode=1\n' \
    > /usr/local/etc/php/conf.d/zzz-session.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Geliştirme: require-dev paketleriyle birlikte. Kaynak kod bind mount ile
# gelir; /app/vendor ise Linux named volume'da çalışır (compose.override.yaml),
# böylece Windows bind-mount filesystem maliyeti ortadan kalkar.
FROM base AS dev

ENV APP_ENV=dev

COPY . .

RUN composer install --prefer-dist --no-progress

EXPOSE 80 443

# Üretim: optimize edilmiş, dev paketsiz.
FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0

COPY . .

# --no-dev ile dev paketleri yoktur; script'ler prod çekirdeğiyle koşmalı.
# Secret SADECE bu adıma özel (imaja gömülmez); çalışırken compose/.env.local verir.
# `.dockerignore` `.env.local`'i dışladığı için gerçek merchant credential'ları bu katmana
# hiç giremez; aşağıdaki dummy secret yalnızca cache:clear'ın çalışması için.
RUN APP_ENV=prod APP_SECRET=dummy-build-secret-not-for-production-0123456789abcdef \
    composer install --no-dev --optimize-autoloader --prefer-dist --no-progress && \
    APP_ENV=prod APP_DEBUG=0 php bin/console asset-map:compile --env=prod && \
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod

RUN chmod +x docker/production-entrypoint.sh

ENTRYPOINT ["/app/docker/production-entrypoint.sh"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

EXPOSE 80 443
