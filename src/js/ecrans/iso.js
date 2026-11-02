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
    // aller-retour. Onglet et recherche se combinent, et chaque compte dit ce qui reste
    // visible. Il n'y a plus de titre de groupe à suivre : le point de chaque ligne dit son
    // état, comme sur la liste des demandes.
    function appliquerFiltres() {
        const page = document.getElementById('plans-iso-page');
        if (!page) return;
        const recherche = (document.getElementById('plans-iso-filter-search')?.value || '').trim().toLowerCase();
        const onglet = page.querySelector('.plans-iso-onglet[aria-pressed="true"]')?.dataset.groupe || '';

        const parGroupe = {};
        let visibles = 0;
        page.querySelectorAll('.plans-iso-request').forEach(demande => {
            const trouvee = !recherche || (demande.dataset.search || '').includes(recherche);
            if (trouvee) parGroupe[demande.dataset.groupe] = (parGroupe[demande.dataset.groupe] || 0) + 1;
            demande.hidden = !trouvee || (onglet !== '' && demande.dataset.groupe !== onglet);
            if (!demande.hidden) visibles++;
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

    // ===== Le résumé d'un plan =====
    //
    // « Résumé du plan », dans le volet : ce qui manque au plan, feuille ISO par feuille ISO
    // — les soudures à renseigner ou à valider, les coulées que les CCPU doivent couvrir. Il
    // se lit à la demande (actions/plan_resume.php) : il parcourt toutes les pages du plan.
    // Le nom d'une feuille l'ouvre dans la fenêtre du plan, à sa page.
    const fenetreResume = () => document.getElementById('plan-resume');
    // Le plan dont le résumé est ouvert, et la dernière demande : refermé puis rouvert sur un
    // autre plan, un résumé en retard ne remplace pas le bon.
    let blocResume = null;
    let demandeResume = 0;
    // Les résumés déjà lus, par plan. Rouvert, un résumé se montre tel qu'on l'a laissé, se
    // relit sans bruit, et ne change que si le plan a changé : il redisait « Lecture du
    // plan… » à chaque ouverture, pour ce qu'on venait de lire.
    const resumes = new Map();

    /** Pose un résumé dans la fenêtre : son titre, son sous-titre, son corps. */
    function poserResume(fenetre, resume) {
        fenetre.querySelector('#plan-resume-titre').textContent = resume.titre;
        fenetre.querySelector('[data-resume-sous-titre]').textContent = resume.sous_titre;
        fenetre.querySelector('[data-resume-corps]').innerHTML = resume.html;
    }

    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.plan-resume-ouvrir');
        const fenetre = fenetreResume();
        if (!bouton || !fenetre) return;
        blocResume = bouton.closest('.atelier-plan-block');
        const demande = ++demandeResume;
        const corps = fenetre.querySelector('[data-resume-corps]');
        const deja = resumes.get(bouton.dataset.element);
        if (deja) {
            poserResume(fenetre, deja);
        } else {
            fenetre.querySelector('#plan-resume-titre').textContent = 'Résumé du plan';
            fenetre.querySelector('[data-resume-sous-titre]').textContent = '';
            corps.innerHTML = '<div class="plan-resume-attente">Lecture du plan…</div>';
        }
        fenetre.showModal();
        try {
            const reponse = await fetch('actions/plan_resume.php?plan=' + encodeURIComponent(bouton.dataset.element),
                { credentials: 'same-origin' });
            const retour = await reponse.json().catch(() => null);
            if (demande !== demandeResume) return;
            if (!reponse.ok || !retour?.success) throw new Error(retour?.message || '');
            resumes.set(bouton.dataset.element, retour);
            // Rien n'a bougé : la fenêtre reste telle quelle, à l'endroit où on la lit.
            if (deja && deja.html === retour.html && deja.titre === retour.titre && deja.sous_titre === retour.sous_titre) return;
            const defilement = corps.scrollTop;
            poserResume(fenetre, retour);
            corps.scrollTop = defilement;
        } catch (erreur) {
            // Relu en silence, le résumé montré reste : seul ce qui n'a rien à montrer le dit.
            if (demande !== demandeResume || deja) return;
            corps.innerHTML = '';
            const message = document.createElement('div');
            message.className = 'plan-resume-attente';
            message.textContent = erreur.message || 'Le résumé n’a pas pu se lire. Réessayez.';
            corps.append(message);
        }
    });

    // Une feuille du résumé : il se referme, et la fenêtre du plan s'ouvre sur elle.
    document.addEventListener('click', evenement => {
        const feuille = evenement.target.closest('#plan-resume .plan-resume-feuille');
        const lecteur = feuille && blocResume?.querySelector('.plan-viewer[data-document="' + feuille.dataset.document + '"]');
        if (!lecteur) return;
        fenetreResume().close();
        lecteur.dataset.pageVoulue = feuille.dataset.page;
        lecteur.querySelector('.plan-viewer-ouvrir')?.click();
    });

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('#plan-resume [data-fermer-resume]')) fenetreResume()?.close();
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
