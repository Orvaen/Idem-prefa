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

    // Les visas d'un plan, enregistrés d'un bloc. Une case cochée part à « 1 », une case
    // décochée part à vide : le serveur a besoin des deux, puisque décocher retire une
    // signature et que ne rien envoyer ne voudrait rien dire.
    $(document).on('submit', '.soudures-visa-form', async function (event) {
        event.preventDefault();
        const form = this;
        const retour = form.querySelector('.atelier-message');
        const bouton = form.querySelector('button[type="submit"]');

        // Relevé avant le rechargement : après, le formulaire est détaché de la page.
        const voletId = form.closest('.prefa-detail-row')?.id || '';

        const donnees = new URLSearchParams();
        donnees.set('csrf', form.dataset.csrf);
        donnees.set('element', form.dataset.element);
        form.querySelectorAll('input[type="checkbox"][name^="visas["]').forEach(case_ => {
            donnees.set(case_.name, case_.checked ? '1' : '');
        });

        bouton.disabled = true;
        retour.textContent = 'Enregistrement…';
        try {
            const reponse = await fetch('actions/viser_soudures.php', {
                method: 'POST', body: donnees,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const corps = await reponse.json();
            retour.textContent = corps.message || (reponse.ok ? 'Enregistré.' : 'Enregistrement impossible.');
            if (!reponse.ok || !corps.success) return;
            // Le nom du signataire s'affiche à côté de la case : il vient d'être posé ou
            // retiré, et c'est lui qui s'imprimera sur le plan.
            $('#content').load('pages/suivi_soudures.php', function () {
                const volet = voletId ? document.getElementById(voletId) : null;
                if (volet) {
                    volet.hidden = false;
                    document.querySelectorAll('.prefa-toggle[aria-controls="' + volet.id + '"]')
                        .forEach(b => b.setAttribute('aria-expanded', 'true'));
                }
            });
        } catch {
            retour.textContent = 'Le serveur ne répond pas.';
        } finally {
            bouton.disabled = false;
        }
    });
})();
