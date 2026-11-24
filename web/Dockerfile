# Imagen de producción para Render (plan gratuito, servicio web Docker).
FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public COMPOSER_ALLOW_SUPERUSER=1
# Laravel enruta con public/.htaccess: Apache debe permitirlo (por defecto viene AllowOverride None).
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && grep -A4 '<Directory /var/www/>' /etc/apache2/apache2.conf | grep -q 'AllowOverride All' \
    && printf "upload_max_filesize=8M\npost_max_size=10M\nmemory_limit=256M\nmax_execution_time=90\nexpose_php=Off\n" > /usr/local/etc/php/conf.d/peaje.ini

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && rm -f .env database/database.sqlite bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]
