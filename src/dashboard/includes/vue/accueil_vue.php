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
     chaque tuile ouvre l'écran où l'on agit.

     La page tient dans une colonne de largeur bornée, centrée. Étalée d'un bord à l'autre,
     elle alignait quatre nombres sur toute la largeur de l'écran puis laissait six cents
     pixels de vide : on lisait un relevé, pas un tableau de bord. -->
<section class="accueil" aria-labelledby="welcome-title">
    <div class="accueil-colonne">
        <header class="accueil-entete">
            <h1 id="welcome-title">Bonjour <?= htmlspecialchars($prenom !== '' ? $prenom : $name) ?> !</h1>
            <p><?= htmlspecialchars(accueilDateDuJour()) ?></p>
        </header>

        <div class="accueil-tuiles">
            <?php foreach ($tuiles as $tuile): ?>
                <?php $icone = accueilIcone($tuile['ouvre']); ?>
                <button type="button" class="accueil-tuile<?= $tuile['alerte'] ? ' est-en-alerte' : '' ?>"
                        <?= $tuile['cle'] ? 'data-tuile="' . htmlspecialchars($tuile['cle']) . '"' : '' ?>
                        <?= $tuile['ouvre'] ? 'data-ouvre="' . htmlspecialchars($tuile['ouvre']) . '"' : 'disabled' ?>>
                    <?php if ($icone): ?>
                        <!-- Le dessin vient du menu : c'est celui de l'écran que la tuile ouvre. -->
                        <span class="accueil-tuile-icone" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><?= $icone ?></svg>
                        </span>
                    <?php endif; ?>
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
                <!-- Un journal vide est l'état normal d'un début de matinée, pas une erreur :
                     il se dit calmement, et dit aussi ce qui le remplira. -->
                <div class="accueil-rien">
                    <svg class="accueil-rien-dessin" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    <p>Rien de nouveau depuis ce matin.</p>
                    <p class="accueil-rien-aide">Les dépôts, validations et refus de la journée s’afficheront ici.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
