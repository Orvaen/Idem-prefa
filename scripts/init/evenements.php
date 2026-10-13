<?php

/**
 * Le journal des événements que le flux annonce.
 *
 * Le flux repérait les nouveautés au plus grand identifiant de demande : il voyait donc
 * les dépôts, et rien d'autre. Une validation ne crée pas de ligne, elle en modifie une —
 * le chargé d'affaire n'apprenait jamais que sa demande était passée.
 *
 * On aurait pu guetter date_validation en plus, mais deux curseurs pour deux sortes de
 * nouvelles se mettent à diverger : l'un rattrape, l'autre saute. Une table de journal
 * donne un seul curseur, strictement croissant, et le prochain événement à annoncer
 * tiendra en une insertion.
 *
 * Idempotent : à relancer sans risque.
 *
 *   docker exec -i php-app-php-1 php < scripts/init/evenements.php
 */
// Le script se lance aussi bien dans le conteneur, où il arrive par l'entrée standard et
// n'a donc pas de __DIR__ utile, que depuis le dépôt.
$racine = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $racine . '/db.php';

$db = new MyPDO($racine . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS evenement (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(32) NOT NULL COMMENT 'demande.deposee, demande.validee, demande.refusee',
  id_demande INT DEFAULT NULL COMMENT 'la demande concernée, pour savoir qui a le droit de voir passer l''événement',
  id_acteur INT DEFAULT NULL COMMENT 'qui l''a provoqué : on ne lui annonce pas ce qu''il vient de faire',
  donnees JSON DEFAULT NULL COMMENT 'ce qu''il faut pour écrire l''alerte',
  cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY cree_le (cree_le),
  KEY demande (id_demande)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

echo "Table evenement prête.\n";

// Ajoutée après coup : un événement s'adresse soit à ceux qui ont le droit de voir la
// demande qu'il concerne, soit à une personne précise. Une affaire confiée ne regarde que
// celui à qui on la confie, et elle ne porte aucune demande.
$colonnes = $db->query('SHOW COLUMNS FROM evenement')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('id_destinataire', $colonnes, true)) {
    $db->exec('ALTER TABLE evenement
               ADD COLUMN id_destinataire INT DEFAULT NULL
                   COMMENT "destinataire unique ; vide = visible selon la demande"
                   AFTER id_acteur,
               ADD KEY destinataire (id_destinataire)');
    echo "Colonne id_destinataire ajoutée.
";
} else {
    echo "Colonne id_destinataire déjà là.
";
}

// Un journal qu'on ne purge pas devient la plus grosse table de la base. Rien n'est
// annoncé au-delà de quelques minutes : une semaine est déjà large, et laisse de quoi
// comprendre après coup ce qui s'est passé un jour donné.
$vieux = $db->exec('DELETE FROM evenement WHERE cree_le < NOW() - INTERVAL 7 DAY');
echo "Événements de plus de sept jours retirés : $vieux\n";
