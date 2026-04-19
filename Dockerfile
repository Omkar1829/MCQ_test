FROM php:8.3-apache

RUN docker-php-ext-install pdo_pgsql

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
