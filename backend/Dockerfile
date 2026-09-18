FROM php:8.3-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    git

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Setup working directory
WORKDIR /var/www/html

FROM base AS deps

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies (without dev and scripts)
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM base AS production

# Copy existing application directory contents
COPY . .

# Copy vendor from deps
COPY --from=deps /var/www/html/vendor /var/www/html/vendor

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Generate optimized autoload files and run package discovery
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
