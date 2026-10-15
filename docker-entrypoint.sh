#!/bin/sh
# ============================================================
#  Point d'entree Docker
#  Render impose le port via la variable PORT (ex : 10000).
#  On reconfigure Apache pour ecouter sur ce port.
# ============================================================
set -e

PORT="${PORT:-80}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[DigitalElect] Apache demarre sur le port ${PORT}"

# Apache au premier plan
exec "$@"
