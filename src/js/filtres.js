(() => {
    'use strict';

    // La barre de filtres : ouvrir un volet, cocher, enlever.
    //
    // Rien n'est déclaré ici : le balisage vient de includes/filtres.php et se reconnaît à
    // ses classes. Le script ne sait pas ce qu'est un statut ou un utilisateur — il coche
    // des cases et prévient le formulaire, qui recharge la liste.
    //
    // Délégation sur le document : la barre est rendue avec la liste, et se remplace à
    // chaque rafraîchissement.

    const volets = () => [...document.querySelectorAll('.filtre-volet:not([hidden])')];

    /** Le volet est en position fixe : il doit donc recevoir ses coordonnées. */
    function placer(filtre) {
        const volet = filtre.querySelector('.filtre-volet');
        const repere = filtre.getBoundingClientRect();
        const hauteur = Math.min(volet.scrollHeight, 320);
        const dessous = window.innerHeight - repere.bottom;

        volet.style.left = Math.max(8, Math.min(repere.left, window.innerWidth - volet.offsetWidth - 8)) + 'px';
        // Vers le bas, sauf s'il n'y a pas la place et qu'il y en a au-dessus.
        volet.style.top = (dessous < hauteur + 8 && repere.top > dessous
            ? repere.top - hauteur - 4
            : repere.bottom + 4) + 'px';
    }

    function fermer(filtre) {
        const volet = filtre.querySelector('.filtre-volet');
        volet.hidden = true;
        filtre.querySelector('.filtre-declencheur').setAttribute('aria-expanded', 'false');
    }

    function ouvrir(filtre) {
        document.querySelectorAll('.filtre').forEach(autre => { if (autre !== filtre) fermer(autre); });
        const volet = filtre.querySelector('.filtre-volet');
        volet.hidden = false;
        filtre.querySelector('.filtre-declencheur').setAttribute('aria-expanded', 'true');
        placer(filtre);
        filtre.querySelector('.filtre-chercher')?.focus();
    }

    /**
     * La liste se recharge.
     *
     * On passe par un événement sur le formulaire plutôt que d'appeler le rafraîchissement :
     * c'est l'écran qui sait comment se recharger, pas la barre de filtres.
     */
    const prevenir = filtre => filtre.closest('form')
        ?.dispatchEvent(new Event('change', { bubbles: true }));

    // ---------------------------------------------------------------- ouvrir et fermer

    document.addEventListener('click', evenement => {
        const declencheur = evenement.target.closest?.('.filtre-declencheur');
        if (declencheur) {
            const filtre = declencheur.closest('.filtre');
            const ouvert = declencheur.getAttribute('aria-expanded') === 'true';
            if (ouvert) fermer(filtre);
            else ouvrir(filtre);
            return;
        }

        // Un clic dans un volet ne le referme pas : on coche souvent plusieurs cases.
        if (evenement.target.closest?.('.filtre-volet')) return;
        document.querySelectorAll('.filtre').forEach(fermer);
    });

    document.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape') return;
        const ouverts = volets();
        if (!ouverts.length) return;
        ouverts.forEach(v => fermer(v.closest('.filtre')));
    });

    // La barre vit dans un écran qui défile : le volet suit, ou il resterait en l'air.
    const replacer = () => volets().forEach(v => placer(v.closest('.filtre')));
    document.addEventListener('scroll', replacer, true);
    window.addEventListener('resize', replacer);

    // ---------------------------------------------------------------- cocher

    // Rien à faire quand on coche à la souris : l'événement « change » de la case remonte
    // déjà jusqu'au formulaire, et c'est lui que l'écran écoute. On prévenait en plus, ce
    // qui déclenchait deux rechargements par clic — mesuré à deux requêtes pour une case.
    //
    // « prevenir » reste indispensable plus bas : « Tout sélectionner », « Effacer » et
    // « Tout effacer » cochent par programme, et affecter « checked » n'émet aucun
    // événement natif.

    document.addEventListener('click', evenement => {
        // « Tout sélectionner » bascule : tout coché, le même bouton décoche.
        const tout = evenement.target.closest?.('.filtre-tout');
        if (tout) {
            const filtre = tout.closest('.filtre');
            const cases = [...filtre.querySelectorAll('.filtre-option input')]
                .filter(c => c.closest('.filtre-option').style.display !== 'none');
            const manquant = cases.some(c => !c.checked);
            cases.forEach(c => { c.checked = manquant; });
            tout.textContent = manquant ? 'Tout désélectionner' : 'Tout sélectionner';
            prevenir(filtre);
            return;
        }

        const effacer = evenement.target.closest?.('.filtre-effacer');
        if (effacer) {
            const filtre = effacer.closest('.filtre');
            filtre.querySelectorAll('.filtre-option input').forEach(c => { c.checked = false; });
            prevenir(filtre);
            return;
        }

        const tousEffacer = evenement.target.closest?.('.filtres-tout-effacer');
        if (tousEffacer) {
            const barre = tousEffacer.closest('form');
            barre.querySelectorAll('.filtre-option input').forEach(c => { c.checked = false; });
            // La recherche fait partie de ce qui restreint la liste : « tout effacer »
            // qui la laisserait en place ne tiendrait pas sa promesse.
            const recherche = barre.querySelector('.filtres-recherche input');
            if (recherche) recherche.value = '';
            barre.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    // ---------------------------------------------------------------- chercher dans la liste

    document.addEventListener('input', evenement => {
        const champ = evenement.target.closest?.('.filtre-chercher');
        if (!champ) return;
        const filtre = champ.closest('.filtre');
        const cherche = champ.value.trim().toLowerCase();
        let visibles = 0;
        filtre.querySelectorAll('.filtre-option').forEach(option => {
            const texte = option.querySelector('.filtre-texte').textContent.toLowerCase();
            const montre = !cherche || texte.includes(cherche);
            option.style.display = montre ? '' : 'none';
            if (montre) visibles++;
        });
        filtre.querySelector('.filtre-vide').hidden = visibles > 0;
        placer(filtre);
    });
})();
