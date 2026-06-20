FROM php:8.2-apache

# Installera de PHP-tillägg som appen behöver (databas + bildhantering)
RUN apt-get update && apt-get install -y \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Tillåt större filuppladdningar (PDF:er kan vara upp till 25 MB)
RUN { \
        echo 'upload_max_filesize=30M'; \
        echo 'post_max_size=32M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini
