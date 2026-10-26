# The runtime, pinned (Q7). Verified by running it, not by reading a tag:
#
#   $ docker run --rm dunglas/frankenphp:1.13.3-php8.4-bookworm php -v
#   PHP 8.4.27 (cli) (built: Oct 21 2026 15:55:55) (ZTS)
#   $ docker run --rm dunglas/frankenphp:1.13.3-php8.4-bookworm frankenphp version
#   FrankenPHP v1.13.3 PHP 8.4.27 Caddy v2.11.7
#
# THE TAG NAMES THE PHP VERSION ON PURPOSE. `dunglas/frankenphp:1.13.3` alone is
# ambiguous -- 1.13.3-php8.5 and 1.13.3-php8.5.12 ship in the same release, so the
# unqualified tag can float to a PHP this project does not run. Task 0 already lost time
# to a file telling agents to install PHP 8.5 against §1 (§4a).
#
# The build is ZTS, which is correct and not incidental: FrankenPHP's worker mode is
# threaded, and an extension compiled for NTS will not load in it.
#
# >= 1.13.3 is a floor, not a preference: Octane requires >= 1.13.0, and the 1.13.3
# release notes record worker mode broken for symlinked document roots in 1.13.0-1.13.2.
FROM dunglas/frankenphp:1.13.3-php8.4-bookworm

# Measured INSIDE the base image: PDO, mbstring and posix are present; pdo_pgsql, redis,
# pcntl, intl and bcmath are NOT. pcntl is not optional -- Laravel's trap() is how
# `schedule:work`, the readiness carrier (§6.3), drains on SIGTERM.
RUN install-php-extensions pdo_pgsql redis pcntl intl bcmath opcache zip

# unzip, because composer unpacks dist archives with it and the base image ships
# neither it nor the zip extension -- the first build failed with "The zip extension and
# unzip/7z commands are both missing". git, because composer falls back to it for any
# package without a dist archive.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies first, so a source change does not re-resolve them. --no-dev on purpose:
# the drain proof exercises the runtime, and the dev tools are gates that run outside it.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY . .

# The directories first. .dockerignore excludes bootstrap/cache and the storage caches
# -- deliberately, so the image is built from source rather than from a developer
# machine's state -- and package:discover needs bootstrap/cache to exist before it can
# write the manifest.
RUN mkdir -p bootstrap/cache storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && composer dump-autoload --no-dev --optimize

# Octane's own worker script, where the Caddyfile expects it -- AND bootstrap.php beside
# it, which is the part that is easy to miss: frankenphp-worker.php does
# `require __DIR__.'/bootstrap.php'`, so copying only the worker gives
# "Failed opening required '/app/public/bootstrap.php'" on every thread and FrankenPHP
# exits with "worker has not reached frankenphp_handle_request()". That bootstrap also
# reads APP_BASE_PATH, which the compose service sets.
#
# Copied rather than symlinked: 1.13.0-1.13.2 broke worker mode for symlinked document
# roots, and a symlink here is the shape that broke.
RUN cp vendor/laravel/octane/bin/frankenphp-worker.php public/frankenphp-worker.php \
    && cp vendor/laravel/octane/bin/bootstrap.php public/bootstrap.php \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_BASE_PATH=/app

COPY docker/Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080

# No octane:start: that command downloads a static binary, and the whole point of Q7 is
# that the runtime is THIS image. frankenphp is already on PATH here.
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
