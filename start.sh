#!/bin/sh
set -e

# Render provides PORT; default to 10000 if missing
PORT="${PORT:-10000}"

# Update Apache to listen on the PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Start apache
exec apache2-foreground
