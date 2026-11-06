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

    /** Le menu n'écrit que par deux actions : celle des comptes, et celle du développement. */
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
        if (event.target.closest('.account-parametres')) {
            ouvrirMenu(false);
            window.ouvrirParametres?.();
            return;
        }
        if (event.target.closest('.account-add')) {
            ouvrirMenu(false);
            form.reset();
            etapeCompte('identifiant');
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
            if (erreurDev) window.alerte(erreurDev, { ton: 'danger' });
            return;
        }

        const retrait = event.target.closest('.account-remove');
        const bascule = event.target.closest('.account-item');
        if (!retrait && !bascule) return;
        if (bascule && menu.classList.contains('is-managing')) return;
        if (bascule && bascule.classList.contains('is-current')) { ouvrirMenu(false); return; }
        if (retrait && !await window.confirmer('Retirer « ' + retrait.dataset.nom + ' » de cet appareil ?', {
            texte: 'Son mot de passe sera redemandé pour y revenir.',
            action: 'Retirer',
        })) return;
        const bouton = retrait || bascule;
        const donnees = new FormData();
        donnees.set('csrf', menu.dataset.csrf);
        donnees.set('operation', retrait ? 'remove' : 'switch');
        donnees.set('compte', bouton.dataset.compte);
        const erreur = await envoyer(donnees, bouton);
        if (erreur) window.alerte(erreur, { ton: 'danger' });
    });

    dialog.addEventListener('click', event => {
        if (event.target.closest('.account-dialog-close')) dialog.close();
    });

    // ===== « Ajouter un compte » : les étapes de l'écran de connexion =====
    // L'identifiant d'abord. « Continuer » demande au serveur si ce compte attend son premier
    // mot de passe (auth/compte_a_creer.php) : oui, le code d'accès et le mot de passe choisi
    // (« creer », actions/comptes.php) ; non — ou compte inconnu, la réponse est la même —,
    // le mot de passe (« add »). Comme login.js : un mot de passe que le navigateur aurait
    // glissé dans un champ caché ne part pas.
    const boutonEnvoi = form.querySelector('.account-submit');
    const LIBELLES = { identifiant: 'Continuer', 'mot-de-passe': 'Se connecter', creation: 'Choisir et ajouter' };

    function dire(texte, erreur = false) {
        message.textContent = texte;
        message.classList.toggle('is-error', erreur);
    }

    function etapeCompte(nom) {
        form.dataset.etape = nom;
        form.querySelector('.account-etape-mdp').hidden = nom !== 'mot-de-passe';
        form.querySelector('.account-etape-creation').hidden = nom !== 'creation';
        boutonEnvoi.textContent = LIBELLES[nom];
        form.elements.operation.value = nom === 'creation' ? 'creer' : 'add';
        ['password', 'code', 'nouveau', 'confirmation'].forEach(champ => { form.elements[champ].value = ''; });
        dire('');
        if (nom === 'mot-de-passe') form.elements.password.focus();
        if (nom === 'creation') {
            form.querySelector('[data-account-compte]').textContent = form.elements.login.value.trim();
            form.elements.code.focus();
        }
    }

    // Changer d'identifiant ramène à la première étape : le compte n'est plus le même.
    form.elements.login.addEventListener('input', () => {
        if (form.dataset.etape !== 'identifiant') etapeCompte('identifiant');
    });

    /** Le compte attend-il son premier mot de passe ? null si le serveur ne répond pas. */
    async function compteACreer(login) {
        const donnees = new FormData();
        donnees.set('csrf', form.elements.csrf.value);
        donnees.set('login', login);
        try {
            const reponse = await fetch('../auth/compte_a_creer.php', { method: 'POST', body: donnees, credentials: 'same-origin' });
            const corps = await reponse.json().catch(() => ({}));
            return Boolean(corps.creer);
        } catch {
            return null;
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (boutonEnvoi.disabled) return;
        const login = form.elements.login.value.trim();
        if (!login) {
            dire('Tapez l’identifiant du compte.', true);
            form.elements.login.focus();
            return;
        }

        if (form.dataset.etape === 'identifiant') {
            boutonEnvoi.disabled = true;
            const creer = await compteACreer(login);
            boutonEnvoi.disabled = false;
            etapeCompte(creer ? 'creation' : 'mot-de-passe');
            return;
        }

        if (form.dataset.etape === 'mot-de-passe' && !form.elements.password.value) {
            dire('Tapez son mot de passe.', true);
            form.elements.password.focus();
            return;
        }
        if (form.dataset.etape === 'creation') {
            if (!form.elements.code.value.trim()) {
                dire('Tapez le code que l’administrateur a donné.', true);
                form.elements.code.focus();
                return;
            }
            if (form.elements.nouveau.value !== form.elements.confirmation.value) {
                dire('Les deux saisies ne sont pas les mêmes.', true);
                form.elements.confirmation.focus();
                return;
            }
        }

        dire(form.dataset.etape === 'creation' ? 'Enregistrement…' : 'Connexion…');
        const erreur = await envoyer(new FormData(form), boutonEnvoi);
        if (!erreur) return;
        dire(erreur, true);
        // Le champ à reprendre, sélectionné : le mot de passe, ou le code.
        const aReprendre = form.dataset.etape === 'creation' ? form.elements.code : form.elements.password;
        aReprendre.focus();
        aReprendre.select();
    });

    // ===== THÈME =====
    // La décision vit dans theme.js, chargé en tête de page : ici on ne fait que montrer
    // l'état et transmettre le choix.
    //
    // Le thème avait son bouton et son menu dans la barre du haut ; il tient maintenant en
    // trois choix dans le menu du compte. Le menu reste ouvert après le choix : l'écran
    // change de teinte derrière lui, et c'est la réponse.
    const choixTheme = menu.querySelector('.account-theme-choix');
    if (choixTheme) {
        const themeActif = () => (window.Theme ? window.Theme.choix() : 'auto');

        function marquerActif() {
            const actuel = themeActif();
            choixTheme.querySelectorAll('.theme-option').forEach(option => {
                const choisi = option.dataset.themeVal === actuel;
                option.classList.toggle('is-current', choisi);
                option.setAttribute('aria-checked', String(choisi));
            });
        }

        choixTheme.addEventListener('click', event => {
            const option = event.target.closest('.theme-option');
            if (!option) return;
            if (window.Theme) window.Theme.appliquer(option.dataset.themeVal);
            marquerActif();
        });

        // Les paramètres changent aussi le thème : la coche suit.
        document.addEventListener('theme:choisi', marquerActif);

        marquerActif();
    }
})();
