FROM php:8.3-apache

RUN apt-get update && \
    apt-get install -y libsqlite3-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install pdo_sqlite

RUN mkdir -p /app/data && chown -R www-data:www-data /app/data