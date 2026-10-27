<?php
/**
 * Le volet d'une demande sur l'écran des PV : un panneau flottant à droite, la liste
 * visible derrière.
 *
 * Il se lit de haut en bas comme une fiche : la demande en lignes de propriétés (icône,
 * libellé, valeur), puis chaque PV avec ses champs posés de la même façon, ses pièces
 * jointes en puces, et l'historique en frise. Une seule grammaire pour tout le volet :
 * ce qu'on lit et ce qu'on saisit s'alignent sur la même colonne.
 *
 * @var array $demande  la ligne de la demande
 * @var array $pvs      ses PV, par type
 * @var int   $id
 */

/** Les icônes du volet : un trait, 16 px, la couleur du texte sourd. */
$icone = static function (string $nom): string {
    $traces = [
        'affaire' => '<rect x="3" y="7" width="18" height="13" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18" />',
        'personne' => '<circle cx="12" cy="8" r="3.5" /><path d="M5 20c.7-3.6 3.5-5.5 7-5.5s6.3 1.9 7 5.5" />',
        'goutte' => '<path d="M12 3.5 7 10a5.5 5.5 0 1 0 10 0z" />',
        'avancement' => '<circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3 2" />',
        'production' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5l3 3 5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z" />',
        'urgence' => '<path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" />',
        'etat' => '<circle cx="12" cy="12" r="8.5" stroke-dasharray="3 2.6" />',
        'date' => '<rect x="3.5" y="5" width="17" height="15" rx="2" /><path d="M3.5 10h17M8 3v4M16 3v4" />',
        'trombone' => '<path d="m21 11-8.6 8.6a5 5 0 0 1-7-7L14 4a3.3 3.3 0 0 1 4.7 4.7l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4L15.4 7" />',
        'fermer' => '<path d="m6 6 12 12M18 6 6 18" />',
    ];
    return '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
        . ($traces[$nom] ?? '') . '</svg>';
};

/** Une puce d'état, comme « Approved » : le seul aplat de couleur d'une section. */
$puce = static fn (string $statut): string => '<span class="pv-puce pv-puce-' . prefaEscape($statut) . '">'
    . prefaEscape(pvStatuts()[$statut]) . '</span>';

