<?php

require_once __DIR__ . '/../materiel/manques.php';
require_once __DIR__ . '/../materiel/unites.php';
require_once __DIR__ . '/../demande/prefa_references.php';
require_once __DIR__ . '/liste_filtrable.php';

/**
 * Le tableau des manques qu'une personne a signalés, tel que « Mes soudures » l'affiche.
 *
 * Rendu ici, et non dans la page : l'action qui enregistre un signalement renvoie le même
 * tableau, et l'écran le remplace sans se recharger. Deux copies du balisage auraient fini
 * par ne plus dire la même chose.
 *
 * @param list<array<string, mixed>> $manques ce que rend manquesDuSoudeur()
 */
function manquesSoudeurRendu(array $manques): string
{
    if (!$manques) {
        return '<p class="soudures-materiel-vide">Aucun manque signalé. Si une pièce vous manque pour souder,'
            . ' utilisez « Signaler un manque » : le chef d’atelier est prévenu tout de suite.</p>';
    }

    $motifs = manquesMotifs();
    $etats = manquesEtats();
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $html = '<div class="prefa-table-scroll" role="region" aria-label="Matériel signalé" tabindex="0">'
        . '<table class="prefa-table soudures-materiel-table"><thead><tr>'
        . '<th scope="col">Article</th>'
        . '<th scope="col" class="prefa-nombre">Qté</th>'
        . '<th scope="col">Motif</th>'
        . '<th scope="col">Demande</th>'
        . '<th scope="col">Signalé le</th>'
        . '<th scope="col">État</th>'
        . '</tr></thead><tbody>';
    foreach ($manques as $manque) {
        $etat = $etats[$manque['statut']] ?? $etats['nouvelle'];
        // Qui l'a traité et quand : c'est la réponse qu'on attend, au survol de la puce.
        $titre = $manque['statut'] === 'nouvelle'
            ? 'Pas encore vu par le chef d’atelier'
            : ($manque['statut'] === 'vue' ? 'Vu' : 'Traité')
                . ($manque['traitant'] ? ' par ' . $manque['traitant'] : '')
                . ($manque['date_traitement'] ? ' le ' . date('d/m/Y', strtotime((string) $manque['date_traitement'])) : '');
        $html .= '<tr>'
            . '<td class="soudures-materiel-article"><strong>' . $e($manque['designation']) . '</strong>'
            . ($manque['commentaire'] ? '<span>' . $e($manque['commentaire']) . '</span>' : '') . '</td>'
            . '<td class="prefa-nombre">' . $e(manqueQuantite($manque['quantite']) . ' ' . $manque['unite']) . '</td>'
            . '<td>' . $e($motifs[$manque['motif']] ?? $manque['motif']) . '</td>'
            . '<td class="prefa-reference">' . $e(prefaReference((int) $manque['id_demande'])) . '</td>'
            . '<td>' . $e(date('d/m/Y', strtotime((string) $manque['date_creation']))) . '</td>'
            . '<td><span class="pv-puce ' . $etat['puce'] . '" title="' . $e($titre) . '">' . $e($etat['libelle']) . '</span></td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/**
 * « Signaler un manque » : la fenêtre de la maison. Le plan est celui qu'on regarde, et
 * depuis l'en-tête on le choisit ; l'article se choisit parmi ceux du bon de commande de sa
 * demande, ou s'écrit.
 *
 * Les listes sont celles de la maison, pas celles du navigateur : on tape pour chercher, on
 * choisit au clavier ou à la souris — comme le chargé d'affaire de « Nouvelle affaire ». Le
 * mode sans étiquette : la fenêtre n'en porte pas, l'invite dit ce qu'on attend.
 *
 * @param array<int, array{element:int, reference:string, demande:int}> $plans les plans de la
 *        personne ; s'il n'y en a qu'un, il est posé d'office — sinon on le choisit
 * @param array<int, list<array{designation:string}>> $articlesParPlan les articles des bons
 *        de commande, par plan : le script refait la liste quand on change de plan
 * @param list<array{libelle:string}> $unites la liste du chargé de production (Paramètres →
 *        Matériel) ; vide — pas encore créée sur ce serveur, ou vidée —, l'unité s'écrit
 */
function manqueFenetreRendu(array $plans, array $articlesParPlan, array $unites): string
{
    $planUnique = count($plans) === 1 ? array_key_first($plans) : null;
    ob_start();
    ?>
<dialog class="fenetre" id="soudures-manque" aria-labelledby="soudures-manque-titre"
        data-articles="<?= prefaEscape(json_encode($articlesParPlan, JSON_UNESCAPED_UNICODE)) ?>">
    <form method="dialog" id="soudures-manque-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
        <div class="fenetre-entete">
            <h2 id="soudures-manque-titre">Signaler un manque</h2>
            <button type="button" class="modal-close" data-fermer-manque aria-label="Fermer">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>
        <p class="fenetre-sous-titre">Le chef d’atelier est prévenu tout de suite.</p>
        <div class="fenetre-corps">
            <?php if (count($plans) > 1): ?>
                <?= listeFiltrableRendu('manque-plan', 'Plan', array_map(static fn (array $choix): array => [
                    'id' => $choix['element'],
                    'libelle' => $choix['reference'] . ' · ' . prefaReference($choix['demande']),
                ], array_values($plans)), null, [
                    'nom' => 'element',
                    'aucun' => 'Aucun plan à ce nom.',
                    'chercher' => 'Plan',
                    'tableau' => true,
                ]) ?>
            <?php else: ?>
                <input type="hidden" name="element" value="<?= (int) $planUnique ?>">
            <?php endif; ?>

            <?= listeFiltrableRendu('manque-article', 'Article', array_map(static fn (array $article): array => [
                'id' => $article['designation'],
                'libelle' => $article['designation'],
            ], $articlesParPlan[$planUnique] ?? []), null, [
                'nom' => 'designation',
                'autre' => 'Autre article…',
                'aucun' => 'Pas sur le bon de commande : choisissez « Autre article ».',
                'chercher' => 'Article qui manque',
                'tableau' => true,
            ]) ?>
            <div id="manque-article-autre-field" class="soudures-manque-autre" hidden>
                <input id="manque-article-autre" class="fenetre-champ" name="designation_autre" maxlength="180"
                       autocomplete="off" placeholder="Article qui manque — bride DN40, tube Ø48,3…" aria-label="Article qui manque">
            </div>

            <div class="soudures-manque-quantite">
                <input class="fenetre-champ" name="quantite" inputmode="decimal" placeholder="Quantité" aria-label="Quantité">
                <?php if ($unites): ?>
                    <?= listeFiltrableRendu('manque-unite', 'Unité', array_map(static fn (array $unite): array => [
                        'id' => $unite['libelle'],
                        'libelle' => $unite['libelle'],
                    ], $unites), null, [
                        'nom' => 'unite',
                        'aucun' => 'Pas dans la liste : demandez au chargé de production de l’ajouter.',
                        'chercher' => 'Unité',
                        'tableau' => true,
                    ]) ?>
                <?php else: ?>
                    <input class="fenetre-champ" name="unite" maxlength="<?= UNITE_LONGUEUR ?>" placeholder="Unité (u, m, kg…)" aria-label="Unité">
                <?php endif; ?>
            </div>

            <?= listeFiltrableRendu('manque-motif', 'Motif', array_map(static fn (string $cle, string $libelle): array => [
                'id' => $cle, 'libelle' => $libelle,
            ], array_keys(manquesMotifs()), manquesMotifs()), null, [
                'nom' => 'motif',
                'aucun' => 'Aucun motif ne correspond.',
                'chercher' => 'Motif du manque',
                'tableau' => true,
            ]) ?>
            <textarea class="fenetre-champ" name="commentaire" maxlength="1000" rows="3"
                      placeholder="Précision pour le chef (facultatif) — repère, longueur, urgence…" aria-label="Commentaire"></textarea>
            <p class="fenetre-message" role="status" aria-live="polite"></p>
        </div>
        <div class="fenetre-pied">
            <button type="button" class="fenetre-annuler" data-fermer-manque>Annuler</button>
            <button type="submit" class="fenetre-valider">Signaler</button>
        </div>
    </form>
</dialog>
<?php
    return ob_get_clean();
}
