FROM php:8.2-apache

# Install PostgreSQL PDO (Supabase) + common extras
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# Copy your code
COPY index.php /var/www/html/index.php

# Copy start script
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Render will set PORT. We expose 10000 as a hint (not required, but nice)
EXPOSE 10000

CMD ["/start.sh"]
