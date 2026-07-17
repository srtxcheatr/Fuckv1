FROM php:8.3-apache

# १. आवश्यक पर्ने सिष्टम डिपेन्डेन्सी र gRPC / Protobuf एक्स्टेन्सन इन्स्टल गर्ने
RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf \
        build-essential \
        zlib1g-dev \
        libssl-dev \
        pkg-config \
        libzip-dev \
        zip \
        unzip \
        git \
    && pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf \
    && docker-php-ext-install zip \
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

# ३. प्रोजेक्टका सबै फाइलहरू कपि गर्ने
WORKDIR /var/www/html
COPY . .

# ४. आधिकारिक Composer इमेजबाट सिधै कम्पोजर तान्ने र डिपेन्डेन्सी रन गर्ने
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# कम्पोजर इन्स्टल सिधै यहाँ रन गर्ने (यसले एरर आउन दिँदैन र सिधै बिल्ड गर्छ)
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# ५. Permissions मिलाउने
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
