# UniFi Voucher Management System – Container-Image
FROM php:8.2-apache

# System-Tools (curl für Healthcheck) + PHP-Extensions
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers

# Empfohlene PHP-Einstellungen
RUN { \
      echo 'display_errors=0'; \
      echo 'log_errors=1'; \
      echo 'expose_php=0'; \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/zz-voucher.ini

WORKDIR /var/www/html
COPY . /var/www/html

# Laufzeit-Verzeichnis des Updaters beschreibbar machen
RUN mkdir -p /var/www/html/updater/storage \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

# Apache-Worker laufen als www-data (Privilege-Drop durch den Master).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://localhost/health.php || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
