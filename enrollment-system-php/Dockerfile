FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y unzip git libzip-dev default-mysql-client \
    && docker-php-ext-install sockets pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . /var/www/html/

# Serve the public/ folder as webroot
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's#/var/www/#/var/www/html/public/#g' /etc/apache2/apache2.conf

EXPOSE 80
