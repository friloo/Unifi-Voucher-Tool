# UniFi Voucher Management System – Container-Image
FROM php:8.2-apache

# PHP-Extensions
RUN docker-php-ext-install pdo pdo_mysql \
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
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
