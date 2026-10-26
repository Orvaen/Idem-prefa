FROM php:8.3-apache

# Installer les extensions MySQL nécessaires
RUN docker-php-ext-install mysqli pdo_mysql
RUN printf 'upload_max_filesize=50M\npost_max_size=512M\nmax_file_uploads=1000\n' > /usr/local/etc/php/conf.d/prefa-uploads.ini
# Une session tient la journée de travail. Par défaut PHP la ramasse après 24 minutes
# d'inactivité : on revenait d'une réunion devant un écran de connexion, et surtout la
# liste des comptes mémorisés — qui vit dans la session — disparaissait avec elle, obligeant
# à se reconnecter sur chaque compte un par un.
#
# Le cookie, lui, reste un cookie de session : fermer le navigateur déconnecte, ce qui est
# la bonne règle sur un poste d'atelier partagé.
RUN printf 'session.gc_maxlifetime=28800\n' > /usr/local/etc/php/conf.d/prefa-session.ini
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

# Le journal du conteneur — « docker compose logs php ». Trois pièces :
#
# 1. Le format des lignes, dans docker/apache-journal.conf. Il remplace « combined », deux
#    cents caractères par requête dont la moitié répète le nom du navigateur. Apache est
#    livré avec un CustomLog dans son site par défaut, qui l'emporterait sur le nôtre ; et
#    other-vhosts-access-log, une fois le premier retiré, se mettrait à tout noter une
#    seconde fois. Les deux s'en vont donc ici.
#
# 2. log_errors : il valait Off. Aucun avertissement, aucune erreur fatale de PHP n'était
#    notée — elles partaient dans la page, à la place de la réponse attendue. display_errors
#    s'éteint pour la même raison : une erreur imprimée avant les en-têtes empêche PHP de
#    répondre 500, et l'échec ressort en 200.
#
# 3. journal.php, chargé avant chaque script : il attrape ce que PHP signale et donne à
#    l'application de quoi noter ses propres faits. Voir le fichier pour le détail.
#
# L'heure du journal est celle de l'atelier : les écrans affichent Europe/Paris, le journal
# aussi. PHP ne lit pas TZ — il garde date.timezone, c'est-à-dire UTC — donc rien ne change
# pour les dates enregistrées en base.
ENV TZ=Europe/Paris
COPY ./docker/apache-journal.conf /etc/apache2/conf-available/prefa-journal.conf
RUN a2enconf prefa-journal \
    && a2disconf other-vhosts-access-log \
    && sed -i '/CustomLog/d' /etc/apache2/sites-available/000-default.conf
RUN printf 'log_errors=On\ndisplay_errors=Off\nauto_prepend_file=/var/www/html/journal.php\n' > /usr/local/etc/php/conf.d/prefa-journal.ini

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
