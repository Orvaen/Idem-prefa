(() => {
    document.addEventListener('click', evenement => {
        const commande = evenement.target.closest('.plans-iso-toggle');
        if (!commande) return;
        const row = document.getElementById(commande.getAttribute('aria-controls'));
        if (!row) return;
        const ouvrir = row.hidden;
        if (ouvrir) {
            // Un seul volet ouvert à la fois, comme partout ailleurs.
            // Seuls les boutons des volets qu'on referme repassent à « replié ». Le sélecteur
            // visait tout bouton déplié de la page : les rubriques de la barre de gauche et le
            // bouton du menu se repliaient aussi à chaque ouverture de volet.
            document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(autre => {
                autre.hidden = true;
                document.querySelectorAll(`[aria-controls="${autre.id}"]`).forEach(bouton => bouton.setAttribute('aria-expanded', 'false'));
            });
        }
        row.hidden = !ouvrir;
        // La commande existe sur la ligne et dans le menu : les deux suivent le volet.
        document.querySelectorAll(`[aria-controls="${row.id}"]`).forEach(bouton => bouton.setAttribute('aria-expanded', String(ouvrir)));
    });

    // ===== Les onglets et la recherche =====
    // Toutes les demandes validées sont déjà dans la page : on filtre sur place, sans
    // aller-retour. Onglet et recherche se combinent ; les titres de groupe disparaissent
    // avec leur dernière ligne, et chaque compte dit ce qui reste visible.
    function appliquerFiltres() {
        const page = document.getElementById('plans-iso-page');
        if (!page) return;
        const recherche = (document.getElementById('plans-iso-filter-search')?.value || '').trim().toLowerCase();
        const onglet = page.querySelector('.plans-iso-onglet[aria-pressed="true"]')?.dataset.groupe || '';

        const parGroupe = {};
        page.querySelectorAll('.plans-iso-request').forEach(demande => {
            const trouvee = !recherche || (demande.dataset.search || '').includes(recherche);
            if (trouvee) parGroupe[demande.dataset.groupe] = (parGroupe[demande.dataset.groupe] || 0) + 1;
            demande.hidden = !trouvee || (onglet !== '' && demande.dataset.groupe !== onglet);
        });

        let visibles = 0;
        page.querySelectorAll('.plans-iso-groupe').forEach(titre => {
            const nombre = parGroupe[titre.dataset.groupe] || 0;
            const affiche = nombre > 0 && (onglet === '' || titre.dataset.groupe === onglet);
            titre.hidden = !affiche;
            titre.querySelector('.prefa-groupe-compte').textContent = nombre;
            if (affiche) visibles += nombre;
        });

        page.querySelectorAll('.plans-iso-onglet').forEach(bouton => {
            const groupe = bouton.dataset.groupe;
            const total = groupe === '' ? Object.values(parGroupe).reduce((a, b) => a + b, 0) : (parGroupe[groupe] || 0);
            bouton.querySelector('.prefa-onglet-compte').textContent = total;
        });

        const aucune = page.querySelector('.plans-iso-aucune');
        if (aucune) aucune.hidden = visibles > 0;
    }

    document.addEventListener('click', evenement => {
        const choisi = evenement.target.closest('.plans-iso-onglet');
        if (!choisi) return;
        document.querySelectorAll('.plans-iso-onglet').forEach(onglet => {
            onglet.setAttribute('aria-pressed', String(onglet === choisi));
        });
        appliquerFiltres();
    });

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#plans-iso-filter-search')) appliquerFiltres();
    });

    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('#refresh-plans-iso')) return;
        window.chargerDans('#content', 'pages/plans_iso.php');
    });
})();

// Fermer un volet par sa croix rend la main à toutes ses commandes, ligne et menu.
document.addEventListener('click', evenement => {
    const croix = evenement.target.closest('.plans-iso-page .prefa-detail-close');
    if (!croix) return;
    const cible = croix.dataset.closeTarget || croix.closest('.prefa-detail-row')?.id;
    if (!cible) return;
    document.querySelectorAll(`[aria-controls="${cible}"]`).forEach(bouton => bouton.setAttribute('aria-expanded', 'false'));
});
