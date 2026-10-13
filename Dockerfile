FROM php:8.3-apache

# Installer les extensions MySQL nécessaires
RUN docker-php-ext-install mysqli pdo_mysql
RUN printf 'upload_max_filesize=50M\npost_max_size=512M\nmax_file_uploads=1000\n' > /usr/local/etc/php/conf.d/prefa-uploads.ini
# Les pièces jointes sont déposées sous la racine web, mais ne doivent jamais être servies
# directement : elles passent par les actions download_*.php, qui vérifient la session et
# l'appartenance de la demande. Sans ce refus, l'adresse d'un fichier suffit à le lire sans
# être connecté. AllowOverride vaut None ici, donc un .htaccess dans le dossier ne ferait rien.
RUN printf '<Directory /var/www/html/uploads>\nRequire all denied\n</Directory>\n<Directory /var/www/html/dashboard/uploads>\nRequire all denied\n</Directory>\n' > /etc/apache2/conf-available/prefa-uploads.conf && a2enconf prefa-uploads

# L'API répond sur des adresses qui ne sont pas des fichiers — /api/demandes/12. Tout ce
# qui, sous /api, ne correspond à aucun fichier existant part donc vers son point d'entrée,
# qui lit l'adresse et route. FallbackResource relève de mod_dir, actif par défaut : pas
# besoin de mod_rewrite ni d'un .htaccess, qui ne serait de toute façon pas lu ici
# (AllowOverride vaut None). Les fichiers qui existent — api/users/get.php — passent avant
# et continuent d'être servis directement.
RUN printf '<Directory /var/www/html/api>\nFallbackResource /api/index.php\n</Directory>\n' > /etc/apache2/conf-available/prefa-api.conf && a2enconf prefa-api

# Lecture des certificats matière (CCPU) : Poppler ouvre le PDF, Tesseract lit les scans.
# Tout reste sur le serveur, aucun service externe. Placé avant la copie de l'application
# pour que cette couche ne soit pas reconstruite à chaque modification du code.
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-fra \
    && rm -rf /var/lib/apt/lists/*

# Copier l'application web
COPY ./src /var/www/html/

# Copier les scripts hors du dossier web
COPY ./scripts /scripts/

# Donner les bons droits
RUN chown -R www-data:www-data /var/www/html

COPY ./scripts/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]

CMD ["apache2-foreground"]
