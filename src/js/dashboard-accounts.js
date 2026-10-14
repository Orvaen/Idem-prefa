(() => {
    const trigger = document.getElementById('account-menu-button');
    const menu = document.getElementById('account-menu');
    const dialog = document.getElementById('account-dialog');
    if (!trigger || !menu || !dialog) return;
    const form = dialog.querySelector('form');
    const message = form.querySelector('.account-message');
    const marge = 8;
    const popovers = [];

    // Les menus sont en position fixe : la barre latérale défile et les découperait.
    //
    // L'état ouvert se lit dans aria-expanded, que le CSS suit : on réécrivait ici le
    // className du déclencheur, ce qui effaçait sa classe de départ — le bouton de la barre
    // perdait son habillage à la première ouverture, et comme ce bloc sert aussi au menu du
    // thème, ouvrir celui-ci restylait le bouton des comptes.
    function popover(declencheur, panneau, premierFocus) {
        function placer() {
            const repere = declencheur.getBoundingClientRect();
            const largeur = panneau.offsetWidth;
            const hauteur = panneau.offsetHeight;

            // Le menu se range du côté où il y a la place, sans jamais recouvrir ce qui
            // l'ouvre : à droite du déclencheur quand elle y est, sinon dessous, et
            // au-dessus en dernier recours. Il ouvrait avant vers le haut en toute
            // circonstance, ce qui le posait sur le bouton depuis que celui-ci a rejoint la
            // barre du haut.
            let gauche = repere.right + marge;
            let haut = repere.bottom - hauteur;

            if (gauche + largeur > window.innerWidth - marge) {
                // Aligné sur le bord droit du déclencheur plutôt que sur son bord gauche :
                // contre le bord de la fenêtre, c'est le seul alignement qui tienne.
                gauche = repere.right - largeur;
                haut = repere.bottom + hauteur + marge <= window.innerHeight
                    ? repere.bottom + marge
                    : repere.top - hauteur - marge;
            }

            panneau.style.left = Math.max(marge, Math.min(gauche, window.innerWidth - largeur - marge)) + 'px';
            panneau.style.top = Math.max(marge, Math.min(haut, window.innerHeight - hauteur - marge)) + 'px';
        }

        function ouvrir(ouvert) {
            if (ouvert) {
                popovers.forEach(autre => { if (autre !== controle) autre.ouvrir(false); });
                panneau.style.visibility = 'hidden';
                panneau.hidden = false;
                placer();
                panneau.style.visibility = '';
                panneau.querySelector(premierFocus)?.focus();
            } else {
                const rendreFocus = panneau.contains(document.activeElement);
                panneau.hidden = true;
                panneau.classList.remove('is-managing');
                panneau.querySelector('.account-manage')?.setAttribute('aria-pressed', 'false');
                if (rendreFocus) declencheur.focus();
            }
            declencheur.setAttribute('aria-expanded', String(ouvert));
        }

        const controle = { declencheur, panneau, ouvrir, placer, estOuvert: () => !panneau.hidden };
        declencheur.addEventListener('click', () => ouvrir(panneau.hidden));
        popovers.push(controle);
        return controle;
    }

    const menuComptes = popover(trigger, menu, '.account-item:not(.is-current), .account-add, .account-devenir');

    document.addEventListener('click', event => {
        popovers.forEach(p => {
            if (p.estOuvert() && !p.panneau.contains(event.target) && !p.declencheur.contains(event.target)) p.ouvrir(false);
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') popovers.forEach(p => { if (p.estOuvert()) p.ouvrir(false); });
    });

    window.addEventListener('resize', () => popovers.forEach(p => { if (p.estOuvert()) p.placer(); }));

    const ouvrirMenu = ouvert => menuComptes.ouvrir(ouvert);

    /** Le menu n'''écrit que par deux actions : celle des comptes, et celle du développement. */
    async function envoyer(donnees, bouton, action = 'comptes.php') {
        bouton.disabled = true;
        try {
            const reponse = await fetch('actions/' + action, { method: 'POST', body: donnees, credentials: 'same-origin' });
            const resultat = await reponse.json().catch(() => ({}));
            if (!reponse.ok || !resultat.success) return resultat.message || 'Action impossible. Réessayez.';
            window.location.href = resultat.redirect || 'dashboard.php';
            return null;
        } catch (error) {
            return 'Le serveur ne répond pas. Réessayez.';
        } finally {
            bouton.disabled = false;
        }
    }

    menu.addEventListener('click', async event => {
        if (event.target.closest('.account-add')) {
            ouvrirMenu(false);
            form.reset();
            message.textContent = '';
            message.classList.remove('is-error');
            dialog.showModal();
            form.elements.login.focus();
            return;
        }
        const gestion = event.target.closest('.account-manage');
        if (gestion) {
            // Comme Discord : un mode dédié révèle le retrait, plutôt qu'une croix permanente.
            const actif = menu.classList.toggle('is-managing');
            gestion.setAttribute('aria-pressed', String(actif));
            gestion.querySelector('.account-manage-label').textContent = actif ? 'Terminer' : 'Gérer les comptes';
            return;
        }
        // Développement : prendre la place d'un compte sans son mot de passe. Le bouton
        // n'est rendu que si le serveur tourne avec APP_ENV=dev, et l'action le revérifie.
        const devenir = event.target.closest('.account-devenir');
        if (devenir) {
            const donneesDev = new FormData();
            donneesDev.set('csrf', menu.dataset.csrf);
            donneesDev.set('compte', devenir.dataset.compte);
            const erreurDev = await envoyer(donneesDev, devenir, 'dev_connexion.php');
            if (erreurDev) window.alert(erreurDev);
            return;
        }

        const retrait = event.target.closest('.account-remove');
        const bascule = event.target.closest('.account-item');
        if (!retrait && !bascule) return;
        if (bascule && menu.classList.contains('is-managing')) return;
        if (bascule && bascule.classList.contains('is-current')) { ouvrirMenu(false); return; }
        if (retrait && !window.confirm(
            'Retirer « ' + retrait.dataset.nom + ' » de cet appareil ? Son mot de passe sera redemandé pour y revenir.'
        )) return;
        const bouton = retrait || bascule;
        const donnees = new FormData();
        donnees.set('csrf', menu.dataset.csrf);
        donnees.set('operation', retrait ? 'remove' : 'switch');
        donnees.set('compte', bouton.dataset.compte);
        const erreur = await envoyer(donnees, bouton);
        if (erreur) window.alert(erreur);
    });

    dialog.addEventListener('click', event => {
        if (event.target.closest('.account-dialog-close')) dialog.close();
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        message.textContent = 'Connexion…';
        message.classList.remove('is-error');
        const erreur = await envoyer(new FormData(form), form.querySelector('.account-submit'));
        if (erreur) {
            message.textContent = erreur;
            message.classList.add('is-error');
        }
    });

    // ===== THÈME =====
    // La décision vit dans theme.js, chargé en tête de page : ici on ne fait que montrer
    // l'état et transmettre le choix.
    const declencheurTheme = document.getElementById('theme-menu-button');
    const menuTheme = document.getElementById('theme-menu');
    if (declencheurTheme && menuTheme) {
        const menuThemes = popover(declencheurTheme, menuTheme, '.theme-option[aria-checked="true"], .theme-option');

        const themeActif = () => (window.Theme ? window.Theme.choix() : 'auto');

        function marquerActif() {
            const actuel = themeActif();
            menuTheme.querySelectorAll('.theme-option').forEach(option => {
                const choisi = option.dataset.themeVal === actuel;
                option.classList.toggle('is-current', choisi);
                option.setAttribute('aria-checked', String(choisi));
                // toggleAttribute et pas .hidden : la coche est un <svg>, qui n'a pas cette propriété.
                option.querySelector('.account-check').toggleAttribute('hidden', !choisi);
            });
        }

        function appliquerTheme(nom) {
            if (window.Theme) window.Theme.appliquer(nom);
            marquerActif();
        }

        menuTheme.addEventListener('click', event => {
            const option = event.target.closest('.theme-option');
            if (!option) return;
            appliquerTheme(option.dataset.themeVal);
            menuThemes.ouvrir(false);
        });

        marquerActif();
    }
})();
