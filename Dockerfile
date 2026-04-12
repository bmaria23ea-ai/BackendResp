FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev sqlite3 pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_sqlite
RUN a2enmod rewrite headers

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY api/ /var/www/html/api/

RUN chown -R www-data:www-data /var/www/html

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
