#!/bin/sh
# Ensure writable directories have correct ownership on startup.
# Docker volumes are mounted at runtime, overriding Dockerfile chown.
mkdir -p /var/www/html/var/cache/twig \
         /var/www/html/var/log \
         /var/www/html/var/sessions \
         /var/www/html/public/sites_data

chown -R www-data:www-data /var/www/html/var /var/www/html/public/sites_data

# If a custom command is passed (e.g. worker/scheduler), run it directly.
# Otherwise start PHP-FPM.
if [ "$1" = "php" ] || [ "$1" = "php-fpm" ]; then
    exec "$@"
else
    exec php-fpm "$@"
fi
