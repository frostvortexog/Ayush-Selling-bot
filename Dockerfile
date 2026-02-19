FROM php:8.2-apache

# Install mysqli extension (for MySQL)
RUN docker-php-ext-install mysqli

# Enable Apache rewrite (optional, but good practice)
RUN a2enmod rewrite

# Copy your bot file
COPY index.php /var/www/html/index.php

# (Optional) Set Apache to listen on Render/Cloud port via env PORT
# We'll keep default 80; most platforms map PORT->80 automatically.

EXPOSE 80
