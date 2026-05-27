# Stage 1: Install Composer dependencies
FROM php:8.4-cli-alpine AS composer-builder
WORKDIR /app
RUN apk add --no-cache git unzip zip
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Stage 2: Final Production Image
FROM php:8.4-cli-alpine
WORKDIR /app

# Install runtime system dependencies
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    zip \
    libzip-dev \
    unzip \
    oniguruma-dev \
    sqlite-dev \
    postgresql-dev \
    postgresql-client \
    bash

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_sqlite pdo_pgsql mbstring zip exif pcntl

# Copy custom php configuration
COPY .docker/php/php.ini $PHP_INI_DIR/conf.d/custom-php.ini

# Copy application files (excluding those in .dockerignore)
COPY . .

# Copy Composer dependencies from composer-builder
COPY --from=composer-builder /app/vendor ./vendor

# Ensure correct permissions for storage and bootstrap cache
RUN mkdir -p storage/framework/{sessions,views,cache} bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

# Generate package discovery cache for production
RUN php artisan package:discover --ansi

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
