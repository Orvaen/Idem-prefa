<?php

/**
 * L'écran d'accueil.
 *
 * Il est rendu à deux endroits : dans dashboard.php au premier affichage, et par
 * pages/accueil.php quand on revient dessus par la barre. Le balisage est donc ici, et
 * non dans l'un des deux — il y était, et l'entrée « Accueil » de la barre n'aurait eu
 * aucune page à ouvrir.
 *
 * Attend : $prenom, $name, $tuiles, $activite.
 */
?>
<!-- L'accueil répond aux deux questions qu'on se pose en arrivant : combien en attente de
     moi, et qu'est-ce qui a bougé. Les chiffres sont ceux du métier de la personne, et
     chaque tuile ouvre l'écran où l'on agit. -->
<section class="accueil" aria-labelledby="welcome-title">
    <header class="accueil-entete">
        <h1 id="welcome-title">Bonjour <?= htmlspecialchars($prenom !== '' ? $prenom : $name) ?> !</h1>
        <p><?= htmlspecialchars(accueilDateDuJour()) ?></p>
    </header>

    <div class="accueil-tuiles">
        <?php foreach ($tuiles as $tuile): ?>
            <button type="button" class="accueil-tuile<?= $tuile['alerte'] ? ' est-en-alerte' : '' ?>"
                    <?= $tuile['cle'] ? 'data-tuile="' . htmlspecialchars($tuile['cle']) . '"' : '' ?>
                    <?= $tuile['ouvre'] ? 'data-ouvre="' . htmlspecialchars($tuile['ouvre']) . '"' : 'disabled' ?>>
                <span class="accueil-tuile-valeur"><?= (int) $tuile['valeur'] ?></span>
                <span class="accueil-tuile-libelle"><?= htmlspecialchars($tuile['libelle']) ?></span>
                <span class="accueil-tuile-detail"><?= htmlspecialchars($tuile['detail']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <section class="accueil-journal" aria-labelledby="accueil-journal-titre">
        <h2 id="accueil-journal-titre">Aujourd’hui</h2>
        <?php if ($activite): ?>
            <ul class="accueil-evenements">
                <?php foreach ($activite as $evenement): ?>
                    <li class="accueil-evenement est-<?= htmlspecialchars($evenement['quoi']) ?>">
                        <span class="accueil-heure"><?= htmlspecialchars($evenement['heure']) ?></span>
                        <span class="accueil-texte"><?= htmlspecialchars($evenement['texte']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="accueil-rien">Rien de nouveau depuis ce matin.</p>
        <?php endif; ?>
    </section>
</section>
