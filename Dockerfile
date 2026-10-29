FROM php:8.2-fpm
RUN docker-php-ext-install mysqli
WORKDIR /var/www/html
COPY . .
RUN chown -R www-data:www-data public/uploads
