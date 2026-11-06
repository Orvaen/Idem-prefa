(() => {
    'use strict';

    // « Validation des soudures » : la file des plans à gauche, le plan qu'on vise à droite
    // (pages/validation_soudures.php). Tout est déjà rendu ; choisir un plan ne fait que
    // montrer le sien.
    //
    // Les décisions partent par atelier.js, qui recharge l'écran une fois la réponse venue. Le
    // plan validé n'y est plus : on ouvre alors le suivant de la file, sans repasser par elle —
    // c'était tout le défaut de l'ancien écran, refermer et rouvrir autant de fois qu'il y avait
    // de plans.

    const page = () => document.getElementById('validation-soudures-page');
    const sansAccents = texte => texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const plansDe = racine => Array.from(racine.querySelectorAll('.validation-plan'));
    const visibles = racine => plansDe(racine).filter(bouton => !bouton.closest('li')?.hidden);

    /**
     * Ce qu'on veut voir au prochain rendu de l'écran : les plans à essayer, dans l'ordre — le
     * plan ouvert, puis ceux d'après, puis ceux d'avant en remontant —, la colonne montrée sur
     * petit écran, et la recherche. Une minute au plus : au-delà, ce n'est plus la suite de ce
     * qu'on faisait.
     *
     * @type {{ordre: string[], detail: boolean, recherche: string, quand: number} | null}
     */
    let voulu = null;

    /**
     * Ouvre un plan : son bouton se marque dans la file, son détail paraît à droite, les autres
     * se cachent. « detail » : sur petit écran, passer à la colonne du plan.
     */
    function choisir(bouton, { detail = true } = {}) {
        const racine = page();
        if (!racine || !bouton) return;
        plansDe(racine).forEach(autre => {
            const actif = autre === bouton;
            autre.setAttribute('aria-current', String(actif));
            const volet = document.getElementById(autre.getAttribute('aria-controls'));
            if (volet) volet.hidden = !actif;
        });
        const zone = racine.querySelector('.validation-detail-zone');
        if (zone) zone.scrollTop = 0;
        const file = racine.querySelector('.validation-file');
        if (file && detail) file.dataset.vue = 'detail';
        bouton.scrollIntoView({ block: 'nearest' });
    }

    function filtrer() {
        const racine = page();
        if (!racine) return;
        const texte = sansAccents((racine.querySelector('#validation-recherche')?.value || '').trim());
        let trouves = 0;
        racine.querySelectorAll('.validation-plans > li').forEach(ligne => {
            const garde = !texte || sansAccents(ligne.dataset.cherche || '').includes(texte);
            ligne.hidden = !garde;
            if (garde) trouves++;
        });
        const aucun = racine.querySelector('[data-aucun]');
        if (aucun) aucun.hidden = trouves > 0;
        // Le plan ouvert ne répond plus : le premier qui répond prend sa place, sans quitter
        // la file sur petit écran — on est en train de chercher.
        const ouvert = racine.querySelector('.validation-plan[aria-current="true"]');
        if (ouvert?.closest('li')?.hidden) {
            const premier = visibles(racine)[0];
            if (premier) choisir(premier, { detail: false });
        }
    }

    /** Retient où l'on en est, avant un rechargement de l'écran. */
    function retenir() {
        const racine = page();
        if (!racine) return;
        const ids = plansDe(racine).map(bouton => bouton.dataset.plan);
        const ouvert = racine.querySelector('.validation-plan[aria-current="true"]')?.dataset.plan;
        const rang = ids.indexOf(ouvert);
        voulu = {
            ordre: rang < 0 ? ids : [...ids.slice(rang), ...ids.slice(0, rang).reverse()],
            detail: racine.querySelector('.validation-file')?.dataset.vue === 'detail',
            recherche: racine.querySelector('#validation-recherche')?.value || '',
            quand: Date.now(),
        };
    }

    /**
     * L'écran vient d'être posé — à l'arrivée, après « Actualiser », après une décision : il
     * reprend le plan voulu, ou le suivant s'il a quitté la file. Sans vœu, il garde le premier
     * plan, ouvert par le serveur.
     */
    function preparer(racine) {
        if (racine.dataset.pret) return;
        racine.dataset.pret = '1';
        const vu = voulu && Date.now() - voulu.quand < 60000 ? voulu : null;
        voulu = null;
        if (!vu) return;

        const champ = racine.querySelector('#validation-recherche');
        if (champ && vu.recherche) {
            champ.value = vu.recherche;
            filtrer();
        }
        const file = racine.querySelector('.validation-file');
        const cible = vu.ordre.map(id => document.getElementById('validation-plan-' + id))
            .find(bouton => bouton && !bouton.closest('li')?.hidden);
        if (!cible) {
            if (file) file.dataset.vue = 'liste';
            return;
        }
        choisir(cible, { detail: vu.detail });
        // Le rechargement a emporté l'élément qui avait le focus : on le rend au plan ouvert,
        // dans la colonne qu'on voit.
        if (!document.activeElement || document.activeElement === document.body) {
            const enDetail = file?.dataset.vue === 'detail' && window.matchMedia('(max-width: 850px)').matches;
            const titre = document.getElementById(cible.getAttribute('aria-controls'))?.querySelector('h2');
            (enDetail ? titre : cible)?.focus({ preventScroll: true });
        }
    }

    // Les renvois des autres écrans — « Valider → » dans la fiche d'une demande, « Plans &
    // équipes » ou « Soudures » — ouvrent l'écran sur leur plan (prefa-interactions.js).
    window.validationSouduresOuvrir = function (plan) {
        voulu = { ordre: [String(plan)], detail: true, recherche: '', quand: Date.now() };
        const racine = page();
        const bouton = document.getElementById('validation-plan-' + plan);
        if (racine && bouton) choisir(bouton);
    };

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('#validation-soudures-page .validation-plan');
        if (bouton) choisir(bouton);
    });

    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('#validation-soudures-page [data-validation-retour]')) return;
        const racine = page();
        const file = racine?.querySelector('.validation-file');
        if (file) file.dataset.vue = 'liste';
        racine?.querySelector('.validation-plan[aria-current="true"]')?.focus();
    });

    // Les flèches parcourent la file, comme la barre latérale ; Début et Fin vont aux bouts.
    // Elles ouvrent le plan sans quitter la file sur petit écran.
    document.addEventListener('keydown', evenement => {
        const bouton = evenement.target.closest('#validation-soudures-page .validation-plan');
        if (!bouton) return;
        const pas = { ArrowDown: 1, ArrowUp: -1 }[evenement.key];
        const liste = visibles(page());
        let suivant = null;
        if (pas) suivant = liste[liste.indexOf(bouton) + pas];
        else if (evenement.key === 'Home') suivant = liste[0];
        else if (evenement.key === 'End') suivant = liste[liste.length - 1];
        else return;
        evenement.preventDefault();
        if (!suivant) return;
        choisir(suivant, { detail: false });
        suivant.focus();
    });

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#validation-recherche')) filtrer();
    });

    // Avant chaque rechargement : une décision envoyée (atelier.js la poste, puis recharge),
    // ou « Actualiser ».
    document.addEventListener('submit', evenement => {
        if (evenement.target.closest('#validation-soudures-page .validation-formulaire')) retenir();
    });
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('#validation-soudures-page .atelier-refresh')) retenir();
    });

    // L'écran arrive par AJAX dans #content (charger.js), qui ne prévient de rien : on le guette.
    const contenu = document.getElementById('content');
    if (contenu) {
        new MutationObserver(() => {
            const racine = page();
            if (racine) preparer(racine);
        }).observe(contenu, { childList: true });
    }
    const deja = page();
    if (deja) preparer(deja);
})();
