<?php

require_once __DIR__ . '/pdf.php';

/**
 * Feuille de plan intelligent, A4 paysage, reprenant la disposition des ISO de l'atelier :
 * le dessin occupe toute la partie gauche, et une colonne à droite empile de haut en bas
 * la nomenclature de matériel, la liste des soudures et le cartouche.
 *
 * Le schéma du tube n'est pas redessiné : sa zone reste réservée. Le dessin vit dans le
 * PDF d'origine, que cette feuille accompagne.
 *
 * @param array{projet?:string, ligne?:string, dessine_par?:string, approuve_par?:string,
 *              date?:string, indice?:string, reference?:string, tuyauteur?:string} $entete
 * @param list<array{n?:string, qte?:string, dn?:string, ep?:string, description?:string, coulee?:string}> $nomenclature
 * @param list<array{n?:string, soudeur?:string, date?:string, type?:string, rt?:string, vi?:string, pt?:string, visa?:string}> $soudures
 */
function planIsoPdf(array $entete, array $nomenclature, array $soudures): string
{
    $pdf = new PdfPlan(297, 210);

    $marge = 8.0;
    $droite = 297 - $marge;
    $bas = 210 - $marge;
    $colonne = $droite - 115.0;

    $pdf->cadre($marge, $marge, $droite - $marge, $bas - $marge, 0.35);

    // Trois boîtes fermées le long du bord droit, séparées par un espace. Sans cet
    // espace leurs bordures gauches se rejoignent et redessinent un trait continu qui
    // coupe la page en deux.
    $basNomenclature = planIsoNomenclature($pdf, $colonne, $marge, $droite, $nomenclature);
    planIsoListeSoudure($pdf, $colonne, $basNomenclature + 6.0, $droite, $entete, $soudures);

    planIsoCartouche($pdf, $colonne, $bas - 14.0, $droite, $bas, $entete);

    return $pdf->rendu();
}

/** @return float le bas du bloc, pour empiler le suivant dessous */
function planIsoNomenclature(PdfPlan $pdf, float $gauche, float $haut, float $droite, array $nomenclature): float
{
    $largeur = $droite - $gauche;
    $pdf->texteCentre('NOMENCLATURE DE MATÉRIEL', $gauche, $largeur, $haut + 5.5, 8, true);
    $pdf->ligne($gauche, $haut + 7, $droite, $haut + 7, 0.25);

    // La description porte les libellés longs, mais DN et EP gardent de quoi rester
    // lisibles : la taille vaut pour toute la colonne, donc une seule valeur trop longue
    // rapetisserait les autres avec elle.
    $colonnes = [
        ['N°', 7.0], ['QTE', 13.0], ['DN', 12.0], ['EP', 10.0],
        ['DESCRIPTION', 50.0], ['N° COULÉE', 23.0],
    ];
    $lignes = array_map(
        static fn (array $ligne): array => [
            (string) ($ligne['n'] ?? ''), (string) ($ligne['qte'] ?? ''),
            (string) ($ligne['dn'] ?? ''), (string) ($ligne['ep'] ?? ''),
            (string) ($ligne['description'] ?? ''), (string) ($ligne['coulee'] ?? ''),
        ],
        $nomenclature
    );
    // Au moins dix lignes : une nomenclature courte laisse de la place pour compléter.
    $nombre = max(10, count($lignes));
    $bas = $haut + 7 + 6 + $nombre * 5.0;
    planIsoTableau($pdf, $gauche, $haut + 7, $bas, $largeur, $colonnes, $lignes, 4);
    // La boîte se ferme à gauche et en bas : le titre est encadré comme le tableau.
    $pdf->ligne($gauche, $haut, $gauche, $bas, 0.35);
    $pdf->ligne($gauche, $bas, $droite, $bas, 0.35);
    return $bas;
}

