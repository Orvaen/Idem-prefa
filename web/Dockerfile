FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite

RUN printf 'upload_max_filesize=50M\npost_max_size=512M\nmax_file_uploads=1000\n' \
    > /usr/local/etc/php/conf.d/prefa-uploads.ini \
    && printf 'session.gc_maxlifetime=28800\n' \
    > /usr/local/etc/php/conf.d/prefa-session.ini \
    && printf 'log_errors=On\ndisplay_errors=Off\nauto_prepend_file=/var/www/html/journal.php\n' \
    > /usr/local/etc/php/conf.d/prefa-journal.ini

RUN printf '<Directory /var/www/html/uploads>\nRequire all denied\n</Directory>\n<Directory /var/www/html/dashboard/uploads>\nRequire all denied\n</Directory>\n<Directory /var/www/html/api>\nFallbackResource /api/index.php\n</Directory>\n' \
    > /etc/apache2/conf-available/prefa-app.conf \
    && a2enconf prefa-app

COPY ./src /var/www/html/

RUN printf '#!/bin/sh\nset -eu\nmkdir -p /var/www/html/dashboard/uploads/prefa\nchown -R www-data:www-data /var/www/html/dashboard/uploads\nexec docker-php-entrypoint "$@"\n' \
    > /usr/local/bin/app-entrypoint \
    && chmod +x /usr/local/bin/app-entrypoint \
    && chown -R www-data:www-data /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-fra \
    && rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["apache2-foreground"]