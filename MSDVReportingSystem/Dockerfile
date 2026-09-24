FROM php:8.2-apache

RUN apt-get update && apt-get install -y libzip-dev zip unzip \
    && docker-php-ext-install zip
# MySQL extensions (TiDB is MySQL-compatible)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable .htaccess / rewrites and silence the ServerName warning
RUN a2enmod rewrite \
 && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy the app into Apache's web root
COPY MSDVReportingSystem/ /var/www/html/

# Let Apache write to the uploads folder
RUN mkdir -p /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html/uploads

# Render sends traffic to $PORT (default 10000), so make Apache listen on it
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

ENV PORT=10000
EXPOSE 10000
