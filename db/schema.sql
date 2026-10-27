-- Schema de la base « myapp » — application de prefabrication IDEM.
--
-- Ce fichier cree les tables vides et les seules donnees sans lesquelles
-- l'application ne fonctionne pas : les roles, les statuts de demande et le
-- parametrage du rendement.
--
-- Il ne contient aucune donnee metier : ni comptes, ni demandes, ni documents.
-- Pour creer le premier administrateur, voir scripts/init/add_user.php.
--
-- Installation sur une base vide :
--   docker compose exec -T db mysql -umyuser -pmypassword myapp < db/schema.sql
--
-- Le fichier ne supprime aucune table : l'appliquer sur une base deja remplie
-- echoue au lieu d'effacer quoi que ce soit.

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Utilisateur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `id_role` int NOT NULL,
  `password` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `prenom` varchar(256) NOT NULL,
  `identifiant` varchar(256) NOT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `email_2` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Table Utilisateur';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `affaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  `id_charge_affaire` int DEFAULT NULL COMMENT 'Le chargé d''affaire, rôle 5',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `affaire_nom` (`nom`),
  KEY `fk_affaire_charge` (`id_charge_affaire`),
  CONSTRAINT `fk_affaire_charge` FOREIGN KEY (`id_charge_affaire`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `affectation_atelier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_element` int NOT NULL,
  `id_utilisateur` int NOT NULL,
  `type_affectation` enum('plan','montage','soudage') NOT NULL,
  `affecte_par` int NOT NULL,
  `date_affectation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actif` tinyint(1) DEFAULT '1',
  `date_fin_affectation` datetime DEFAULT NULL,
  `avancement` tinyint unsigned NOT NULL DEFAULT '0',
  `commentaire` text,
  `revision` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atelier_active` (`id_element`,`type_affectation`,`actif`),
  KEY `idx_atelier_utilisateur` (`id_utilisateur`,`actif`),
  KEY `affecte_par` (`affecte_par`),
  CONSTRAINT `affectation_atelier_ibfk_1` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `affectation_atelier_ibfk_2` FOREIGN KEY (`id_utilisateur`) REFERENCES `Utilisateur` (`id`),
  CONSTRAINT `affectation_atelier_ibfk_3` FOREIGN KEY (`affecte_par`) REFERENCES `Utilisateur` (`id`),
  CONSTRAINT `affectation_atelier_chk_1` CHECK ((`avancement` <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `avancement_atelier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_affectation` int NOT NULL,
  `id_utilisateur` int NOT NULL,
  `avancement` tinyint unsigned NOT NULL,
  `soudures` smallint unsigned DEFAULT NULL,
  `soudures_total` smallint unsigned DEFAULT NULL,
  `commentaire` text,
  `etat` enum('propose','valide','refuse') NOT NULL DEFAULT 'valide',
  `valide_par` int DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `date_saisie` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `fk_avancement_valide` (`valide_par`),
  KEY `idx_avancement_attente` (`id_affectation`,`etat`,`id`),
  CONSTRAINT `avancement_atelier_ibfk_1` FOREIGN KEY (`id_affectation`) REFERENCES `affectation_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `avancement_atelier_ibfk_2` FOREIGN KEY (`id_utilisateur`) REFERENCES `Utilisateur` (`id`),
  CONSTRAINT `fk_avancement_valide` FOREIGN KEY (`valide_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `avancement_atelier_chk_1` CHECK ((`avancement` <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bon_livraison` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `numero` varchar(40) NOT NULL,
  `fournisseur` varchar(120) NOT NULL,
  `date_bl` date DEFAULT NULL,
  `date_livraison_2` date DEFAULT NULL COMMENT 'seconde échéance quand la commande arrive en deux fois',
  `lieu_livraison` varchar(255) DEFAULT NULL COMMENT 'où la marchandise arrive, repris de l''adresse de livraison du bon',
  `statut` enum('attendu','partielle','complete','refusee') NOT NULL DEFAULT 'attendu',
  `commentaire` varchar(1000) DEFAULT NULL,
  `receptionne_par` int DEFAULT NULL,
  `date_reception` datetime DEFAULT NULL,
  `revision` int unsigned NOT NULL DEFAULT '0',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bon_livraison_numero` (`numero`),
  KEY `bon_livraison_demande` (`id_demande`),
  KEY `bon_livraison_receptionnaire_fk` (`receptionne_par`),
  CONSTRAINT `bon_livraison_demande_fk` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bon_livraison_receptionnaire_fk` FOREIGN KEY (`receptionne_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bon_livraison_ligne` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_bon` int NOT NULL,
  `ordre` int NOT NULL DEFAULT '0',
  `reference_article` varchar(80) DEFAULT NULL,
  `designation` varchar(180) NOT NULL,
  `unite` varchar(16) NOT NULL DEFAULT 'u',
  `quantite_attendue` decimal(12,3) NOT NULL,
  `quantite_recue` decimal(12,3) DEFAULT NULL,
  `commentaire` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bon_livraison_ligne_bon` (`id_bon`),
  CONSTRAINT `bon_livraison_ligne_bon_fk` FOREIGN KEY (`id_bon`) REFERENCES `bon_livraison` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charger_affaier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_approvisionnement` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `id_ligne_bl` int DEFAULT NULL,
  `designation` varchar(180) NOT NULL,
  `quantite` decimal(12,3) NOT NULL,
  `unite` varchar(16) NOT NULL DEFAULT 'u',
  `motif` enum('oubli','erreur','fabrication','autre') NOT NULL,
  `commentaire` varchar(1000) DEFAULT NULL,
  `demande_par` int NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('nouvelle','vue','traitee') NOT NULL DEFAULT 'nouvelle',
  `traite_par` int DEFAULT NULL,
  `date_traitement` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demande_approvisionnement_demande` (`id_demande`),
  KEY `demande_approvisionnement_statut` (`statut`),
  KEY `demande_approvisionnement_ligne_fk` (`id_ligne_bl`),
  KEY `demande_approvisionnement_auteur_fk` (`demande_par`),
  KEY `demande_approvisionnement_traitant_fk` (`traite_par`),
  CONSTRAINT `demande_approvisionnement_auteur_fk` FOREIGN KEY (`demande_par`) REFERENCES `Utilisateur` (`id`) ON DELETE CASCADE,
  CONSTRAINT `demande_approvisionnement_demande_fk` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE,
  CONSTRAINT `demande_approvisionnement_ligne_fk` FOREIGN KEY (`id_ligne_bl`) REFERENCES `bon_livraison_ligne` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demande_approvisionnement_traitant_fk` FOREIGN KEY (`traite_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_ccpu_coulees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_document` bigint unsigned NOT NULL,
  `numero` varchar(20) NOT NULL,
  `page` smallint unsigned NOT NULL DEFAULT '1',
  `confiance` tinyint unsigned NOT NULL DEFAULT '0',
  `confirmee` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ccpu_coulee_par_document` (`id_document`,`numero`),
  CONSTRAINT `fk_ccpu_coulees_document` FOREIGN KEY (`id_document`) REFERENCES `demande_ccpu_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_ccpu_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` longblob NOT NULL,
  `sha256` binary(32) NOT NULL,
  `lecture` varchar(10) DEFAULT NULL,
  `erreur` varchar(255) DEFAULT NULL,
  `date_lecture` datetime DEFAULT NULL,
  `certificat` varchar(40) DEFAULT NULL,
  `dimensions` varchar(255) DEFAULT NULL,
  `nuances` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ccpu_documents_demande` (`id_demande`),
  CONSTRAINT `fk_ccpu_documents_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_dmos` (
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` mediumblob NOT NULL,
  PRIMARY KEY (`id_demande`),
  CONSTRAINT `fk_dmos_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_dmos_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` mediumblob NOT NULL,
  `sha256` binary(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dmos_documents_demande` (`id_demande`),
  CONSTRAINT `fk_dmos_documents_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_materiel_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` mediumblob NOT NULL,
  `sha256` binary(32) NOT NULL,
  `lecture` varchar(10) DEFAULT NULL,
  `erreur` varchar(255) DEFAULT NULL,
  `date_lecture` datetime DEFAULT NULL,
  `numero_commande` varchar(40) DEFAULT NULL COMMENT 'numéro lu dans l''en-tête du bon',
  `date_commande` date DEFAULT NULL,
  `verifie_par` int DEFAULT NULL,
  `date_verification` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_materiel_documents_demande` (`id_demande`),
  KEY `fk_materiel_documents_verificateur` (`verifie_par`),
  CONSTRAINT `fk_materiel_documents_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_materiel_documents_verificateur` FOREIGN KEY (`verifie_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_materiel_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_document` bigint unsigned NOT NULL,
  `rang` smallint unsigned NOT NULL DEFAULT '0',
  `page` smallint unsigned NOT NULL DEFAULT '1',
  `reference` varchar(80) DEFAULT NULL,
  `designation` varchar(180) NOT NULL,
  `unite` varchar(16) NOT NULL DEFAULT 'u',
  `quantite` decimal(12,3) NOT NULL,
  `date_livraison` date DEFAULT NULL,
  `confiance` tinyint unsigned NOT NULL DEFAULT '0',
  `douteuse` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_materiel_lignes_document` (`id_document`),
  CONSTRAINT `fk_materiel_lignes_document` FOREIGN KEY (`id_document`) REFERENCES `demande_materiel_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_prefabrication` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'id de la table',
  `idUsers` int NOT NULL COMMENT 'clé secondaire relier à la table utilisateur',
  `DateUpdate` datetime NOT NULL COMMENT 'Date de la mise à jours de la demande',
  `date_creation` datetime DEFAULT NULL,
  `date_livraison_prevue` date DEFAULT NULL,
  `date_fin_prevue` date DEFAULT NULL,
  `nom_affaire` varchar(255) DEFAULT NULL,
  `id_affaire` int DEFAULT NULL,
  `plan_bpe_iso` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `pouces_total_iso` int NOT NULL,
  `heures_chiffrees` decimal(15,2) NOT NULL DEFAULT '0.00',
  `CDN` tinyint(1) NOT NULL COMMENT 'Si oui alors communiquer le pourcentage par contrôle',
  ` QMOS` varchar(255) NOT NULL,
  `urgent` tinyint(1) NOT NULL,
  `id_statut` int NOT NULL DEFAULT '1',
  `controles_cdn` varchar(1000) DEFAULT NULL,
  `commentaire_validation` varchar(1000) DEFAULT NULL,
  `valide_par` int DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `id_passivation` int DEFAULT NULL,
  `revetement` tinyint(1) NOT NULL DEFAULT '0',
  `commentaire_revetement` varchar(1000) DEFAULT NULL,
  `id_matiere` int DEFAULT NULL,
  `matiere_disponibilite` enum('stock','commande') DEFAULT NULL,
  `RT` tinyint(1) NOT NULL DEFAULT '0',
  `PT` tinyint(1) NOT NULL DEFAULT '0',
  `controles_rt` decimal(5,2) DEFAULT NULL,
  `controles_pt` decimal(5,2) DEFAULT NULL,
  `id_charger_affaire` int DEFAULT NULL,
  `date_debut_planifiee` date DEFAULT NULL,
  `date_fin_planifiee` date DEFAULT NULL,
  `planning_jours_ouvres` tinyint(1) NOT NULL DEFAULT '1',
  `planning_revision` int unsigned NOT NULL DEFAULT '0',
  `id_personnel_atelier` int DEFAULT NULL,
  `date_prise_en_charge` datetime DEFAULT NULL,
  `prise_en_charge_atelier` tinyint(1) NOT NULL DEFAULT '0',
  `pris_en_charge_par` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_demande_utilisateur` (`idUsers`),
  KEY `fk_demande_passivation` (`id_passivation`),
  KEY `fk_demande_matiere` (`id_matiere`),
  KEY `fk_demande_charger_user` (`id_charger_affaire`),
  KEY `idx_prefa_planning` (`id_statut`,`id_charger_affaire`,`date_debut_planifiee`,`date_fin_planifiee`),
  KEY `fk_prefa_personnel_atelier` (`id_personnel_atelier`),
  KEY `fk_prefa_workshop_taker` (`pris_en_charge_par`),
  KEY `fk_demande_affaire` (`id_affaire`),
  CONSTRAINT `fk_demande_affaire` FOREIGN KEY (`id_affaire`) REFERENCES `affaire` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_demande_charger_user` FOREIGN KEY (`id_charger_affaire`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_demande_matiere` FOREIGN KEY (`id_matiere`) REFERENCES `type_matiere` (`id`),
  CONSTRAINT `fk_demande_passivation` FOREIGN KEY (`id_passivation`) REFERENCES `type_passivation` (`id`),
  CONSTRAINT `fk_demande_statut` FOREIGN KEY (`id_statut`) REFERENCES `statut_demande` (`id`),
  CONSTRAINT `fk_prefa_personnel_atelier` FOREIGN KEY (`id_personnel_atelier`) REFERENCES `Utilisateur` (`id`),
  CONSTRAINT `fk_prefa_workshop_taker` FOREIGN KEY (`pris_en_charge_par`) REFERENCES `Utilisateur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_qmos` (
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` mediumblob NOT NULL,
  PRIMARY KEY (`id_demande`),
  CONSTRAINT `fk_qmos_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_qmos_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `nom` varchar(255) NOT NULL,
  `contenu` mediumblob NOT NULL,
  `sha256` binary(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qmos_documents_demande` (`id_demande`),
  CONSTRAINT `fk_qmos_documents_demande` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `element_atelier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `reference` varchar(80) NOT NULL,
  `libelle` varchar(180) NOT NULL,
  `cree_par` int NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atelier_reference` (`id_demande`,`reference`),
  KEY `cree_par` (`cree_par`),
  CONSTRAINT `element_atelier_ibfk_1` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE,
  CONSTRAINT `element_atelier_ibfk_2` FOREIGN KEY (`cree_par`) REFERENCES `Utilisateur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `element_atelier_document` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_element` int NOT NULL,
  `type_document` enum('plan','qmos','dmos') NOT NULL,
  `cle_document` varchar(255) NOT NULL,
  `pages` smallint unsigned NOT NULL DEFAULT '1',
  `numeros_ligne` json DEFAULT NULL COMMENT 'numéro de ligne du cartouche, par page : {"1":"YU-PRE-101"}',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atelier_document` (`id_element`,`type_document`,`cle_document`),
  CONSTRAINT `element_atelier_document_ibfk_1` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `element_atelier_etapes` (
  `id_element` int NOT NULL,
  `statuts` json NOT NULL,
  `revision` int NOT NULL DEFAULT '1',
  `modifie_par` int NOT NULL,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_element`),
  KEY `modifie_par` (`modifie_par`),
  CONSTRAINT `element_atelier_etapes_ibfk_1` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `element_atelier_etapes_ibfk_2` FOREIGN KEY (`modifie_par`) REFERENCES `Utilisateur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `element_atelier_etapes_historique` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_element` int NOT NULL,
  `statuts` json NOT NULL,
  `revision` int NOT NULL,
  `modifie_par` int NOT NULL,
  `date_saisie` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_etapes_revision` (`id_element`,`revision`),
  KEY `modifie_par` (`modifie_par`),
  CONSTRAINT `element_atelier_etapes_historique_ibfk_1` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `element_atelier_etapes_historique_ibfk_2` FOREIGN KEY (`modifie_par`) REFERENCES `Utilisateur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evenement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(32) NOT NULL COMMENT 'demande.deposee, demande.validee, demande.refusee',
  `id_demande` int DEFAULT NULL COMMENT 'la demande concernée, pour savoir qui a le droit de voir passer l''événement',
  `id_acteur` int DEFAULT NULL COMMENT 'qui l''a provoqué : on ne lui annonce pas ce qu''il vient de faire',
  `id_destinataire` int DEFAULT NULL COMMENT 'destinataire unique ; vide = visible selon la demande',
  `donnees` json DEFAULT NULL COMMENT 'ce qu''il faut pour écrire l''alerte',
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cree_le` (`cree_le`),
  KEY `demande` (`id_demande`),
  KEY `destinataire` (`id_destinataire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametrage_prefabrication` (
  `id` tinyint unsigned NOT NULL,
  `heures_par_pouce` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `duree_heures` smallint unsigned NOT NULL DEFAULT '1',
  `duree_minutes` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_nomenclature` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_element` int NOT NULL,
  `id_document` int DEFAULT NULL,
  `page` smallint unsigned NOT NULL DEFAULT '1',
  `rang` smallint unsigned NOT NULL,
  `pos_x` decimal(7,6) DEFAULT NULL,
  `pos_y` decimal(7,6) DEFAULT NULL,
  `pos_w` decimal(7,6) NOT NULL DEFAULT '0.090000',
  `pos_h` decimal(7,6) NOT NULL DEFAULT '0.018000',
  `quantite` varchar(20) NOT NULL DEFAULT '',
  `dn` varchar(20) NOT NULL DEFAULT '',
  `epaisseur` varchar(20) NOT NULL DEFAULT '',
  `description` varchar(180) NOT NULL DEFAULT '',
  `numero_coulee` varchar(20) NOT NULL DEFAULT '',
  `id_coulee` bigint unsigned DEFAULT NULL,
  `saisie_par` int DEFAULT NULL,
  `date_saisie` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomenclature_rang_par_page` (`id_element`,`id_document`,`page`,`rang`),
  KEY `idx_nomenclature_coulee` (`id_coulee`),
  KEY `fk_nomenclature_saisie` (`saisie_par`),
  KEY `fk_plan_nomenclature_document` (`id_document`),
  CONSTRAINT `fk_nomenclature_coulee` FOREIGN KEY (`id_coulee`) REFERENCES `demande_ccpu_coulees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nomenclature_element` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomenclature_saisie` FOREIGN KEY (`saisie_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_plan_nomenclature_document` FOREIGN KEY (`id_document`) REFERENCES `element_atelier_document` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_soudure` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_element` int NOT NULL,
  `id_document` int DEFAULT NULL,
  `page` smallint unsigned NOT NULL DEFAULT '1',
  `numero` varchar(20) NOT NULL,
  `id_soudeur` int DEFAULT NULL,
  `id_pointage` int DEFAULT NULL,
  `date_soudure` date DEFAULT NULL,
  `type_soudure` varchar(30) NOT NULL DEFAULT '',
  `rt` varchar(20) NOT NULL DEFAULT '',
  `vi` varchar(20) NOT NULL DEFAULT '',
  `pt` varchar(20) NOT NULL DEFAULT '',
  `vise_par` int DEFAULT NULL,
  `date_visa` datetime DEFAULT NULL,
  `saisie_par` int DEFAULT NULL,
  `date_saisie` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `soudure_par_pointage` (`id_pointage`),
  UNIQUE KEY `soudure_numero_par_page` (`id_element`,`id_document`,`page`,`numero`),
  KEY `idx_soudure_soudeur` (`id_soudeur`),
  KEY `fk_soudure_visa` (`vise_par`),
  KEY `fk_soudure_saisie` (`saisie_par`),
  KEY `fk_plan_soudure_document` (`id_document`),
  CONSTRAINT `fk_plan_soudure_document` FOREIGN KEY (`id_document`) REFERENCES `element_atelier_document` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_soudure_element` FOREIGN KEY (`id_element`) REFERENCES `element_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_soudure_pointage` FOREIGN KEY (`id_pointage`) REFERENCES `pointage_atelier` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_soudure_saisie` FOREIGN KEY (`saisie_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_soudure_soudeur` FOREIGN KEY (`id_soudeur`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_soudure_visa` FOREIGN KEY (`vise_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pointage_atelier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_affectation` int NOT NULL,
  `id_document` int DEFAULT NULL,
  `page` smallint unsigned NOT NULL DEFAULT '1',
  `id_utilisateur` int NOT NULL,
  `date_travail` date NOT NULL,
  `duree_minutes` smallint unsigned NOT NULL,
  `type_soudure` varchar(30) NOT NULL DEFAULT '',
  `commentaire` varchar(500) DEFAULT NULL,
  `cle_saisie` char(32) NOT NULL,
  `date_saisie` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atelier_pointage_saisie` (`id_utilisateur`,`cle_saisie`),
  KEY `id_affectation` (`id_affectation`),
  KEY `fk_pointage_document` (`id_document`),
  CONSTRAINT `fk_pointage_document` FOREIGN KEY (`id_document`) REFERENCES `element_atelier_document` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pointage_atelier_ibfk_1` FOREIGN KEY (`id_affectation`) REFERENCES `affectation_atelier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pointage_atelier_ibfk_2` FOREIGN KEY (`id_utilisateur`) REFERENCES `Utilisateur` (`id`),
  CONSTRAINT `pointage_atelier_chk_1` CHECK (((`duree_minutes` > 0) and (`duree_minutes` <= 1440)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prefa_reference` (
  `id_demande` int NOT NULL,
  `annee` smallint unsigned NOT NULL,
  `numero` int unsigned NOT NULL,
  `reference` varchar(24) NOT NULL,
  PRIMARY KEY (`id_demande`),
  UNIQUE KEY `reference` (`reference`),
  UNIQUE KEY `reference_annuelle` (`annee`,`numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prefa_reference_compteur` (
  `annee` smallint unsigned NOT NULL,
  `dernier_numero` int unsigned NOT NULL,
  PRIMARY KEY (`annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pv_document` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_pv` int NOT NULL,
  `cle` varchar(255) NOT NULL,
  `nom` varchar(190) NOT NULL,
  `categorie` enum('document','photo') NOT NULL DEFAULT 'document',
  `taille` int unsigned NOT NULL DEFAULT '0',
  `depose_par` int DEFAULT NULL,
  `date_depot` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pv_document_cle` (`id_pv`,`cle`),
  KEY `pv_document_auteur_fk` (`depose_par`),
  CONSTRAINT `pv_document_auteur_fk` FOREIGN KEY (`depose_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pv_document_pv_fk` FOREIGN KEY (`id_pv`) REFERENCES `pv_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pv_prefabrication` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_demande` int NOT NULL,
  `type` enum('reception_materiel','fin_fabrication','conformite','passivation') NOT NULL,
  `statut` enum('a_faire','fait','sans_objet') NOT NULL DEFAULT 'a_faire',
  `date_pv` date DEFAULT NULL,
  `commentaire` varchar(1000) DEFAULT NULL,
  `maj_par` int DEFAULT NULL,
  `date_maj` datetime DEFAULT NULL,
  `revision` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `pv_demande_type` (`id_demande`,`type`),
  KEY `pv_auteur_fk` (`maj_par`),
  CONSTRAINT `pv_auteur_fk` FOREIGN KEY (`maj_par`) REFERENCES `Utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pv_demande_fk` FOREIGN KEY (`id_demande`) REFERENCES `demande_prefabrication` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revetements` (
  `id` int NOT NULL,
  `libelle` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `statut_demande` (
  `id` int NOT NULL,
  `libelle` varchar(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `type_matiere` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `type_passivation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


--
-- Donnees de reference
--

INSERT INTO `role` (`id`, `name`) VALUES (1,'Administrateur'),(2,'Gestionnaire'),(3,'Utilisateur'),(5,'Demandeur');
INSERT INTO `statut_demande` (`id`, `libelle`) VALUES (1,'En attente'),(3,'Refusée'),(2,'Validée');
INSERT INTO `parametrage_prefabrication` (`id`, `heures_par_pouce`, `duree_heures`, `duree_minutes`) VALUES (1,24.0000,24,0);
