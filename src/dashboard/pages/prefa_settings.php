<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');
$hoursSettings = prefaHoursSettings($db);
?>
<section class="page prefa-page prefa-simple">
    <div class="prefa-shell">
        <div class="prefa-heading">
            <h1>Calcul des jours ISO</h1>
            <p>Indiquez le temps nécessaire pour fabriquer 1 pouce ISO.</p>
        </div>
        <form id="prefa-settings-form" class="prefa-form">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <div class="form-group">
                <label for="days-per-inch">Temps par pouce ISO</label>
                <div class="time-inputs-group">
                    <div class="input-unit">
                        <input id="days-per-inch" name="duree_jours" type="number" min="0.0001" max="100" step="any" value="<?= prefaEscape($hoursSettings['days']) ?>" required aria-label="Jours par pouce">
                        <span class="input-unit-suffix">j</span>
                    </div>

                </div>
                <small>Exemple : 0,5 jour par pouce = 5 jours pour 10 pouces.</small>
            </div>
            <p class="prefa-message" role="status" aria-live="polite"></p>
            <div class="prefa-form-footer prefa-actions"><button type="submit">Enregistrer</button></div>
        </form>
    </div>
</section>