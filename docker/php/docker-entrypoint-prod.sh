#!/bin/sh
# Ensure writable directories have correct ownership on startup.
# Docker volumes are mounted at runtime, overriding Dockerfile chown.
mkdir -p /var/www/html/var/cache/twig \
         /var/www/html/var/log \
         /var/www/html/var/sessions \
         /var/www/html/public/sites_data \
         /var/www/html/backups/uploaded

# Sync built-in public assets into the volume.
# Named Docker volumes preserve old content across rebuilds, so we must
# copy updated assets (CSS, JS, media) from the image on every start.
if [ -d /usr/src/oci-public ]; then
    cp -a /usr/src/oci-public/. /var/www/html/public/
fi

# backups/ is load-bearing here. A named volume mounts root-owned, and both
# writers run as www-data: the worker creates archives, php-fpm retrieves them
# from offsite and serves the downloads. Without this chown the volume that was
# added to stop archives dying on deploy would instead stop them being written
# at all — and the failure surfaces as an unhelpful "no detail returned" from
# deep inside an SFTP fetch, because the local fopen is what actually failed.
#
# public/ is handed over whole, not only sites_data: the scheduler writes the
# vendor list, the status feed and the cookie pages there, and it no longer
# runs as root (see below).
chown -R www-data:www-data /var/www/html/var /var/www/html/public /var/www/html/backups
# The owner must be able to write what it owns. An image built from a checkout
# on a filesystem without Unix permissions ships its folders read-only (555),
# and the copy above carries that into the volume.
chmod -R u+rwX /var/www/html/var /var/www/html/public /var/www/html/backups

# This script runs as root, because only root can prepare the volumes above.
# Nothing after it should. PHP-FPM drops to www-data for its workers by
# itself; a worker, the scheduler or any other `php ...` command is started as
# www-data here. They used to run as root, so a file a worker wrote first was
# read-only to the dashboard, and a flaw in a job ran with every privilege the
# container had.
#
# OCI_RUN_AS_ROOT=1 keeps a command on root, for a service that writes to a
# volume another container owns.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
elif [ "$1" = "php" ]; then
    if [ "${OCI_RUN_AS_ROOT:-0}" = "1" ] || [ "$(id -u)" != "0" ]; then
        exec "$@"
    fi
    # www-data has no home directory; tools that want one get a writable place.
    # `env` replaces itself with the command, so PHP is still process 1 and
    # still receives the stop signal.
    exec su-exec www-data env HOME=/tmp "$@"
else
    exec php-fpm "$@"
fi
