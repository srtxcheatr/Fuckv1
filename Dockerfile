# Render doesn't run PHP natively — this deploys as a Docker web
# service. On Render: New → Web Service → Language: Docker.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM php:8.3-apache

# Firestore (via google/cloud-firestore, which kreait/firebase-php
# bridges to) REQUIRES the gRPC PHP extension. Without it, every
# single Firestore call in this project fatal-errors with "The
# requested client requires the gRPC extension" — which the shutdown
# handler in firebase.php then reports to the frontend as the generic
# "Internal server error. Please try again." This is very likely the
# actual cause of the errors seen throughout this whole project, not
# just the newer endpoints.
#
# NOTE: this compiles from source and can take a while (sometimes
# 10-20+ minutes) on Render's free build — be patient on first deploy.
RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf \
        build-essential \
        zlib1g-dev \
        libssl-dev \
        pkg-config \
    && pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf \
    && apt-get purge -y --auto-remove autoconf build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html/
RUN rm -f /var/www/html/composer.json

# Apache strips the Authorization header before PHP ever sees it,
# unless mod_rewrite explicitly passes it through (see .htaccess) —
# and Apache ignores .htaccess entirely unless AllowOverride is on.
RUN a2enmod rewrite headers && \
    { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/z-allowoverride.conf && \
    a2enconf z-allowoverride

EXPOSE 80
