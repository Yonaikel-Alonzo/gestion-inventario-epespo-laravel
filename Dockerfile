FROM php:8.2-cli

# Dependencias del sistema + extensiones necesarias para PostgreSQL
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev \
 && docker-php-ext-install pdo pdo_pgsql zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copiar proyecto
COPY . .

# Instalar dependencias
RUN composer install --no-dev --optimize-autoloader

# Permisos para Laravel
RUN chmod -R 775 storage bootstrap/cache || true

EXPOSE 10000

# Render provee la variable PORT
CMD php artisan migrate --force && php -S 0.0.0.0:${PORT:-10000} -t public
