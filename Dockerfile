FROM php:8.2-apache

# Installera de PHP-tillägg som appen behöver (databas + bildhantering)
# samt git och mariadb-klienten (behövs i Codespaces/devcontainer)
RUN apt-get update && apt-get install -y \
        libpng-dev libjpeg-dev libfreetype6-dev \
        git mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Tillåt större filuppladdningar (PDF:er kan vara upp till 25 MB)
# och använd en egen sessionskatalog med rätt rättigheter
# (undviker "Permission denied" på /tmp i Codespaces)
RUN mkdir -p /var/lib/php/sessions && chmod 1777 /var/lib/php/sessions \
    && { \
        echo 'upload_max_filesize=30M'; \
        echo 'post_max_size=32M'; \
        echo 'memory_limit=256M'; \
        echo 'session.save_path=/var/lib/php/sessions'; \
    } > /usr/local/etc/php/conf.d/uploads.ini
