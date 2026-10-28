// Les paramètres : une fenêtre, sur le modèle de celle de Claude.
//
// Elle s'ouvre de trois endroits — le pied de la barre latérale, le menu du compte, et
// Ctrl+, — sur la rubrique demandée, ou sur la dernière ouverte. Le gabarit est rendu par le
// serveur avec le tableau de bord (includes/vue/parametres.php) : ce script montre une
// rubrique, cherche, et transmet ce qu'on règle.
//
// Un réglage s'applique dès qu'on le touche. Le thème vit dans theme.js ; la cadence et les
// passivations partent par les gestionnaires de dashboard-forms.js, ceux de l'ancien écran.
(() => {
    'use strict';

    const fenetre = document.getElementById('parametres');
    if (!fenetre) return;

    const onglets = [...fenetre.querySelectorAll('.parametres-onglet')];
    const rubriques = [...fenetre.querySelectorAll('.parametres-rubrique')];
    const contenu = fenetre.querySelector('.parametres-contenu');
    const recherche = fenetre.querySelector('.parametres-recherche input');
    const aucun = fenetre.querySelector('.parametres-aucun');
    let courante = 'general';

    // Comme la palette : sans accents ni majuscules, « theme » trouve « Thème ».
    const aplatir = texte => String(texte || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    /** Montre une rubrique. Une rubrique que cette personne n'a pas ramène à Général. */
    function montrer(nom) {
        courante = rubriques.some(r => r.dataset.rubrique === nom) ? nom : 'general';
        onglets.forEach(onglet => {
            if (onglet.dataset.rubrique === courante) onglet.setAttribute('aria-current', 'true');
            else onglet.removeAttribute('aria-current');
        });
        rubriques.forEach(r => { r.hidden = r.dataset.rubrique !== courante; });
        contenu.scrollTop = 0;
    }

    /**
     * La recherche traverse les rubriques : les lignes qui répondent restent, sous le nom de
     * la leur. Tous les mots tapés doivent s'y retrouver, dans n'importe quel ordre — ceux de
     * la ligne, de sa section et de sa rubrique : « apparence » trouve le thème.
     */
    function chercher() {
        const mots = aplatir(recherche.value).split(/\s+/).filter(Boolean);
        fenetre.classList.toggle('est-en-recherche', mots.length > 0);

        if (!mots.length) {
            fenetre.querySelectorAll('.parametres-section, .parametre').forEach(el => { el.hidden = false; });
            aucun.hidden = true;
            montrer(courante);
            return;
        }

        onglets.forEach(onglet => onglet.removeAttribute('aria-current'));
        let sectionsTrouvees = 0;
        rubriques.forEach(rubrique => {
            const nom = rubrique.querySelector('.parametres-rubrique-titre').textContent;
            let sections = 0;
            rubrique.querySelectorAll('.parametres-section').forEach(section => {
                const contexte = nom + ' ' + section.querySelector('h4').textContent + ' ' + (section.dataset.cherche || '');
                let lignes = 0;
                section.querySelectorAll('.parametre').forEach(ligne => {
                    const texte = aplatir(contexte + ' ' + ligne.textContent + ' ' + (ligne.dataset.cherche || ''));
                    ligne.hidden = !mots.every(mot => texte.includes(mot));
                    if (!ligne.hidden) lignes++;
                });
                section.hidden = lignes === 0;
                if (lignes) sections++;
            });
            rubrique.hidden = sections === 0;
            sectionsTrouvees += sections;
        });
        aucun.hidden = sectionsTrouvees > 0;
        aucun.querySelector('span').textContent = recherche.value.trim();
        contenu.scrollTop = 0;
    }

    /** Les boutons du thème disent le choix en cours, d'où qu'il soit venu. */
    function cocherTheme() {
        const choix = window.Theme ? window.Theme.choix() : 'auto';
        fenetre.querySelectorAll('[name="parametres-theme"]').forEach(radio => { radio.checked = radio.value === choix; });
    }

    function ouvrir(nom) {
        recherche.value = '';
        chercher();
        montrer(nom || courante);
        cocherTheme();
        if (!fenetre.open) fenetre.showModal();
        // Le focus sur la rubrique ouverte : les flèches parcourent la colonne, la recherche
        // est une tabulation plus haut.
        fenetre.querySelector('.parametres-onglet[aria-current]')?.focus();
    }

    window.ouvrirParametres = ouvrir;

    // ---------------------------------------------------------------- dans la fenêtre

    // Un clic sur le voile referme, comme chez Claude — s'il y a commencé : un glissement
    // parti d'un champ et lâché dehors ne ferme rien.
    let appuiSurLeVoile = false;
    fenetre.addEventListener('pointerdown', evenement => { appuiSurLeVoile = evenement.target === fenetre; });

    fenetre.addEventListener('click', evenement => {
        if (evenement.target === fenetre) {
            if (appuiSurLeVoile) fenetre.close();
            return;
        }
        const onglet = evenement.target.closest('.parametres-onglet');
        if (onglet) {
            recherche.value = '';
            chercher();
            montrer(onglet.dataset.rubrique);
            return;
        }
        if (evenement.target.closest('[data-fermer-parametres]')) {
            fenetre.close();
            return;
        }
        // « Modifiable dans Utilisateurs » : on y va, par le bouton de la barre, pour que
        // l'entrée se marque comme ouverte.
        const vers = evenement.target.closest('[data-parametres-vers]');
        if (vers) {
            fenetre.close();
            document.getElementById('btn-' + vers.dataset.parametresVers)?.click();
        }
    });

    // Les flèches parcourent la colonne, comme dans la barre latérale.
    fenetre.querySelector('.parametres-nav').addEventListener('keydown', evenement => {
        const rang = onglets.indexOf(document.activeElement);
        if (rang < 0) return;
        const vers = { ArrowDown: rang + 1, ArrowUp: rang - 1, Home: 0, End: onglets.length - 1 }[evenement.key];
        if (vers === undefined) return;
        evenement.preventDefault();
        const cible = onglets[(vers + onglets.length) % onglets.length];
        cible.focus();
        cible.click();
    });

    recherche.addEventListener('input', chercher);

    // Échap vide d'abord la recherche ; il ne referme la fenêtre qu'une fois le champ vide.
    recherche.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape' || !recherche.value) return;
        evenement.preventDefault();
        recherche.value = '';
        chercher();
    });

    fenetre.addEventListener('change', evenement => {
        const champ = evenement.target;
        if (champ.name === 'parametres-theme' && window.Theme) window.Theme.appliquer(champ.value);
        // La cadence part dès qu'on la quitte, par Entrée ou un clic ailleurs. Le formulaire
        // ne se valide pas dans le navigateur : sa bulle grise est celle de Windows, et le
        // serveur dit mieux ce qui ne va pas, sous la ligne.
        if (champ.id === 'parametres-cadence') champ.form.requestSubmit();
    });

    // Le menu du thème, dans la barre du haut, peut aussi changer le thème.
    document.addEventListener('theme:choisi', cocherTheme);

    // ---------------------------------------------------------------- l'ouvrir

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('[data-ouvre-parametres]');
        if (!bouton) return;
        // Sur un écran étroit, la barre passe par-dessus le contenu : on la range d'abord.
        if (window.matchMedia('(max-width: 1080px)').matches && typeof setSidebar === 'function') setSidebar(false);
        ouvrir(bouton.dataset.ouvreParametres);
    });

    // Ctrl+, : le raccourci des réglages dans la plupart des logiciels de bureau.
    document.addEventListener('keydown', evenement => {
        if (evenement.key !== ',' || !(evenement.ctrlKey || evenement.metaKey) || evenement.altKey) return;
        evenement.preventDefault();
        // Une autre fenêtre a la main : on ne passe pas par-dessus.
        if (document.querySelector('dialog[open]')) return;
        ouvrir();
    });

    // Les adresses de l'ancien écran : ?vue=settings, et ses ancres. Le serveur a rendu
    // l'accueil à sa place ; la fenêtre s'ouvre par-dessus, sur la bonne rubrique, puis
    // l'adresse reprend le nom de l'écran qu'on voit. Dans cet ordre : si le navigateur
    // refuse de réécrire l'adresse, la fenêtre est ouverte quand même.
    if (new URLSearchParams(location.search).get('vue') === 'settings') {
        ouvrir({ hours: 'fabrication', passivation: 'passivation', theme: 'general' }[location.hash.slice(1)]);
        history.replaceState(history.state, '', location.pathname + '?vue=' + (document.getElementById('content')?.dataset.vue || 'accueil'));
    }
})();
