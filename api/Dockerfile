FROM dunglas/frankenphp:1-php8.3

# FrankenPHP serves /app/public itself — no separate web server, no FastCGI wiring.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so editing source does not invalidate the install layer.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var \
    && chmod -R 0777 var
