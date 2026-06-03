# ---------------------------------------------------------------------------
# Immagine base PHP 7.3 (vincolo dell'esercizio). Usiamo la variante FPM così
# la stessa immagine serve sia al web (dietro nginx) sia ai worker di coda.
# ---------------------------------------------------------------------------
FROM php:7.3-fpm

# Dipendenze di sistema:
#  - libzip/zip  -> richiesto da OpenSpout per scrivere gli .xlsx (sono zip)
#  - git/unzip   -> richiesti da Composer per installare i pacchetti
#
# NB: fissiamo l'estensione redis a una versione PECL compatibile con PHP 7.3:
# le versioni 6.x richiedono PHP >= 7.4 e farebbero fallire la build.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev; \
    docker-php-ext-install pdo_mysql zip bcmath; \
    pecl install redis-5.3.7; \
    docker-php-ext-enable redis; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Composer (copiato dall'immagine ufficiale, niente download a runtime).
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Installiamo prima le sole dipendenze, sfruttando la cache dei layer Docker:
# se cambia solo il codice applicativo non rifacciamo `composer install`.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist || true

# Copia del codice e generazione autoloader ottimizzato.
COPY . .
RUN composer dump-autoload --optimize

# php.ini con limiti adatti all'ingestione massiva e all'export.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000
CMD ["php-fpm"]
