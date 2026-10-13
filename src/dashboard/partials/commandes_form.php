<?php

/**
 * Les commandes d'une demande, éditables dans le PV « Réception matériel ».
 *
 * Le bon de commande est déjà déposé en PDF juste en dessous ; ce tableau en reprend ce
 * qu'on a besoin de lire sans rouvrir le document. Les champs sont nommés en tableaux
 * parallèles — commande[numero][] — et partent avec le reste du formulaire : une seule
 * sauvegarde pour le PV et ses commandes.
 *
 * @var list<array<string, mixed>> $commandes
 * @var string $cle
 */
$etats = commandeEtats();
?>
<div class="commandes-saisie" data-commandes>
    <div class="commandes-entete">
        <h3>Commandes fournisseur</h3>
        <button type="button" class="commandes-ajouter">Ajouter une commande</button>
    </div>

    <div class="commandes-scroll">
        <table class="commandes-table commandes-editable">
            <colgroup>
                <col class="col-numero">
                <col class="col-fournisseur">
                <col class="col-livraison">
                <col class="col-lieu">
                <col class="col-etat">
                <col class="col-retirer">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">N° de commande</th>
                    <th scope="col">Fournisseur</th>
                    <th scope="col">Livraison</th>
                    <th scope="col">Lieu</th>
                    <th scope="col">État</th>
                    <th scope="col"><span class="sr-only">Retirer</span></th>
                </tr>
            </thead>
            <tbody class="commandes-lignes">
                <?php foreach ($commandes as $commande): ?>
                    <tr class="commande-ligne">
                        <td>
                            <input type="hidden" name="commande[id][]" value="<?= (int) $commande['id'] ?>">
                            <input name="commande[numero][]" value="<?= prefaEscape($commande['numero']) ?>" maxlength="40" aria-label="Numéro de commande" placeholder="957-MAT-I-03-28588">
                        </td>
                        <td><input name="commande[fournisseur][]" value="<?= prefaEscape($commande['fournisseur']) ?>" maxlength="120" aria-label="Fournisseur" placeholder="STAPPERT"></td>
                        <td class="commande-livraison<?= $commande['date_livraison_2'] ? ' est-depliee' : '' ?>">
                            <div class="commande-date-rang">
                                <input name="commande[date_bl][]" type="date" value="<?= prefaEscape((string) ($commande['date_bl'] ?? '')) ?>" aria-label="Date de livraison">
                                <button type="button" class="commande-plier-date" title="Seconde date de livraison" aria-label="Ajouter une seconde date de livraison">+</button>
                            </div>
                            <!-- Le champ existe même replié : les lignes partent en tableaux
                                 parallèles, et une case absente décalerait toutes les suivantes. -->
                            <input class="commande-date-2e" name="commande[date_livraison_2][]" type="date" value="<?= prefaEscape((string) ($commande['date_livraison_2'] ?? '')) ?>" aria-label="Seconde date de livraison">
                        </td>
                        <td><input name="commande[lieu_livraison][]" value="<?= prefaEscape((string) ($commande['lieu_livraison'] ?? '')) ?>" maxlength="255" aria-label="Lieu de livraison" placeholder="Atelier Orgon"></td>
                        <td>
                            <select name="commande[statut][]" aria-label="État de la commande">
                                <?php foreach ($etats as $valeur => $texte): ?>
                                    <option value="<?= prefaEscape($valeur) ?>" <?= $commande['statut'] === $valeur ? 'selected' : '' ?>><?= prefaEscape($texte) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <button type="button" class="commande-retirer" aria-label="Retirer cette commande" title="Retirer">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="commandes-vide"<?= $commandes ? ' hidden' : '' ?>>Aucune commande saisie. Le bon de commande se dépose en PDF ci-dessous ; ce tableau en reprend l’essentiel.</p>

    <!-- Le gabarit d'une ligne neuve. Il vit dans un template plutôt que dans le script :
         son balisage est celui des lignes ci-dessus, et l'écrire deux fois le ferait
         diverger au premier champ ajouté. -->
    <template class="commande-gabarit">
        <tr class="commande-ligne">
            <td>
                <input type="hidden" name="commande[id][]" value="">
                <input name="commande[numero][]" maxlength="40" aria-label="Numéro de commande" placeholder="957-MAT-I-03-28588">
            </td>
            <td><input name="commande[fournisseur][]" maxlength="120" aria-label="Fournisseur" placeholder="STAPPERT"></td>
            <td class="commande-livraison">
                <div class="commande-date-rang">
                    <input name="commande[date_bl][]" type="date" aria-label="Date de livraison">
                    <button type="button" class="commande-plier-date" title="Seconde date de livraison" aria-label="Ajouter une seconde date de livraison">+</button>
                </div>
                <input class="commande-date-2e" name="commande[date_livraison_2][]" type="date" aria-label="Seconde date de livraison">
            </td>
            <td><input name="commande[lieu_livraison][]" maxlength="255" aria-label="Lieu de livraison" placeholder="Atelier Orgon"></td>
            <td>
                <select name="commande[statut][]" aria-label="État de la commande">
                    <?php foreach ($etats as $valeur => $texte): ?>
                        <option value="<?= prefaEscape($valeur) ?>"><?= prefaEscape($texte) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <button type="button" class="commande-retirer" aria-label="Retirer cette commande" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </td>
        </tr>
    </template>
</div>
