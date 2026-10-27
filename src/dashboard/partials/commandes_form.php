<?php

/**
 * Les commandes d'une demande, éditables dans le PV « Réception matériel ».
 *
 * Le bon de commande est déjà déposé en PDF juste en dessous ; cette liste en reprend ce
 * qu'on a besoin de lire sans rouvrir le document. Les champs sont nommés en tableaux
 * parallèles — commande[numero][] — et partent avec le reste du formulaire : une seule
 * sauvegarde pour le PV et ses commandes.
 *
 * @var list<array<string, mixed>> $commandes
 * @var string $cle
 */
$etats = commandeEtats();

/*
 * Une commande, en carte de deux lignes : numéro, fournisseur et état en haut ; livraison
 * et lieu dessous. Ce n'est plus un <table> : le volet vit lui-même dans une cellule du
 * tableau des demandes, et toutes les règles de ses cellules — largeur bornée, fond forcé
 * en thème clair — retombaient sur celles-ci. La même fonction dessine les lignes
 * existantes et le gabarit d'une ligne neuve : écrit deux fois, le balisage divergeait.
 */
$ligneCommande = static function (array $commande) use ($etats): void {
    $date2 = (string) ($commande['date_livraison_2'] ?? '');
    $articles = $commande['articles'] ?? [];
    $statut = (string) ($commande['statut'] ?? '');
    ?>
    <div class="commande-ligne">
        <div class="commande-champ commande-c-numero">
            <input type="hidden" name="commande[id][]" value="<?= isset($commande['id']) ? (int) $commande['id'] : '' ?>">
            <input name="commande[numero][]" value="<?= prefaEscape((string) ($commande['numero'] ?? '')) ?>" maxlength="40" aria-label="Numéro de commande" placeholder="N° de commande" title="Numéro de commande">
        </div>
        <div class="commande-champ commande-c-fournisseur">
            <input name="commande[fournisseur][]" value="<?= prefaEscape((string) ($commande['fournisseur'] ?? '')) ?>" maxlength="120" aria-label="Fournisseur" placeholder="Fournisseur" title="Fournisseur">
        </div>
        <div class="commande-champ commande-c-etat">
            <select name="commande[statut][]" aria-label="État de la commande">
                <?php foreach ($etats as $valeur => $texte): ?>
                    <option value="<?= prefaEscape($valeur) ?>" <?= $statut === $valeur ? 'selected' : '' ?>><?= prefaEscape($texte) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="commande-champ commande-c-retirer">
            <button type="button" class="commande-retirer bouton-discret" aria-label="Retirer cette commande" title="Retirer">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>
        <div class="commande-champ commande-c-livraison commande-livraison<?= $date2 !== '' ? ' est-depliee' : '' ?>">
            <input name="commande[date_bl][]" type="date" value="<?= prefaEscape((string) ($commande['date_bl'] ?? '')) ?>" aria-label="Date de livraison" title="Date de livraison">
            <button type="button" class="commande-plier-date bouton-discret" title="Seconde date de livraison" aria-label="Ajouter une seconde date de livraison">+</button>
            <!-- Le champ existe même replié : les lignes partent en tableaux parallèles, et
                 une case absente décalerait toutes les suivantes. Son enveloppe est ce qu'on
                 cache — le calendrier maison habille le champ, et son icône restait sinon. -->
            <div class="commande-date-2e-rang">
                <input class="commande-date-2e" name="commande[date_livraison_2][]" type="date" value="<?= prefaEscape($date2) ?>" aria-label="Seconde date de livraison" title="Seconde date de livraison">
            </div>
        </div>
        <div class="commande-champ commande-c-lieu">
            <input name="commande[lieu_livraison][]" value="<?= prefaEscape((string) ($commande['lieu_livraison'] ?? '')) ?>" maxlength="255" aria-label="Lieu de livraison" placeholder="Lieu de livraison" title="Lieu de livraison">
        </div>
    </div>
    <!-- Les articles du bon, sous leur commande et sur toute la largeur : une ligne
         d'article a six champs à elle seule. -->
    <div class="commande-articles-rang">
        <button type="button" class="commande-articles-bascule bouton-discret" aria-expanded="false">
            <svg class="icon commande-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
            Articles <span class="commande-articles-compte"><?= count($articles) ?></span>
        </button>
        <!-- Les articles partent en JSON : six champs sur quarante lignes feraient six
             tableaux parallèles de deux cent quarante cases. -->
        <input type="hidden" name="commande[articles][]" value="<?= prefaEscape(json_encode($articles, JSON_UNESCAPED_UNICODE)) ?>">
        <div class="commande-articles" hidden></div>
    </div>
    <?php
};
?>
<div class="commandes-saisie" data-commandes>
    <div class="commandes-entete">
        <h3>Commandes fournisseur</h3>
        <button type="button" class="commandes-ajouter bouton-discret">Ajouter une commande</button>
    </div>

    <div class="commandes-scroll"<?= $commandes ? '' : ' hidden' ?>>
        <div class="commandes-editable commandes-lignes">
            <?php foreach ($commandes as $commande) $ligneCommande($commande); ?>
        </div>
    </div>

    <p class="commandes-vide"<?= $commandes ? ' hidden' : '' ?>>Aucune commande. Le bon se dépose en PDF ci-dessous.</p>

    <template class="commande-gabarit"><?php $ligneCommande([]); ?></template>
</div>
