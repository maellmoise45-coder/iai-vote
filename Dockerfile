# ============================================================
#  Digital-Elect
#  Image Docker : PHP 8.2 + Apache + extension PostgreSQL
#  Deploiement : Render.com (ou tout hebergeur Docker)
# ============================================================
FROM php:8.2-apache

# Extension PostgreSQL pour PDO
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# Modules Apache utiles (reecriture d'URL + headers Authorization)
RUN a2enmod rewrite headers

# Code de l'application
COPY . /var/www/html/

# Autoriser la surcharge par .htaccess et transmettre Authorization
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/iai-vote.conf \
    && a2enconf iai-vote

# Script de demarrage : Render fournit la variable PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
