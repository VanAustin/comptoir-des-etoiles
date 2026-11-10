# Développement uniquement : FrankenPHP (PHP + serveur web Caddy).
# La production n'en dépend pas (Apache ou Nginx, voir README.md).
FROM dunglas/frankenphp:1.13.0-php8.3

# Outils nécessaires à Composer (git, unzip) ; opcache est déjà incluse
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions intl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
