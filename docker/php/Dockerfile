FROM php:8.3-fpm

# Системные зависимости
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    libzip-dev \
    $PHPIZE_DEPS

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Установка расширений
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

RUN pecl install xdebug redis \
    && docker-php-ext-enable xdebug redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html
# RUN chmod -R 775 /var/www/html/storage
