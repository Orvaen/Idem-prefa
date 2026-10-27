<?php
$requestId = (int) $row['id'];
$isPending = (int) $row['id_statut'] === 1;
$isUrgentPending = !empty($row['urgent']) && $isPending;
// Une demande validée que l'atelier n'a pas encore reprise : l'action de prise en
// charge s'offre ici comme sur la page Plans / ISO.

$ownerName = trim($row['prenom'] . ' ' . $row['name']);

// Deux lettres pour le demandeur, comme le monogramme d'une carte d'affaire : la ligne
// gagne un point d'ancrage, et on retrouve une personne du coin de l'œil sans lire.
$initiales = mb_strtoupper(mb_substr(trim($row['prenom']), 0, 1) . mb_substr(trim($row['name']), 0, 1));

// Une échéance dépassée sur une demande qui court encore. Refusée, elle ne court plus :
// elle ne sera pas faite, elle n'est donc pas en retard.
$enRetard = !empty($row['date_fin_prevue'])
    && (int) $row['id_statut'] !== 3
    && $row['date_fin_prevue'] < date('Y-m-d');

// Le retard se dit en jours : « en retard » seul ne distingue pas la veille du mois dernier.
$joursDeRetard = $enRetard
    ? (new DateTimeImmutable((string) $row['date_fin_prevue']))->diff(new DateTimeImmutable('today'))->days
    : 0;

