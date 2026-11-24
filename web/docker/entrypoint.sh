#!/bin/sh
set -e
# Render asigna el puerto en $PORT.
PORT="${PORT:-10000}"
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
# Crea lista blanca (docente admin + ADMIN_EMAILS) y, la primera vez, los datos de prueba.
php artisan db:seed --force

# Los comandos anteriores corren como root: Apache (www-data) debe poder leer/escribir la caché.
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
