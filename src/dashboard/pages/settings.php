<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_hours.php';
require_once __DIR__ . '/../includes/vue/passivation_row.php';

if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');

$hoursSettings = prefaHoursSettings($db);
$types = $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll();

?>

<?php
// Le jeton de version est l'heure de dernière modification du fichier servi : le navigateur
// garde sa copie tant que le fichier n'a pas bougé, et la remplace dès qu'il bouge.
//
// Les trois défauts d'avant : time() changeait d'adresse à chaque affichage, donc la
// feuille de style était retéléchargée en permanence ; settings.js portait l'heure de
// floating-fields.js, donc le modifier ne renouvelait rien ; et le chemin remontait d'un
// cran de trop — « pages/../js » au lieu de « pages/../../js » — d'où un avertissement
// filemtime() à chaque ouverture de l'écran.
$version = static fn (string $fichier): string => (string) @filemtime(__DIR__ . '/../../' . $fichier);
?>
<!-- Lien fichier externes -->
<link rel="stylesheet" href="../css/dashboard/settings.css?v=<?= $version('css/dashboard/settings.css') ?>">
<script src="../js/settings.js?v=<?= $version('js/settings.js') ?>" defer></script>

<?php // Les réglages se lisent comme ceux de Linear ou de GitHub : une seule page, trois
      // sections empilées, et dans chacune des lignes « ce que c'est » à gauche, « la
      // commande » à droite. Les onglets cachaient deux sections sur trois pour en montrer
      // une qui tenait en une ligne.
      //
      // La page porte la coque des listes — même en-tête, même retrait — pour que passer de
      // la liste des demandes aux paramètres ne fasse pas sauter le titre de place. ?>
<section class="page prefa-page prefa-list settings-page">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1>Paramètres</h1>
                <p>La cadence de fabrication, les types de passivation et l’apparence de l’application.</p>
            </div>
        </header>

        <div class="reglages">

            <!-- Fabrication -->
            <section class="reglages-section" id="hours" aria-labelledby="reglages-fabrication">
                <div class="reglages-section-tete">
                    <h2 id="reglages-fabrication">Fabrication</h2>
                    <p>Ce qui sert à estimer le délai d’une préfabrication.</p>
                </div>

                <div class="reglages-carte">
                    <form id="prefa-settings-form" class="reglage">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">

                        <div class="reglage-texte">
                            <label class="reglage-titre" for="duration-days">Temps par pouce ISO, en jours</label>
                            <p class="reglage-aide">
                                Jours de fabrication pour un pouce. Actuellement
                                <strong class="settings-preview-text"><?= prefaEscape($hoursSettings['formatted']) ?></strong>
                                par pouce.
                            </p>
                            <p class="prefa-message" role="status" aria-live="polite"></p>
                        </div>

                        <div class="reglage-controle">
                            <input id="duration-days" class="reglage-champ reglage-champ-nombre" name="duree_jours" type="number" inputmode="decimal"
                                   min="0.0001" max="100" step="any" value="<?= prefaEscape($hoursSettings['days']) ?>" required>
                            <button type="submit" class="reglage-bouton">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Passivation -->
            <section class="reglages-section" id="passivation" aria-labelledby="reglages-passivation">
                <div class="reglages-section-tete">
                    <h2 id="reglages-passivation">Types de passivation</h2>
                    <p>Proposés aux demandeurs lors de la saisie d’une préfabrication.</p>
                </div>

                <div class="reglages-carte">
                    <form id="passivation-form" class="reglage">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">

                        <div class="reglage-texte">
                            <label class="reglage-titre" for="passivation-libelle">Ajouter un type</label>
                            <p class="reglage-aide">Il est proposé aussitôt dans le formulaire de demande.</p>
                            <p class="prefa-message" role="status" aria-live="polite"></p>
                        </div>

                        <div class="reglage-controle">
                            <input id="passivation-libelle" class="reglage-champ" name="libelle" maxlength="100"
                                   placeholder="Décapage, acide citrique…" required>
                            <button type="submit" class="reglage-bouton">Ajouter</button>
                        </div>
                    </form>

                    <?php // La liste vit dans la carte, sans cadre à elle : une carte et un
                          // tableau encadré donneraient deux cadres l'un dans l'autre (DA, § 8). ?>
                    <table class="reglages-table">
                        <thead>
                            <tr>
                                <th scope="col" class="reglages-col-id">N°</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="reglages-col-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="passivation-rows">
                            <?php foreach ($types as $type): ?><?php passivationRow($type); ?><?php endforeach; ?>
                            <?php if (!$types): ?>
                                <tr class="passivation-empty">
                                    <td colspan="3">Aucun type pour l’instant.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Apparence -->
            <section class="reglages-section" id="theme" aria-labelledby="reglages-apparence">
                <div class="reglages-section-tete">
                    <h2 id="reglages-apparence">Apparence</h2>
                    <p>Retenue par ce navigateur : chacun choisit la sienne.</p>
                </div>

                <?php // Une ligne comme les deux autres sections : un libellé, un champ. Les
                      // trois cartes à aperçus colorés faisaient de cette section la plus
                      // bruyante de la page pour le réglage le moins important. ?>
                <div class="reglages-carte">
                    <div class="reglage">
                        <div class="reglage-texte">
                            <label class="reglage-titre" for="theme-choix">Thème</label>
                            <p class="reglage-aide">« Système » suit le réglage de l’ordinateur.</p>
                            <p id="theme-status-message" class="prefa-message" role="status" aria-live="polite"></p>
                        </div>
                        <div class="reglage-controle">
                            <span class="reglage-liste">
                                <select id="theme-choix" class="reglage-champ">
                                    <option value="auto">Système</option>
                                    <option value="light">Clair</option>
                                    <option value="dark">Sombre</option>
                                </select>
                                <svg aria-hidden="true" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m4 6 4 4 4-4" /></svg>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </div>
</section>
