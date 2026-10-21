FROM php:8.3-apache

# Outils nécessaires à Composer (git, unzip) et à l'extension intl (libicu)
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev \
    && docker-php-ext-install intl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
