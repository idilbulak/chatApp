FROM php:8.1-fpm

RUN apt-get update && apt-get install -y libsqlite3-dev sqlite3 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_sqlite

COPY . /var/www/html

RUN chmod 0666 /var/www/html/tools/init-db.sql

RUN sqlite3 /var/www/html/data/db.sqlite < /var/www/html/tools/init-db.sql

RUN chmod 0666 /var/www/html/data/db.sqlite

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html/public"]

