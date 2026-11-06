<?php
/**
 * La commande d'un bon en classeur Excel, aux couleurs de la maison : de quoi l'envoyer,
 * l'imprimer ou la pointer ailleurs qu'à l'écran.
 *
 * Une feuille : le logo, la commande en titre, la demande dessous ; une carte de
 * propriétés — numéro, dates, validation, réception ; puis le tableau des articles, rangés
 * comme dans le volet — par date de livraison —, avec leur état, quand et par qui ils ont
 * été reçus. L'en-tête du tableau reste à l'écran et porte les filtres du tableur ; les
 * dates sont de vraies dates, qui se trient.
 */
require_once __DIR__ . '/../vue/classeur.php';
require_once __DIR__ . '/materiel_bon.php';

/**
 * @param array<string, mixed> $document tel que materielDocument() le rend
 * @param string $demande « 26-DP-0001 - Réseau vapeur »
 * @param string $exportePar le nom de qui télécharge
 */
function materielClasseur(array $document, string $demande, string $exportePar): string
{
    $lignes = $document['lignes'] ?? [];
    // Rangées comme la réception : par date de livraison, les articles sans date à la fin.
    usort($lignes, static fn (array $a, array $b): int =>
        [empty($a['date_livraison']), (string) $a['date_livraison'], (int) $a['rang']]
        <=> [empty($b['date_livraison']), (string) $b['date_livraison'], (int) $b['rang']]);

    $numero = trim((string) ($document['numero_commande'] ?? ''));
    ['recus' => $recus, 'total' => $total] = materielRecus($document);
    $retards = materielRetards($document);

    $classeur = new Classeur('Commande');
    $classeur->titre(($numero !== '' ? 'Commande ' . $numero : 'Bon de commande') . ' — ' . $demande);
    $classeur->pied('IDEM Préfabrication · ' . ($numero !== '' ? 'Commande ' . $numero : 'Bon de commande') . ' · ' . $demande);
    // Référence, désignation, quantité, unité, livraison, état, reçu le, reçu par.
    $classeur->largeurs([18, 46, 10, 9, 18, 14, 12, 24]);
    $colonnes = 8;

    // Le logo, puis le titre souligné de l'accent, puis la demande.
    $classeur->hauteur(1, 44);
    $logo = __DIR__ . '/../../../assets/logo-idem.png';
    // Le mot seul, comme en tête de la barre de l'application (aspect-ratio 470 / 213) : le
    // dessin complet continue à droite, sur 1 403 pixels.
    if (is_file($logo)) $classeur->logo((string) file_get_contents($logo), 50, 1 - 470 / 1403);

    $classeur->hauteur(2, 32);
    $classeur->texte(2, 1, $numero !== '' ? 'Commande ' . $numero : 'Bon de commande', 'titre');
    for ($colonne = 2; $colonne <= $colonnes; $colonne++) $classeur->vide(2, $colonne, 'titre');
    $classeur->fusionner(2, 1, $colonnes);

    $classeur->hauteur(3, 24);
    $classeur->texte(3, 1, 'Demande ' . $demande, 'sous-titre');
    $classeur->fusionner(3, 1, $colonnes);
    $classeur->hauteur(4, 8);

    // La carte des propriétés : deux colonnes d'étiquettes et de valeurs.
    $validation = !empty($document['date_verification'])
        ? 'Validée le ' . prefaFormatDate($document['date_verification'])
            . ' par ' . (trim((string) ($document['verificateur'] ?? '')) ?: 'l’encadrement')
        : (materielEtat($document) === 'echec' ? 'Lecture impossible' : 'À vérifier, pas encore validée');
    $reception = $total
        ? $recus . ' sur ' . $total . ' reçu' . ($recus > 1 ? 's' : '') . ($retards ? ' · ' . $retards . ' en retard' : '')
        : 'Aucun article';
    $proprietes = [
        ['N° de commande', $numero !== '' ? $numero : '—', 'Date de commande',
            !empty($document['date_commande']) ? prefaFormatDate($document['date_commande']) : '—'],
        ['Bon déposé', (string) $document['nom'], 'Livraison', materielEcheancesTexte($document) ?: '—'],
        ['Validation', $validation, 'Réception', $reception],
    ];
    foreach ($proprietes as $rang => [$etiquette, $valeur, $etiquette2, $valeur2]) {
        $ligne = 5 + $rang;
        $classeur->hauteur($ligne, 22);
        $classeur->texte($ligne, 1, $etiquette, 'etiquette');
        $classeur->texte($ligne, 2, $valeur, $rang === 0 ? 'valeur-accent' : 'valeur');
        $classeur->vide($ligne, 3, 'valeur');
        $classeur->vide($ligne, 4, 'valeur');
        $classeur->fusionner($ligne, 2, 4);
        $classeur->texte($ligne, 5, $etiquette2, 'etiquette');
        $classeur->texte($ligne, 6, $valeur2, 'valeur');
        for ($colonne = 7; $colonne <= $colonnes; $colonne++) $classeur->vide($ligne, $colonne, 'valeur');
        $classeur->fusionner($ligne, 6, $colonnes);
    }
    $classeur->hauteur(8, 12);

    // Le tableau des articles.
    $entete = 9;
    $titres = [
        ['Référence', 'entete'], ['Désignation', 'entete'], ['Qté', 'entete-droite'], ['Unité', 'entete'],
        ['Livraison prévue', 'entete-centre'], ['État', 'entete-centre'], ['Reçu le', 'entete-centre'], ['Reçu par', 'entete'],
    ];
    $classeur->hauteur($entete, 26);
    foreach ($titres as $rang => [$titre, $style]) $classeur->texte($entete, $rang + 1, $titre, $style);

    $ligne = $entete;
    foreach ($lignes as $article) {
        $ligne++;
        $designation = (string) $article['designation'];
        // Une désignation longue revient à la ligne : la rangée grandit d'autant.
        $rangees = max(1, (int) ceil(mb_strlen($designation) / 52));
        $classeur->hauteur($ligne, 21 + 13 * ($rangees - 1));
        $recu = !empty($article['recu']);

        $classeur->texte($ligne, 1, (string) ($article['reference'] ?? '') ?: '—', 'texte-sourd');
        $classeur->texte($ligne, 2, $designation, 'texte');
        $classeur->nombre($ligne, 3, $article['quantite'], 'nombre');
        $classeur->texte($ligne, 4, (string) $article['unite'], 'texte-sourd');
        $classeur->date($ligne, 5, $article['date_livraison'] ?? null, 'date');
        [$etat, $style] = $recu ? ['Reçu', 'etat-recu']
            : (materielEnRetard($article) ? ['En retard', 'etat-retard'] : ['À recevoir', 'etat-attente']);
        $classeur->texte($ligne, 6, $etat, $style);
        $classeur->date($ligne, 7, $recu ? ($article['date_reception'] ?? null) : null, 'date-sourde');
        $classeur->texte($ligne, 8, $recu ? (string) ($article['receptionnaire'] ?? '') : '', 'texte-sourd');
    }

    if (!$lignes) {
        $ligne++;
        $classeur->hauteur($ligne, 24);
        $classeur->texte($ligne, 1, 'Aucun article sur ce bon.', 'vide-ligne');
        for ($colonne = 2; $colonne <= $colonnes; $colonne++) $classeur->vide($ligne, $colonne, 'vide-ligne');
        $classeur->fusionner($ligne, 1, $colonnes);
    } else {
        $classeur->filtre($entete, $ligne, $colonnes);
    }

    // Le total, sur le fond des groupes.
    $ligne++;
    $classeur->hauteur($ligne, 24);
    $classeur->texte($ligne, 1, 'Total', 'total');
    $classeur->texte($ligne, 2, $total . ' article' . ($total > 1 ? 's' : '')
        . ' · ' . $recus . ' reçu' . ($recus > 1 ? 's' : '')
        . ' · ' . ($total - $recus) . ' à recevoir'
        . ($retards ? ' · ' . $retards . ' en retard' : ''), 'total');
    for ($colonne = 3; $colonne <= $colonnes; $colonne++) $classeur->vide($ligne, $colonne, 'total');
    $classeur->fusionner($ligne, 2, $colonnes);

    $ligne += 2;
    $maintenant = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    $classeur->texte($ligne, 1, 'Exporté le ' . $maintenant->format('d/m/Y') . ' à ' . $maintenant->format('H:i')
        . ($exportePar !== '' ? ' par ' . $exportePar : '') . ' · IDEM Préfabrication', 'note');
    $classeur->fusionner($ligne, 1, $colonnes);

    $classeur->figer($entete);
    $classeur->repeter($entete);
    return $classeur->xlsx();
}

/** Le nom du fichier : « Commande_CMD-2026-114_26-DP-0001.xlsx », sans accent ni espace. */
function materielNomClasseur(array $document, string $reference): string
{
    $numero = trim((string) ($document['numero_commande'] ?? ''));
    $nom = 'Commande_' . ($numero !== '' ? $numero : 'bon-' . (int) $document['id']) . '_' . $reference;
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom) : false;
    $nom = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', is_string($ascii) && $ascii !== '' ? $ascii : $nom), '_');
    return mb_substr($nom !== '' ? $nom : 'Commande', 0, 120) . '.xlsx';
}
