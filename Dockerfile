FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN if [ ! -f config/config.php ]; then cp config/config.example.php config/config.php; fi \
    && mkdir -p logs uploads uploads/enrollment-documents uploads/attendance-faces \
    && chown -R www-data:www-data logs uploads
