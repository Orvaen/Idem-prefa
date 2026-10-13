(() => {
    // L'œil qui montre le mot de passe.
    //
    // Rien à déclarer dans le balisage : tout champ de type « password » le reçoit, ici
    // comme sur l'écran de connexion, dans la fenêtre d'ajout de compte ou dans la création
    // d'un utilisateur. Un champ ajouté demain l'aura aussi.
    //
    // On ne tape pas un mot de passe sans le voir quand on ne sait pas s'il est bon : c'est
    // la seule façon de distinguer une faute de frappe d'un compte refusé.

    const OEIL = '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />'
        + '<circle cx="12" cy="12" r="3" />';
    // Barré : la même paupière, plus le trait qui la ferme. Deux dessins plutôt qu'un seul
    // pivoté — l'œil ouvert et l'œil fermé doivent se reconnaître d'un coup d'œil.
    const OEIL_BARRE = '<path d="M3 3l18 18" />'
        + '<path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" />'
        + '<path d="M9.4 5.8A9.3 9.3 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a16 16 0 0 1-2.9 3.7" />'
        + '<path d="M6.2 7.7A16 16 0 0 0 2.5 12S6 18.5 12 18.5a9.6 9.6 0 0 0 3.4-.6" />';

    const dessin = montre => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
        + (montre ? OEIL_BARRE : OEIL) + '</svg>';

    function equiper() {
        document.querySelectorAll('input[type="password"]').forEach(champ => {
            // Le bouton vit dans la boîte à étiquette flottante, qui est déjà positionnée.
            // Sans elle, on en pose une : le bouton doit se caler sur le champ.
            let boite = champ.closest('.floating-field');
            if (!boite) {
                if (champ.parentElement.classList.contains('champ-mdp')) {
                    boite = champ.parentElement;
                } else {
                    boite = document.createElement('div');
                    boite.className = 'champ-mdp';
                    champ.before(boite);
                    boite.append(champ);
                }
            }
            if (boite.querySelector('.mdp-voir')) return;

            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'mdp-voir';
            bouton.setAttribute('aria-pressed', 'false');
            bouton.setAttribute('aria-label', 'Afficher le mot de passe');
            bouton.title = 'Afficher le mot de passe';
            bouton.innerHTML = dessin(false);

            bouton.addEventListener('click', () => {
                const montre = champ.type === 'password';
                champ.type = montre ? 'text' : 'password';
                bouton.innerHTML = dessin(montre);
                bouton.setAttribute('aria-pressed', String(montre));
                const dit = montre ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
                bouton.setAttribute('aria-label', dit);
                bouton.title = dit;
                // Le curseur revient dans le champ, à la fin : on vient de cliquer pour
                // relire ce qu'on tape, pas pour en sortir.
                champ.focus();
                const fin = champ.value.length;
                try { champ.setSelectionRange(fin, fin); } catch (erreur) { /* type text seul */ }
            });

            boite.append(bouton);
            boite.classList.add('a-mdp-voir');
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', equiper);
    else equiper();

    // Les écrans arrivent par AJAX dans #content, et la fenêtre d'ajout de compte est
    // rendue avec la page mais décorée après. On repasse à chaque changement du document ;
    // la fonction ne rééquipe pas ce qui l'est déjà.
    new MutationObserver(equiper).observe(document.documentElement, { childList: true, subtree: true });
})();