function planIsoListeSoudure(PdfPlan $pdf, float $gauche, float $haut, float $droite, array $entete, array $soudures): void
{
    $largeur = $droite - $gauche;

    $pdf->ligne($gauche, $haut, $droite, $haut, 0.35);
    $pdf->texteCentre('LISTE SOUDURE', $gauche, $largeur, $haut + 5.5, 8, true);
    $pdf->ligne($gauche, $haut + 7, $droite, $haut + 7, 0.25);

    // Entre le titre et le tableau : qui a monté la tuyauterie, et le visa du travail.
    // Ils portent sur la pièce entière, pas sur une soudure, d'où la bande à part.
    $hautTableau = planIsoBandeTuyauteur($pdf, $gauche, $haut + 7, $droite, $entete);

    // RT, VT et PT doivent loger « Non OK » : 8 mm n'y suffisaient pas. VT et non VI : c'est
    // l'en-tête que le bureau d'études imprime sur ses listes soudure.
    $colonnes = [
        ['N°', 7.0], ['NOM DU SOUDEUR', 24.0], ['DATE', 15.0], ['TYPE DE SOUDURE', 17.0],
        ['RT', 11.5], ['VT', 11.5], ['PT', 11.5], ['VISA', 17.5],
    ];
    // Huit lignes, comme sur le plan papier : le tableau ne s'étire pas jusqu'au cartouche,
    // ce qui éviterait une dizaine de lignes vides inutilement encadrées.
    $lignes = array_map(
        static fn (array $ligne): array => [
            (string) ($ligne['n'] ?? ''), (string) ($ligne['soudeur'] ?? ''),
            (string) ($ligne['date'] ?? ''), (string) ($ligne['type'] ?? ''),
            (string) ($ligne['rt'] ?? ''), (string) ($ligne['vi'] ?? ''),
            (string) ($ligne['pt'] ?? ''), (string) ($ligne['visa'] ?? ''),
        ],
        $soudures
    );
    $nombre = max(8, count($lignes));
    $bas = $hautTableau + 6 + $nombre * 5.0;
    planIsoTableau($pdf, $gauche, $hautTableau, $bas, $largeur, $colonnes, $lignes, 1);

    $pdf->ligne($gauche, $haut, $gauche, $bas, 0.35);
    $pdf->ligne($gauche, $bas, $droite, $bas, 0.35);
}

/**
 * La bande « Nom du tuyauteur / Visa ». Chaque libellé a sa case, fermée à droite,
 * et la case voisine reçoit la valeur : c'est la trame du plan papier, où l'atelier
 * écrit au stylo dans la case d'à côté.
 *
 * @return float le bas de la bande
 */
function planIsoBandeTuyauteur(PdfPlan $pdf, float $gauche, float $haut, float $droite, array $entete): float
{
    $bas = $haut + 9.0;
    $cases = [
        ['NOM DU TUYAUTEUR :', 30.0, false],
        [(string) ($entete['tuyauteur'] ?? ''), 55.0, true],
        ['VISA :', 12.0, false],
        ['', 18.0, true],
    ];

    $x = $gauche;
    foreach ($cases as [$texte, $largeurCase, $estValeur]) {
        if ($x > $gauche) $pdf->ligne($x, $haut, $x, $bas, 0.25);
        if ($texte !== '') {
            $corps = planIsoCorps($pdf, $texte, $largeurCase - 4, $estValeur ? 8.5 : 6.0, $estValeur);
            $pdf->texte($texte, $x + 2.5, $haut + 6, $corps, $estValeur);
        }
        $x += $largeurCase;
    }
    $pdf->ligne($gauche, $bas, $droite, $bas, 0.25);
    return $bas;
}

/**
 * Cartouche en pied de colonne, sur une seule bande : le chantier, le numéro de ligne,
 * qui a demandé la préfabrication, qui l'a validée, et la date de cette validation.
 *
 * « Dessiné par » et « Approuvé par » ne sont pas saisis sur le plan : ils viennent de
 * la demande, où le demandeur et le valideur sont déjà enregistrés.
 */
function planIsoCartouche(PdfPlan $pdf, float $gauche, float $haut, float $droite, float $bas, array $entete): void
{
    $champs = [
        ['Nom du chantier', (string) ($entete['projet'] ?? ''), 32.0],
        ['Numéro de ligne', (string) ($entete['ligne'] ?? ''), 21.0],
        ['Dessiné par', (string) ($entete['dessine_par'] ?? ''), 24.0],
        ['Approuvé par', (string) ($entete['approuve_par'] ?? ''), 23.0],
        ['Date', (string) ($entete['date'] ?? ''), 15.0],
    ];

    $pdf->ligne($gauche, $haut, $droite, $haut, 0.35);
    $pdf->ligne($gauche, $haut, $gauche, $bas, 0.35);

    $x = $gauche;
    foreach ($champs as [$libelle, $valeur, $largeurChamp]) {
        if ($x > $gauche) $pdf->ligne($x, $haut, $x, $bas, 0.25);
        $pdf->texte($libelle, $x + 2, $haut + 4.5, 6);
        // Un nom de chantier long rapetisse plutôt que de déborder sur la case voisine.
        $corps = planIsoCorps($pdf, $valeur, $largeurChamp - 4, 8.5, true);
        $pdf->texte($valeur, $x + 2, $haut + 10.5, $corps, true);
        $x += $largeurChamp;
    }
}

