# Production image for tx-bookings on the shared company VPS.
#
# The pattern is texra-crm's, which came from corepath-website by way of tx-cma
# and tx-scoreboard. Keep the two in step: this app sits on the same box, uses
# the same host mysqld over its socket, and is proxied by the same nginx.
#
# Two bases on purpose:
#   build   - Debian, because the Wayfinder Vite plugin shells out to
#             `php artisan`, so the asset stage needs PHP *and* Node, and
#             Debian's toolchain for that is the least surprising.
#   runtime - Alpine, because nothing compiled in the build stage ships
#             forward. vendor/ is pure PHP and public/build is static.
#
# PHP is pinned to 8.4 to match the VPS and the apps already containerised on
# it. composer.json requires ^8.3, so nothing forces a newer runtime -- note
# that local development runs 8.5, so anything relying on an 8.5-only feature
# will pass locally and fail here.

# ---------------------------------------------------------------------------
# Stage 1: dependencies and frontend assets.
# ---------------------------------------------------------------------------
FROM php:8.4-cli-bookworm AS build

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
    # pdo_mysql for the database; pcntl so `queue:work` honours --timeout
    # (without it a wedged calendar-sync job runs forever); calendar for
    # easter_days(), which HolidayCalendar uses to place Good Friday and Easter
    # Monday.
    #
    # Do not trim this list from `composer check-platform-reqs` alone -- it
    # reports only what composer.json DECLARES, and the easter_days() call is
    # not declared anywhere. Ubuntu's PHP bundles calendar, the official Alpine
    # image does not, so dropping it 500s every page that resolves holidays
    # while every container still reports healthy.
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl calendar \
    # Node 22 LTS. Matches the version the other apps on the VPS build with.
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency manifests first, so application edits do not invalidate the
# (slow) dependency layers.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# .npmrc is copied too -- it is committed and npm reads it during `npm ci`.
COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY . .

# dump-autoload fires post-autoload-dump (package:discover) on its own.
RUN composer dump-autoload --optimize --no-dev

# Wayfinder boots the application to enumerate routes, so `npm run build` needs
# a key; a throwaway one keeps that boot from failing and it never reaches the
# runtime image.
#
# It also needs somewhere to compile Blade to. .dockerignore excludes the
# storage tree by directory, so nothing under storage/framework exists in the
# build context and the boot dies with "Please provide a valid cache path" --
# surfaced only as `Command failed: php artisan wayfinder:generate`, because
# Rolldown swallows artisan's own output.
RUN mkdir -p storage/framework/views storage/framework/cache/data \
        storage/framework/sessions storage/logs

# The public hostname this image is built for.
#
# This is BAKED INTO THE JAVASCRIPT, not read at runtime. Wayfinder boots the
# application during `npm run build` and generates its URLs from
# config('app.url'), so setting APP_URL in .env.docker is too late -- the
# bundle was compiled long before any container started.
#
# Get it wrong and the failure is silent and total: every Wayfinder URL comes
# out as http://localhost, so the login form posts to http://localhost/login,
# the browser never sends the request anywhere reachable, and the submit button
# spins forever. No console error, no failed request, every container healthy.
#
# Confirm with `grep -o 'http://localhost[^"]*' public/build/assets/*.js`.
#
# Must match APP_URL in .env.docker. docker-compose.yml passes it through from
# the compose .env so the value lives in exactly one place.
ARG APP_URL=https://scheduling.texasrenters.com

# The name in the browser tab, and the same build-time trap as APP_URL above.
# resources/js/app.ts reads import.meta.env.VITE_APP_NAME when the bundle is
# compiled and falls back to 'Laravel', so setting APP_NAME in .env.docker is
# too late: config('app.name') would be right on the server while every tab
# still read "... - Laravel".
#
# Confirm with `grep -o 'Laravel' public/build/assets/app-*.js`.
ARG APP_NAME="TR Scheduling"

# `npm run build`, not `build:ssr`: vite.config.ts declares no SSR input and
# SSR is left off, so building it would only add time.
RUN APP_KEY=base64:$(head -c 32 /dev/urandom | base64) APP_URL="${APP_URL}" VITE_APP_NAME="${APP_NAME}" npm run build \
    # The runtime stage copies the tree wholesale; without this, several
    # hundred MB of build-only dependencies ship in the production image.
    && rm -rf node_modules

# opcodesio/log-viewer serves its UI from public/vendor/log-viewer, and the web
# stage copies public/ out of here -- so the assets are published during the
# build rather than committed, the same way the Vite bundle is. Publishing here
# also means they can never drift from the installed package version.
RUN APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan log-viewer:publish --no-interaction

# ---------------------------------------------------------------------------
# Stage 2: PHP-FPM runtime. Shared by the app, queue, scheduler and migrate
# services -- they differ only in the command they run.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

# fcgi provides cgi-fcgi, which the health check uses to ping FPM directly.
RUN apk add --no-cache fcgi \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl calendar

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /app /var/www/html

# storage/app/public holds the team logos (TeamController stores to the
# `public` disk) and is the one directory here with real user data in it; it
# sits under the storage-app volume so it survives a deploy. The rest are
# created so a first write never fails on a missing parent.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public \
        storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/healthcheck-fpm.sh /usr/local/bin/healthcheck-fpm
COPY docker/healthcheck-db.sh /usr/local/bin/healthcheck-db
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/healthcheck-fpm \
        /usr/local/bin/healthcheck-db

USER www-data

# Both halves, because FPM answering and the app working are not the same thing
# here: a stale /run/mysqld bind mount 500s every route while FPM still returns
# pong. See docker/healthcheck-db.sh. Shell form, since the exec form takes no
# `&&`.
#
# timeout is 10s rather than 5s to leave room for the database check's own 3s
# connect timeout on top of the FastCGI ping.
HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
    CMD healthcheck-fpm && healthcheck-db

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage 3: nginx.
#
# public/ is baked in rather than shared through a volume. A named volume is
# populated once from whichever image first mounts it and never refreshed,
# which would pin the site to the assets of the very first deploy.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /var/www/html/public

# Resolves to /var/www/html/storage/app/public, which is the storage volume.
RUN ln -sfn ../storage/app/public /var/www/html/public/storage

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1/up || exit 1