$historique = pvHistorique($demande, $pvs);
$nbPlans = (int) ($prod['plans'] ?? 0);
$nbSoudures = (int) ($prod['soudures'] ?? 0);
?>
<tr id="pv-detail-<?= $id ?>" class="prefa-detail-row" hidden>
    <td colspan="7">
        <div class="pv-volet" role="dialog" aria-labelledby="pv-volet-titre-<?= $id ?>">

            <header class="pv-volet-tete">
                <div>
                    <h2 id="pv-volet-titre-<?= $id ?>"><?= prefaEscape(prefaReference($id)) ?></h2>
                    <p><?= prefaEscape($demande['nom_affaire'] ?: 'Sans affaire') ?> · <?= $avancement['faits'] ?> PV sur <?= $avancement['applicables'] ?> fait<?= $avancement['faits'] > 1 ? 's' : '' ?></p>
                </div>
                <div class="pv-volet-outils">
                    <button type="button" class="prefa-detail-close" data-close-target="pv-detail-<?= $id ?>" aria-label="Fermer le volet" title="Fermer"><?= $icone('fermer') ?></button>
                </div>
            </header>

            <section class="pv-volet-section">
                <h3 class="pv-volet-titre">Demande</h3>
                <dl class="pv-proprietes">
                    <div><dt><?= $icone('affaire') ?>Affaire</dt><dd><?= prefaEscape($demande['nom_affaire'] ?: '—') ?></dd></div>
                    <div><dt><?= $icone('personne') ?>Demandeur</dt><dd>
                        <span class="prefa-personne"><span class="prefa-avatar" aria-hidden="true"><?= prefaEscape($initiales) ?></span><?= prefaEscape($demandeurNom ?: '—') ?></span>
                    </dd></div>
                    <?php if (!empty($demande['urgent'])): ?>
                        <div><dt><?= $icone('urgence') ?>Priorité</dt><dd><span class="pv-puce pv-puce-a_faire">Urgente</span></dd></div>
                    <?php endif; ?>
                    <div><dt><?= $icone('goutte') ?>Passivation</dt><dd><?= prefaEscape($demande['passivation_libelle'] ?: 'Aucune') ?></dd></div>
                    <div><dt><?= $icone('production') ?>Production</dt><dd>
                        <?= $nbPlans ?> plan<?= $nbPlans > 1 ? 's' : '' ?> · <?= $nbSoudures ?> soudure<?= $nbSoudures > 1 ? 's' : '' ?> saisie<?= $nbSoudures > 1 ? 's' : '' ?>
                    </dd></div>
                </dl>
            </section>

            <?php foreach ($types as $type => $libelle): $pv = $pvs[$type]; $cle = $id . '-' . $type; ?>
                <section class="pv-volet-section" aria-labelledby="pv-titre-<?= prefaEscape($cle) ?>">
                    <div class="pv-volet-titre-rang">
                        <h3 class="pv-volet-titre" id="pv-titre-<?= prefaEscape($cle) ?>"><?= prefaEscape($libelle) ?></h3>
                        <?= $puce($pv['statut']) ?>
                    </div>

                    <?php if ($pv['auto']): ?>
                        <p class="pv-volet-note">Aucune passivation n’est demandée sur cette affaire : ce PV est sans objet.</p>

                    <?php elseif ($pvLectureSeule): ?>
                        <dl class="pv-proprietes">
                            <div><dt><?= $icone('date') ?>Date du PV</dt><dd><?= $pv['date_pv'] ? prefaEscape(prefaFormatDate($pv['date_pv'])) : '—' ?></dd></div>
                            <div><dt><?= $icone('etat') ?>Commentaire</dt><dd class="pv-propriete-texte"><?= $pv['commentaire'] ? nl2br(prefaEscape($pv['commentaire'])) : '—' ?></dd></div>
                        </dl>
                        <?php if ($type === 'reception_materiel'):
                            $commandes = commandesDeLaDemande($db, $id); ?>
                            <?php if ($commandes): ?>
                                <div class="pv-volet-bloc"><?php require __DIR__ . '/commandes_table.php'; ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($pv['photos']): ?>
                            <div class="pv-volet-bloc"><?php $modifiable = false; require __DIR__ . '/pv_photos.php'; ?></div>
                        <?php endif; ?>
                        <?php if ($pv['documents']): ?>
                            <div class="pv-volet-bloc"><?php $modifiable = false; require __DIR__ . '/pv_documents.php'; ?></div>
                        <?php endif; ?>

                    <?php else: ?>
                        <form class="pv-form" data-cle="pv-form-<?= prefaEscape($cle) ?>" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                            <input type="hidden" name="demande" value="<?= $id ?>">
                            <input type="hidden" name="type" value="<?= prefaEscape($type) ?>">
                            <input type="hidden" name="operation" value="save">
                            <input type="hidden" name="revision" value="<?= (int) $pv['revision'] ?>">

                            <dl class="pv-proprietes">
                                <div>
                                    <dt><?= $icone('etat') ?><span id="pv-etat-libelle-<?= prefaEscape($cle) ?>">État</span></dt>
                                    <dd>
                                        <?php // Trois boutons côte à côte : les boutons radio restent dans le
                                              // balisage, seul leur rond est masqué. ?>
                                        <span class="pv-segments" role="radiogroup" aria-labelledby="pv-etat-libelle-<?= prefaEscape($cle) ?>">
                                            <?php foreach ($statuts as $valeur => $texte): ?>
                                                <label>
                                                    <input type="radio" name="statut" value="<?= prefaEscape($valeur) ?>" <?= $pv['statut'] === $valeur ? 'checked' : '' ?>>
                                                    <span><?= prefaEscape($texte) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt><?= $icone('date') ?><label for="pv-date-<?= prefaEscape($cle) ?>">Date du PV</label></dt>
                                    <dd><input id="pv-date-<?= prefaEscape($cle) ?>" class="pv-volet-champ" name="date_pv" type="date" value="<?= prefaEscape((string) ($pv['date_pv'] ?? '')) ?>"></dd>
                                </div>
                            </dl>

                            <div class="pv-volet-bloc">
                                <label class="pv-volet-etiquette" for="pv-commentaire-<?= prefaEscape($cle) ?>">Commentaire <span><?= $type === 'reception_materiel' ? 'si besoin' : 'facultatif' ?></span></label>
                                <textarea id="pv-commentaire-<?= prefaEscape($cle) ?>" class="pv-volet-champ" name="commentaire" maxlength="1000" rows="2"><?= prefaEscape((string) ($pv['commentaire'] ?? '')) ?></textarea>
                            </div>

                            <?php if ($type === 'reception_materiel'):
                                $commandes = commandesDeLaDemande($db, $id); ?>
                                <div class="pv-volet-bloc"><?php require __DIR__ . '/commandes_form.php'; ?></div>
                            <?php endif; ?>

                            <?php if ($type === 'fin_fabrication'): ?>
                                <div class="pv-volet-bloc">
                                    <span class="pv-volet-etiquette">Photos de la préfa</span>
                                    <?php if ($pv['photos']): ?>
                                        <?php $modifiable = true; require __DIR__ . '/pv_photos.php'; ?>
                                    <?php endif; ?>
                                    <div class="pv-ajout">
                                        <input id="pv-photos-<?= prefaEscape($cle) ?>" class="pv-ajout-input" name="photos[]" type="file" accept=".png,.jpg,.jpeg" multiple>
                                        <label class="pv-ajout-bouton" for="pv-photos-<?= prefaEscape($cle) ?>">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                            <span class="pv-ajout-texte">Ajouter des photos</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="pv-volet-bloc">
                                <span class="pv-volet-etiquette">Pièces jointes</span>
                                <?php if ($pv['documents']): ?>
                                    <?php $modifiable = true; require __DIR__ . '/pv_documents.php'; ?>
                                <?php endif; ?>
                                <div class="pv-ajout">
                                    <input id="pv-fichiers-<?= prefaEscape($cle) ?>" class="pv-ajout-input" name="documents[]" type="file" accept=".pdf,.png,.jpg,.jpeg" multiple>
                                    <label class="pv-ajout-bouton" for="pv-fichiers-<?= prefaEscape($cle) ?>">
                                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                        <span class="pv-ajout-texte">Ajouter des documents</span>
                                    </label>
                                </div>
                            </div>

                            <div class="pv-pied">
                                <p class="pv-message" role="status" aria-live="polite"></p>
                                <button type="submit" class="pv-enregistrer">Enregistrer</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="pv-volet-section">
                <h3 class="pv-volet-titre">Historique</h3>
                <?php if ($historique): ?>
                    <ol class="pv-historique">
                        <?php foreach ($historique as $evenement): ?>
                            <li class="pv-historique-<?= prefaEscape($evenement['genre']) ?>">
                                <span class="pv-historique-signe" aria-hidden="true">
                                    <?php if ($evenement['genre'] === 'fichier'): ?>
                                        <?= $icone('trombone') ?>
                                    <?php elseif (in_array($evenement['genre'], ['fait', 'validation'], true)): ?>
                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                    <?php else: ?>
                                        <?= $icone('etat') ?>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <strong><?= prefaEscape($evenement['titre']) ?></strong>
                                    <p><?= prefaEscape($evenement['texte']) ?></p>
                                    <time datetime="<?= prefaEscape($evenement['quand']) ?>"><?= prefaEscape(prefaFormatDate($evenement['quand'], true)) ?></time>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="pv-volet-note">Rien n’a encore été renseigné sur cette demande.</p>
                <?php endif; ?>
            </section>

        </div>
    </td>
</tr>
