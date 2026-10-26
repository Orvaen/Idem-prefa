#!/bin/sh
set -eu

# The development bind mount replaces ownership configured at image build time.
mkdir -p /var/www/html/dashboard/uploads/prefa
chown -R www-data:www-data /var/www/html/dashboard/uploads
# Le mode, en tête du journal. Il ne se lit nulle part ailleurs dans les lignes qui
# suivent, et c'est la première chose qu'on veut savoir en les lisant : un écran de
# développement ne se comporte pas comme celui de l'atelier.
printf '** %s app  démarrage en mode %s\n' "$(date +%H:%M:%S)" "${APP_ENV:-prod}" >&2

exec docker-php-entrypoint "$@"
