(() => {
    // ===== Le volet d'une demande =====
    // Un volet par demande, ouvert par « Renseigner » ou « Voir ». Un seul ouvert à la fois,
    // comme sur les autres listes.
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.pv-ouvrir');
        if (!bouton) return;
        const volet = document.getElementById(bouton.getAttribute('aria-controls'));
        if (!volet) return;
        const ouvrir = volet.hidden;
        if (ouvrir) {
            // Seuls les boutons des volets qu'on referme repassent à « replié ». Le sélecteur
            // visait tout bouton déplié de la page : les rubriques de la barre de gauche et le
            // bouton du menu se repliaient aussi à chaque ouverture de volet.
            document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(autre => {
                autre.hidden = true;
                document.querySelectorAll(`[aria-controls="${autre.id}"]`).forEach(b => b.setAttribute('aria-expanded', 'false'));
            });
        }
        volet.hidden = !ouvrir;
        bouton.setAttribute('aria-expanded', String(ouvrir));
    });

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('#pv-page .prefa-detail-close');
        if (!bouton) return;
        const cible = bouton.dataset.closeTarget;
        document.querySelectorAll(`[aria-controls="${cible}"]`).forEach(b => b.setAttribute('aria-expanded', 'false'));
    });

    // Le volet ouvert est rouvert après rechargement : on enregistre PV par PV, et il serait
    // pénible de rouvrir la demande à chaque fois.
    const ouverts = () => Array.from(document.querySelectorAll('#pv-page .prefa-detail-row:not([hidden])')).map(ligne => ligne.id);

    function recharger(deplies = [], ensuite = null) {
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, 'pages/pv.php').then(({ ok }) => {
            if (!ok) {
                contenu.textContent = 'Impossible de charger les PV. Actualisez.';
                return;
            }
            deplies.forEach(id => {
                const ligne = document.getElementById(id);
                if (ligne) ligne.hidden = false;
                document.querySelectorAll(`[aria-controls="${id}"]`).forEach(b => b.setAttribute('aria-expanded', 'true'));
            });
            if (ensuite) ensuite();
        });
    }

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.pv-refresh')) recharger(ouverts());
    });

    // ===== Les onglets et la recherche =====
    // Toutes les demandes sont dans la page : on filtre sur place. Onglet et recherche se
    // combinent ; un titre de groupe disparaît avec sa dernière ligne, et chaque compte dit
    // ce qui reste visible.
    function filtrer() {
        const page = document.getElementById('pv-page');
        if (!page) return;
        const texte = (document.getElementById('pv-recherche')?.value || '').trim().toLowerCase();
        const onglet = page.querySelector('.pv-onglet[aria-pressed="true"]')?.dataset.groupe || '';

        const parGroupe = {};
        page.querySelectorAll('.pv-ligne').forEach(ligne => {
            const trouvee = !texte || (ligne.dataset.recherche || '').includes(texte);
            if (trouvee) parGroupe[ligne.dataset.groupe] = (parGroupe[ligne.dataset.groupe] || 0) + 1;
            const garder = trouvee && (onglet === '' || ligne.dataset.groupe === onglet);
            ligne.hidden = !garder;
            // Un volet laissé ouvert sur une ligne masquée resterait affiché tout seul.
            if (!garder) ligne.querySelectorAll('.prefa-detail-row').forEach(volet => { volet.hidden = true; });
        });

        let visibles = 0;
        page.querySelectorAll('.pv-groupe').forEach(titre => {
            const nombre = parGroupe[titre.dataset.groupe] || 0;
            const affiche = nombre > 0 && (onglet === '' || titre.dataset.groupe === onglet);
            titre.hidden = !affiche;
            titre.querySelector('.prefa-groupe-compte').textContent = nombre;
            if (affiche) visibles += nombre;
        });

        page.querySelectorAll('.pv-onglet').forEach(bouton => {
            const groupe = bouton.dataset.groupe;
            const total = groupe === '' ? Object.values(parGroupe).reduce((a, b) => a + b, 0) : (parGroupe[groupe] || 0);
            bouton.querySelector('.prefa-onglet-compte').textContent = total;
        });

        const aucune = page.querySelector('.pv-aucune');
        if (aucune) aucune.hidden = visibles > 0;
    }

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#pv-recherche')) filtrer();
    });
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.pv-onglet');
        if (!bouton) return;
        document.querySelectorAll('.pv-onglet').forEach(o => o.setAttribute('aria-pressed', String(o === bouton)));
        filtrer();
    });

    // ===== L'enregistrement =====
    /**
     * Poste à actions/pv.php et rend sa réponse JSON. Un refus du serveur, une réponse qui
     * n'est pas du JSON ou une panne du réseau rejettent avec le corps lu, ou null.
     */
    function poster(donnees) {
        return fetch('actions/pv.php', { method: 'POST', body: donnees, credentials: 'same-origin' })
            .then(async reponse => {
                const corps = await reponse.json().catch(() => null);
                if (!reponse.ok || corps === null) throw corps;
                return corps;
            }, () => { throw null; });
    }

    function envoyer(donnees, messages, boutons, deplies) {
        boutons.forEach(bouton => { bouton.disabled = true; });
        messages.forEach(message => {
            message.classList.remove('error');
            message.textContent = 'Enregistrement…';
        });
        poster(donnees)
            .then(reponse => {
                if (window.toast) window.toast(reponse.message || 'Enregistré.');
                // Un bon déposé sur la réception matériel se lit tout de suite : commandes.js
                // écoute cet événement et remplit la commande dans le volet rouvert.
                const bons = reponse.bons || [];
                const demande = donnees.get('demande');
                recharger(deplies, bons.length ? () => document.dispatchEvent(
                    new CustomEvent('pv:bons-deposes', { detail: { demande, bons } })) : null);
            }, corps => {
                messages.forEach(message => {
                    message.classList.add('error');
                    message.textContent = corps?.message || 'Enregistrement impossible. Réessayez.';
                });
                boutons.forEach(bouton => { bouton.disabled = false; });
            });
    }

    document.addEventListener('submit', evenement => {
        const form = evenement.target.closest('.pv-form');
        if (!form) return;
        evenement.preventDefault();
        envoyer(new FormData(form), form.querySelectorAll('.pv-message'), form.querySelectorAll('.pv-enregistrer'), ouverts());
    });

    // L'état choisi dans le menu de la cellule part tout seul : pas de formulaire à ouvrir.
    document.addEventListener('click', evenement => {
        const choix = evenement.target.closest('.pv-etat-choix');
        if (!choix) return;
        if (choix.getAttribute('aria-checked') === 'true') return;
        const declencheur = choix.closest('.pv-cellule').querySelector('.pv-etat-declencheur');
        const donnees = new FormData();
        donnees.set('csrf', choix.dataset.csrf);
        donnees.set('operation', 'statut');
        donnees.set('demande', choix.dataset.demande);
        donnees.set('type', choix.dataset.type);
        donnees.set('statut', choix.dataset.statut);
        donnees.set('revision', choix.dataset.revision);
        declencheur.disabled = true;
        poster(donnees)
            .then(reponse => {
                if (window.toast) window.toast(reponse.message || 'Enregistré.');
                recharger(ouverts());
            }, corps => {
                declencheur.disabled = false;
                if (window.toast) window.toast(corps?.message || 'Changement impossible. Réessayez.', { erreur: true });
            });
    });

    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.pv-retirer-document');
        if (!bouton) return;
        if (!await window.confirmer('Retirer « ' + bouton.dataset.nom + ' » ?', {
            texte: 'Le fichier sera supprimé.',
            action: 'Retirer',
            ton: 'danger',
        })) return;
        const form = bouton.closest('.pv-form');
        const donnees = new FormData();
        donnees.set('csrf', form.elements.csrf.value);
        donnees.set('operation', 'delete_document');
        donnees.set('demande', bouton.dataset.demande);
        donnees.set('type', bouton.dataset.type);
        donnees.set('document', bouton.dataset.document);
        envoyer(donnees, form.querySelectorAll('.pv-message'), [bouton], ouverts());
    });

    // ===== Ce qu'on vient de choisir =====
    // Le bouton dit ce qui est sélectionné, et la liste des fichiers choisis s'affiche
    // dessous — avec une vignette pour les photos : rien ne confirme sinon qu'on a pris le
    // bon fichier avant d'avoir enregistré.
    document.addEventListener('change', evenement => {
        const champ = evenement.target.closest('.pv-ajout-input');
        if (!champ) return;
        const ajout = champ.closest('.pv-ajout');
        const texte = ajout.querySelector('.pv-ajout-texte');
        const photos = champ.name === 'photos[]';
        const fichiers = Array.from(champ.files || []);
        texte.textContent = !fichiers.length
            ? (photos ? 'Ajouter des photos' : 'Ajouter des documents')
            : (fichiers.length === 1 ? fichiers[0].name : fichiers.length + ' fichiers choisis');

        // Les adresses de la sélection précédente sont révoquées : le navigateur garde sinon
        // chaque fichier en mémoire jusqu'au rechargement de la page.
        let choisis = ajout.nextElementSibling?.classList.contains('pv-choisis') ? ajout.nextElementSibling : null;
        if (choisis) {
            choisis.querySelectorAll('[data-objet]').forEach(el => URL.revokeObjectURL(el.dataset.objet));
            choisis.remove();
        }
        if (!fichiers.length) return;

        choisis = document.createElement('ul');
        choisis.className = 'pv-choisis';
        fichiers.forEach(fichier => {
            const item = document.createElement('li');
            if (fichier.type.startsWith('image/')) {
                const adresse = URL.createObjectURL(fichier);
                const image = document.createElement('img');
                image.dataset.objet = adresse;
                image.src = adresse;
                image.alt = '';
                item.appendChild(image);
            }
            const nom = document.createElement('span');
            nom.textContent = fichier.name;
            item.appendChild(nom);
            choisis.appendChild(item);
        });
        ajout.insertAdjacentElement('afterend', choisis);
    });
})();
