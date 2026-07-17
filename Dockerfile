FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM php:8.3-apache

# Install gRPC and protobuf extensions (required by Firestore)
RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf \
        build-essential \
        zlib1g-dev \
        libssl-dev \
        pkg-config \
    && pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf \
    && php -m | grep -E 'grpc|protobuf' || (echo "❌ gRPC extension missing" && exit 1) \
    && apt-get purge -y --auto-remove autoconf build-essential \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and headers, allow .htaccess overrides
RUN a2enmod rewrite headers && \
    { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/z-allowoverride.conf && \
    a2enconf z-allowoverride

# Copy application source (including composer.json)
COPY . /var/www/html/

# Copy pre-built vendor from composer stage (overwrites any existing vendor)
COPY --from=vendor /app/vendor /var/www/html/vendor

# Install Composer and regenerate the autoloader
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-interaction

# Optionally remove composer.json to keep the image clean
RUN rm -f /var/www/html/composer.json

EXPOSE 80