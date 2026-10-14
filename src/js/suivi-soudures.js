(() => {
    // Le filtrage de l'écran « Soudures » de l'encadrement : tout est déjà rendu, on se
    // contente de cacher ce qui ne correspond pas. La liste tient sur une page — un
    // rechargement serveur à chaque frappe coûterait plus qu'il ne rapporte.
    const page = () => document.getElementById('suivi-soudures-page');

    function filtrer() {
        const racine = page();
        if (!racine) return;
        const texte = (racine.querySelector('#suivi-soudures-recherche')?.value || '').trim().toLowerCase();
        const etat = racine.querySelector('#suivi-soudures-etat')?.value || '';
        const affaire = racine.querySelector('#suivi-soudures-affaire')?.value || '';

        let visibles = 0;
        racine.querySelectorAll('.suivi-soudures-plan').forEach(plan => {
            const garde = (!texte || (plan.dataset.cherche || '').includes(texte))
                && (!etat || plan.dataset.etat === etat)
                && (!affaire || plan.dataset.affaire === affaire);
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

        const compte = racine.querySelector('#suivi-soudures-compte');
        if (compte) compte.textContent = String(visibles);
    }

    $(document).on('input', '#suivi-soudures-recherche', filtrer);
    $(document).on('change', '#suivi-soudures-etat, #suivi-soudures-affaire', filtrer);
})();