// La décision se prend sur la ligne quand c'est elle que la ligne attend.
$decisionEnLigne = $isAdmin && $isPending;
?>
<tbody class="prefa-request<?= $isUrgentPending ? ' is-urgent-pending' : '' ?>">
    <tr>
        <?php // Le numéro seul : le chantier est dans la colonne d'à côté quand la liste n'est
              // pas groupée, et dans le titre du groupe quand elle l'est. Le répéter ici
              // allongeait la colonne de deux cents pixels pour redire la voisine. ?>
        <?php // L'urgence ouvre la ligne, par un signe et non par un mot. La place lui est
              // réservée sur toutes les lignes : sans ça, les références des demandes
              // urgentes se décaleraient de seize pixels et la colonne cesserait d'être
              // une colonne. ?>
        <td class="prefa-reference">
            <span class="prefa-urgence">
                <?php if (!empty($row['urgent'])): ?>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                        stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Urgente">
                        <title>Demande urgente</title>
                        <path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" />
                    </svg>
                <?php endif; ?>
            </span><?= prefaEscape(prefaReference($requestId)) ?>
        </td>
        <?php if (!$vueParAffaire): ?>
            <td class="prefa-affaire"><?= prefaEscape($row['nom_affaire'] ?: '—') ?></td>
        <?php endif; ?>
        <?php // La date de dépôt a quitté la ligne. Elle ne sert à personne pour décider —
              // on valide sur l'échéance, pas sur l'ancienneté — et le volet de détail la
              // porte déjà deux fois : dans sa fiche et dans « Déposée par X le … ».
              //
              // « secondaire » : le nom du demandeur situe la ligne, il ne la fait pas lire.
              // Sans ce retrait, les colonnes arrivaient toutes du même noir. ?>
        <?php // L'urgence se pose sous l'échéance plutôt que dans une colonne à elle : elle
              // ne dit quelque chose qu'une fois sur quatre, et « Normale » occupait une
              // colonne entière pour ne rien annoncer. ?>
        <?php // L'échéance est la seule donnée de la ligne qui porte un état : elle est
              // tenue, ou elle est dépassée. C'est ce qui lui vaut une pastille quand les
              // mesures d'à côté restent des nombres nus — une pastille sur un nombre
              // empêcherait de le comparer à celui du dessous, qui est tout l'intérêt
              // d'une colonne de nombres. ?>
        <?php // La date nue, et le retard dessous quand il y en a un. Encadrer chaque date
              // d'une pastille rouge revenait à alerter sur toutes : c'est la ligne de
              // retard, seule, qui doit se voir. ?>
        <td class="prefa-echeance">
            <span class="prefa-date"><?= $row['date_fin_prevue'] ? prefaEscape(prefaFormatDate($row['date_fin_prevue'])) : '—' ?></span>
            <?php if ($enRetard): ?>
                <span class="prefa-retard"><?= (int) $joursDeRetard ?> j de retard</span>
            <?php endif; ?>
            <?php if ($vueParAffaire && $row['urgent']): ?>
                <span class="prefa-urgent">Urgente</span>
            <?php endif; ?>
        </td>
        <?php if (!$vueParAffaire): ?>
            <td class="prefa-nombre"><?= prefaEscape($row['pouces_total_iso']) ?></td>
            <td class="prefa-nombre"><?= prefaEscape(prefaFormatDays((float) $row['heures_chiffrees'] / 24)) ?></td>
            <td class="prefa-secondaire">
                <span class="prefa-personne">
                    <span class="prefa-avatar" aria-hidden="true"><?= prefaEscape($initiales) ?></span>
                    <?= prefaEscape($ownerName) ?>
                </span>
            </td>
        <?php endif; ?>
        <?php // Rangée par statut, la liste annonce le statut dans le titre du groupe : la
              // colonne ne le répète que là où la liste est rangée par affaire. ?>
        <?php if ($vueParAffaire): ?>
            <td><span class="prefa-status status-<?= (int) $row['id_statut'] ?>"><?= prefaEscape($row['libelle']) ?></span></td>
        <?php endif; ?>
        <?php // Une cellule d'actions à déclencheur unique, comme le TableActionCell de Propel.
              // Les trois commandes étaient posées à plat dans la cellule et se répétaient sur
              // chaque ligne : la colonne pesait plus lourd que les données qu'elle accompagne,
              // et l'œil la lisait avant elles.
              //
              // Les boutons gardent leurs classes et leurs data- : les gestionnaires sont
              // branchés par délégation sur le document, ils continuent donc de répondre sans
              // rien savoir du menu qui les contient. ?>
        <td class="prefa-actions">
            <?php // Valider et Refuser sur la ligne : c'est ce que l'écran attend d'une
                  // demande en attente. Ils passent par le formulaire d'examen du volet —
                  // Valider le soumet tel quel, Refuser l'ouvre sur le motif, obligatoire. ?>
            <?php if ($decisionEnLigne): ?>
                <span class="prefa-decision">
                    <button type="button" class="prefa-ligne-refuser" data-detail="prefa-detail-<?= $requestId ?>">Refuser</button>
                    <button type="button" class="prefa-ligne-valider" data-detail="prefa-detail-<?= $requestId ?>">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10" /></svg>
                        Valider
                    </button>
                </span>
            <?php endif; ?>
            <button type="button" class="prefa-actions-declencheur" aria-haspopup="menu" aria-expanded="false"
                    aria-label="Actions sur la demande <?= prefaEscape(prefaReference($requestId)) ?>">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
                </svg>
            </button>
            <div class="prefa-actions-menu" role="menu" hidden>
                <button type="button" role="menuitem" class="prefa-toggle" aria-expanded="false" aria-controls="prefa-detail-<?= $requestId ?>">
                    <?= $isAdmin && $isPending ? 'Examiner' : 'Détails' ?>
                </button>
                <?php if ($canEditPrefa): ?>
                    <button type="button" role="menuitem" class="edit-prefa" data-id="<?= $requestId ?>">Modifier</button>
                <?php endif; ?>
                <?php if ($canEditPrefa && ($isAdmin || ($isPending && (int) $row['idUsers'] === (int) $actor['id']))): ?>
                    <button type="button" role="menuitem" class="delete-prefa est-destructrice" data-id="<?= $requestId ?>" data-csrf="<?= prefaEscape(Session::jeton()) ?>">Supprimer</button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <tr id="prefa-detail-<?= $requestId ?>" class="prefa-detail-row" hidden>
        <td colspan="<?= $colonnes ?>">
            <div class="prefa-table-detail">
                <?php require __DIR__ . '/prefa_detail.php'; ?>
                <?php if ($isAdmin && $isPending): ?>
                    <form class="prefa-review prefa-form">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                        <input type="hidden" name="id" value="<?= $requestId ?>">

                        <div class="prefa-review-barre">
                            <div class="prefa-review-motif">
                                <label for="comment-<?= $requestId ?>">Commentaire <span>— obligatoire pour un refus</span></label>
                                <textarea id="comment-<?= $requestId ?>" name="commentaire" maxlength="1000" rows="1"
                                          placeholder="Motif, réserve, consigne pour l’atelier…"></textarea>
                            </div>
                            <div class="prefa-review-actions">
                                <p class="prefa-message" role="status" aria-live="polite"></p>
                                <div class="prefa-buttons">
                                    <button type="submit" value="3" class="reject bouton-discret">Refuser</button>
                                    <button type="submit" value="2">Valider</button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</tbody>
