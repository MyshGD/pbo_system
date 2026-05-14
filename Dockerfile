FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite

ENV PORT=8080
RUN sed -ri -e 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri -e 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf

RUN printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/app-root.conf \
    && a2enconf app-root

COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/sessions /var/www/html/backups /var/www/html/assets/images \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/backups /var/www/html/assets/images

EXPOSE 8080
