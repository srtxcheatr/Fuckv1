FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM php:8.3-apache

# १. Firestore को लागि आवश्यक पर्ने gRPC र Protobuf इन्स्टल गर्ने
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

# २. Apache rewrite/headers इनेबल गर्ने र .htaccess Override अलाउ गर्ने
RUN a2enmod rewrite headers && \
    { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '    Require all granted'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/z-allowoverride.conf && \
    a2enconf z-allowoverride

# ३. सोर्स कोड र पहिले नै बिल्ड भएको Vendor कपि गर्ने
COPY . /var/www/html/
COPY --from=vendor /app/vendor /var/www/html/vendor

# ४. Composer राख्ने र Autoloader लाई अप्टिमाइज गर्ने
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-interaction

# ५. Render मा Permission को समस्या आउन नदिन फाइल ओनरसिप मिलाउने
RUN chown -R www-data:www-data /var/www/html

# ६. सफाइका लागि composer.json हटाउने
RUN rm -f /var/www/html/composer.json

EXPOSE 80
