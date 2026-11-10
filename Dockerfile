# Développement uniquement : Apache et PHP 8.4, comme le serveur de production.
# La production n'utilise pas Docker (voir docs/hebergement.md).
FROM php:8.4.26-apache

# Outils nécessaires à Composer (git, unzip) et bibliothèque ICU pour l'extension intl
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev \
    && docker-php-ext-install intl pdo_mysql opcache \
    && rm -rf /var/lib/apt/lists/*

# Réglages PHP de production (erreurs non affichées par PHP lui-même : Symfony s'en charge)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Modules utilisés en production : réécriture d'adresses (public/.htaccess), en-têtes, compression
RUN a2enmod rewrite headers deflate
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
