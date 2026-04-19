FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql curl mbstring \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/coolify/docker/php-apache/allow-override.conf /etc/apache2/conf-available/allow-override.conf
RUN a2enconf allow-override

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html
