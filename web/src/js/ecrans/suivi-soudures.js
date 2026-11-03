(() => {
    // L'écran « Soudures » de l'encadrement : tout est déjà rendu, on cache ce qui ne
    // correspond pas — à la recherche, et à l'onglet d'état choisi. La liste tient sur une
    // page : un rechargement serveur à chaque frappe coûterait plus qu'il ne rapporte.
    const page = () => document.getElementById('suivi-soudures-page');

    function filtrer() {
        const racine = page();
        if (!racine) return;
        const texte = (racine.querySelector('#suivi-soudures-recherche')?.value || '').trim().toLowerCase();
        const etat = racine.querySelector('.suivi-soudures-onglet[aria-pressed="true"]')?.dataset.etat || '';

        let visibles = 0;
        racine.querySelectorAll('.suivi-soudures-plan').forEach(plan => {
            const garde = (!texte || (plan.dataset.cherche || '').includes(texte))
                && (!etat || plan.dataset.etat === etat);
            plan.hidden = !garde;
            if (garde) visibles++;
            // Un volet ouvert sur une ligne qu'on vient de cacher se referme : sinon il
            // réapparaît déplié quand le filtre change, sans qu'on l'ait demandé.
            if (!garde) {
                plan.querySelectorAll('.prefa-detail-row').forEach(volet => { volet.hidden = true; });
                plan.querySelectorAll('.prefa-toggle[aria-expanded="true"]')
                    .forEach(bouton => bouton.setAttribute('aria-expanded', 'false'));
            }
        });

        // Une liste vide parce qu'on a trop filtré le dit ; une liste vide parce qu'aucun plan
        // n'a de soudures a déjà sa phrase.
        const aucun = racine.querySelector('[data-aucun]');
        if (aucun) aucun.hidden = visibles > 0 || !racine.querySelector('.suivi-soudures-plan');
    }

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#suivi-soudures-recherche')) filtrer();
    });

    document.addEventListener('click', evenement => {
        const onglet = evenement.target.closest('.suivi-soudures-onglet');
        if (!onglet) return;
        page()?.querySelectorAll('.suivi-soudures-onglet')
            .forEach(autre => autre.setAttribute('aria-pressed', String(autre === onglet)));
        filtrer();
    });

    // Toute la ligne ouvre le plan, comme une feuille de « Mes soudures » : « Examiner » n'est
    // que la cible visible. Un clic sur un autre contrôle de la ligne garde son sens.
    document.addEventListener('click', evenement => {
        const ligne = evenement.target.closest('#suivi-soudures-page .suivi-soudures-plan > tr:first-child');
        if (!ligne || evenement.target.closest('button, a, input, label')) return;
        ligne.querySelector('.suivi-soudures-examiner')?.click();
    });

    // « Actualiser », comme sur les commandes : l'écran se recharge, sa recherche et son
    // onglet avec.
    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.suivi-soudures-actualiser')) return;
        const racine = page();
        const recherche = racine?.querySelector('#suivi-soudures-recherche')?.value || '';
        const etat = racine?.querySelector('.suivi-soudures-onglet[aria-pressed="true"]')?.dataset.etat || '';
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, 'pages/suivi_soudures.php').then(({ ok }) => {
            if (!ok) {
                contenu.textContent = 'Impossible de recharger cet écran. Actualisez la page.';
                return;
            }
            const champ = document.getElementById('suivi-soudures-recherche');
            if (champ) champ.value = recherche;
            document.querySelectorAll('.suivi-soudures-onglet')
                .forEach(onglet => onglet.setAttribute('aria-pressed', String(onglet.dataset.etat === etat)));
            if (!document.querySelector('.suivi-soudures-onglet[aria-pressed="true"]')) {
                document.querySelector('.suivi-soudures-onglet[data-etat=""]')?.setAttribute('aria-pressed', 'true');
            }
            filtrer();
        });
    });
})();