/**
 * Tableau à en-tête, calé pour remplir exactement la hauteur demandée.
 *
 * Les lignes vides sont tracées quand même : la feuille doit pouvoir se compléter au
 * stylo quand l'atelier n'a pas d'écran sous la main.
 *
 * @param list<array{0:string, 1:float}> $colonnes libellé et largeur en millimètres
 * @param list<list<string>> $lignes
 * @param int $colonneTexte index de la colonne alignée à gauche (les autres sont centrées)
 */
function planIsoTableau(PdfPlan $pdf, float $gauche, float $haut, float $bas, float $largeur, array $colonnes, array $lignes, int $colonneTexte): void
{
    $hauteurEnTete = 6.0;
    $disponible = $bas - $haut - $hauteurEnTete;
    $nombre = max(count($lignes), (int) floor($disponible / 5.2));
    $hauteurLigne = $disponible / max(1, $nombre);

    // Une taille par colonne, et non par case : la plus petite qui convienne à toutes
    // ses valeurs. Sinon « 200 » et « 456456 » sortent dans deux corps différents sur
    // la même colonne, et le tableau donne l'impression d'être déréglé.
    $corpsColonne = [];
    foreach ($colonnes as $index => [, $largeurColonne]) {
        $corps = 7.0;
        foreach ($lignes as $ligne) {
            $valeur = (string) ($ligne[$index] ?? '');
            if ($valeur !== '') {
                $corps = min($corps, planIsoCorps($pdf, $valeur, $largeurColonne - 3, 7.0, false));
            }
        }
        $corpsColonne[$index] = $corps;
    }

    $x = $gauche;
    foreach ($colonnes as [$libelle, $largeurColonne]) {
        $pdf->texteCentre($libelle, $x, $largeurColonne, $haut + 4.2, planIsoCorps($pdf, $libelle, $largeurColonne - 1.5, 6.0, true), true);
        if ($x > $gauche) $pdf->ligne($x, $haut, $x, $bas, 0.25);
        $x += $largeurColonne;
    }
    $pdf->ligne($gauche, $haut + $hauteurEnTete, $gauche + $largeur, $haut + $hauteurEnTete, 0.25);

    for ($rang = 0; $rang < $nombre; $rang++) {
        $y = $haut + $hauteurEnTete + ($rang + 1) * $hauteurLigne;
        // La dernière ligne est tracée elle aussi : le tableau ferme son propre bas,
        // sans quoi les traits de colonnes dépassent dans le vide sous le tableau.
        $pdf->ligne($gauche, $y, $gauche + $largeur, $y, $rang === $nombre - 1 ? 0.25 : 0.15);

        $x = $gauche;
        foreach ($colonnes as $index => [, $largeurColonne]) {
            $valeur = $lignes[$rang][$index] ?? '';
            if ($valeur !== '') {
                // Un libellé trop long rapetisse plutôt que de se faire couper : sur une
                // nomenclature, « - 316L » amputé en « - 31 » change la nuance de l'acier.
                $corps = $corpsColonne[$index];
                if ($index === $colonneTexte) {
                    $pdf->texte($valeur, $x + 1.5, $y - 1.5, $corps);
                } else {
                    $pdf->texteCentre($valeur, $x, $largeurColonne, $y - 1.5, $corps);
                }
            }
            $x += $largeurColonne;
        }
    }
}

/** Plus grand corps, à 0,25 près, qui tient dans la largeur donnée. */
function planIsoCorps(PdfPlan $pdf, string $texte, float $largeurMax, float $corps, bool $gras): float
{
    while ($corps > 3.5 && $pdf->largeurTexte($texte, $corps, $gras) > $largeurMax) {
        $corps -= 0.25;
    }
    return $corps;
}
