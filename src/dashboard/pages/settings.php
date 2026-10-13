<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
require_once __DIR__ . '/../includes/passivation_row.php';

if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');

$hoursSettings = prefaHoursSettings($db);
$types = $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll();

?>

<!-- Lien fichier externes -->
<link rel="stylesheet" href="../css/dashboard/settings.css?v=<?= time() ?>">
<script src="../js/settings.js?v=<?= filemtime(__DIR__ . '/../js/floating-fields.js') ?>" defer></script>

<!-- Page de paramétres -->
<section class="page settings-page">
    <div class="settings-shell">
        <div class="settings-header">
            <h1>Paramètres</h1>
            <p>Gérez la cadence de fabrication, les types de passivation et le thème visuel.</p>
        </div>

        <nav class="settings-tabs-bar" role="tablist" aria-label="Sections des paramètres">
            <button type="button" class="settings-tab-btn is-active" role="tab" id="tab-hours"
                aria-selected="true" aria-controls="panel-hours" data-tab="hours">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <polyline points="12 6 12 12 16 14" />
                </svg>
                <span>Jours ISO</span>
            </button>
            <button type="button" class="settings-tab-btn" role="tab" id="tab-passivation"
                aria-selected="false" aria-controls="panel-passivation" data-tab="passivation">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
                </svg>
                <span>Passivation</span>
            </button>
            <button type="button" class="settings-tab-btn" role="tab" id="tab-theme"
                aria-selected="false" aria-controls="panel-theme" data-tab="theme">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7z" />
                </svg>
                <span>Thème & Style</span>
            </button>
        </nav>

        <div class="settings-content">
            <!-- Jours ISO -->
            <section class="settings-pane is-active" id="panel-hours" role="tabpanel" aria-labelledby="tab-hours">
                <div class="settings-card">
                    <h2 class="settings-card-title">Temps par pouce ISO</h2>
                    <p class="settings-card-desc">Durée nécessaire pour fabriquer 1 pouce (utilisée pour estimer le délai de préfabrication).</p>

                    <form id="prefa-settings-form" class="settings-form">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">

                        <div class="settings-control-group">
                            <label for="duration-days">Jours par pouce :</label>
                            <div class="settings-inline-action">
                                <div class="input-unit">
                                    <input id="duration-days" name="duree_jours" type="number" inputmode="decimal"
                                        min="0.0001" max="100" step="any" value="<?= prefaEscape($hoursSettings['days']) ?>" required aria-label="Jours par pouce">
                                    <span class="input-unit-suffix">j</span>
                                </div>
                                <button type="submit" class="settings-btn-primary">Enregistrer</button>
                            </div>
                            <div class="settings-preview-box">
                                <span>Actuellement :</span>
                                <strong class="settings-preview-text"><?= prefaEscape($hoursSettings['formatted']) ?></strong>
                            </div>
                        </div>

                        <p class="prefa-message" role="status" aria-live="polite"></p>
                    </form>
                </div>
            </section>

            <!-- Passivation -->
            <section class="settings-pane" id="panel-passivation" role="tabpanel" aria-labelledby="tab-passivation" hidden>
                <div class="settings-card">
                    <h2 class="settings-card-title">Types de passivation</h2>
                    <p class="settings-card-desc">Gérez les types de passivation proposés aux demandeurs lors de la saisie d'une préfabrication.</p>

                    <form id="passivation-form" class="settings-form">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">

                        <div class="settings-control-group">
                            <label for="passivation-libelle">Ajouter un type de passivation :</label>
                            <div class="settings-inline-action">
                                <input id="passivation-libelle" name="libelle" maxlength="100" placeholder="ex : Décapage, Acide citrique..." required>
                                <button type="submit" class="settings-btn-primary">Ajouter</button>
                            </div>
                        </div>

                        <p class="prefa-message" role="status" aria-live="polite"></p>
                    </form>

                    <div class="settings-table-card">
                        <table class="settings-data-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="col-id">ID</th>
                                    <th scope="col">Type</th>
                                    <th scope="col" class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="passivation-rows">
                                <?php foreach ($types as $type): ?><?php passivationRow($type); ?><?php endforeach; ?>
                                <?php if (!$types): ?>
                                    <tr class="passivation-empty">
                                        <td colspan="3">Aucun type pour l'instant.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Thème & Style (Option Rose pour le fun) -->
            <section class="settings-pane" id="panel-theme" role="tabpanel" aria-labelledby="tab-theme" hidden>
                <div class="settings-card">
                    <h2 class="settings-card-title">Design & Thème de l'application</h2>
                    <p class="settings-card-desc">Personnalisez l'ambiance visuelle du tableau de bord, des formulaires et du planning.</p>

                    <div class="theme-picker-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 16px;">
                        <!-- Carte Système : l'état de départ, et le seul moyen d'y revenir
                             une fois qu'on a tranché. -->
                        <div class="theme-card" data-theme-val="auto" style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; transition: all 0.2s; background: linear-gradient(135deg, #ffffff 50%, #141820 50%);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; padding: 2px 6px; border-radius: 4px; background: var(--surface);">
                                <strong style="font-size: 15px; color: var(--text);">Celui du système</strong>
                                <span class="theme-check" style="font-size: 18px; color: var(--accent); font-weight: 700;"></span>
                            </div>
                            <p style="font-size: 12px; margin-bottom: 14px; padding: 4px 6px; border-radius: 4px; background: var(--surface); color: var(--text-muted);">L'application suit le réglage de votre ordinateur, et bascule avec lui.</p>
                            <button type="button" class="settings-btn-secondary btn-apply-theme" data-theme="auto" style="width: 100%;">Suivre le système</button>
                        </div>

                        <!-- Carte Thème Sombre -->
                        <div class="theme-card" data-theme-val="dark" style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; transition: all 0.2s; background: var(--frame);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <strong style="font-size: 15px; color: var(--text);">Thème Sombre Standard</strong>
                                <span class="theme-check" style="font-size: 18px; color: var(--accent); font-weight: 700;"></span>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">Le style classique et sobre du tableau de bord.</p>
                            <div style="display: flex; gap: 8px; margin-bottom: 16px;">
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #141820; border: 1px solid #343d4c;" title="Fond"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #1c222d; border: 1px solid #343d4c;" title="Surface"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #2563eb;" title="Accent Bleu"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #e2e7ef;" title="Texte"></div>
                            </div>
                            <button type="button" class="settings-btn-secondary btn-apply-theme" data-theme="dark" style="width: 100%;">Activer le thème Sombre</button>
                        </div>

                        <!-- Carte Thème Clair -->
                        <div class="theme-card" data-theme-val="light" style="border: 2px solid #cbd5e1; border-radius: 8px; padding: 20px; cursor: pointer; transition: all 0.2s; background: #ffffff;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <strong style="font-size: 15px; color: #0f172a;">Thème Clair Épuré ☀️</strong>
                                <span class="theme-check" style="font-size: 18px; color: #2563eb; font-weight: 700;"></span>
                            </div>
                            <p style="font-size: 12px; color: #64748b; margin-bottom: 14px;">Une interface lumineuse, moderne et reposante avec fond clair.</p>
                            <div style="display: flex; gap: 8px; margin-bottom: 16px;">
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #f1f5f9; border: 1px solid #cbd5e1;" title="Fond Gris Perle"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #ffffff; border: 1px solid #cbd5e1;" title="Surface Blanche"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #2563eb;" title="Accent Bleu"></div>
                                <div style="width: 28px; height: 28px; border-radius: 4px; background: #0f172a;" title="Texte Foncé"></div>
                            </div>
                            <button type="button" class="settings-btn-primary btn-apply-theme" data-theme="light" style="width: 100%; background: #2563eb !important; border: none !important; font-weight: 700;">Activer le thème Clair</button>
                        </div>

                    </div>

                    <p id="theme-status-message" class="prefa-message" role="status" aria-live="polite" style="margin-top: 18px;"></p>
                </div>
            </section>
        </div>
    </div>
</section>