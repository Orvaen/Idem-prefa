(() => {
    // ===== Le volet d'une demande =====
    // Un volet par demande, ouvert par « Renseigner » ou « Voir ». Un seul ouvert à la fois,
    // comme sur les autres listes.
    $(document).on('click', '.pv-ouvrir', function () {
        const volet = document.getElementById(this.getAttribute('aria-controls'));
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
        this.setAttribute('aria-expanded', String(ouvrir));
    });

    $(document).on('click', '#pv-page .prefa-detail-close', function () {
        const cible = this.dataset.closeTarget;
        document.querySelectorAll(`[aria-controls="${cible}"]`).forEach(b => b.setAttribute('aria-expanded', 'false'));
    });

    // Le volet ouvert est rouvert après rechargement : on enregistre PV par PV, et il serait
    // pénible de rouvrir la demande à chaque fois.
    const ouverts = () => Array.from(document.querySelectorAll('#pv-page .prefa-detail-row:not([hidden])')).map(ligne => ligne.id);

    function recharger(deplies = [], ensuite = null) {
        $('#content').load('pages/pv.php', function (_, statut) {
            if (statut === 'error') {
                $(this).text('Impossible de charger les PV. Actualisez.');
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

    $(document).on('click', '.pv-refresh', () => recharger(ouverts()));

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

    $(document).on('input', '#pv-recherche', filtrer);
    $(document).on('click', '.pv-onglet', function () {
        document.querySelectorAll('.pv-onglet').forEach(o => o.setAttribute('aria-pressed', String(o === this)));
        filtrer();
    });

    // ===== L'enregistrement =====
    function envoyer(donnees, message, boutons, deplies) {
        boutons.prop('disabled', true);
        message.removeClass('error').text('Enregistrement…');
        $.ajax({ url: 'actions/pv.php', method: 'POST', data: donnees, processData: false, contentType: false, dataType: 'json' })
            .done(reponse => {
                if (window.toast) window.toast(reponse.message || 'Enregistré.');
                // Un bon déposé sur la réception matériel se lit tout de suite : commandes.js
                // écoute cet événement et remplit la commande dans le volet rouvert.
                const bons = reponse.bons || [];
                const demande = donnees.get('demande');
                recharger(deplies, bons.length ? () => document.dispatchEvent(
                    new CustomEvent('pv:bons-deposes', { detail: { demande, bons } })) : null);
            })
            .fail(xhr => {
                message.addClass('error').text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.');
                boutons.prop('disabled', false);
            });
    }

    $(document).on('submit', '.pv-form', function (event) {
        event.preventDefault();
        envoyer(new FormData(this), $(this).find('.pv-message'), $(this).find('.pv-enregistrer'), ouverts());
    });

    // L'état choisi dans le menu de la cellule part tout seul : pas de formulaire à ouvrir.
    $(document).on('click', '.pv-etat-choix', function () {
        if (this.getAttribute('aria-checked') === 'true') return;
        const declencheur = this.closest('.pv-cellule').querySelector('.pv-etat-declencheur');
        const donnees = new FormData();
        donnees.set('csrf', this.dataset.csrf);
        donnees.set('operation', 'statut');
        donnees.set('demande', this.dataset.demande);
        donnees.set('type', this.dataset.type);
        donnees.set('statut', this.dataset.statut);
        donnees.set('revision', this.dataset.revision);
        declencheur.disabled = true;
        $.ajax({ url: 'actions/pv.php', method: 'POST', data: donnees, processData: false, contentType: false, dataType: 'json' })
            .done(reponse => {
                if (window.toast) window.toast(reponse.message || 'Enregistré.');
                recharger(ouverts());
            })
            .fail(xhr => {
                declencheur.disabled = false;
                if (window.toast) window.toast(xhr.responseJSON?.message || 'Changement impossible. Réessayez.', { erreur: true });
            });
    });

    $(document).on('click', '.pv-retirer-document', function () {
        if (!window.confirm('Retirer « ' + this.dataset.nom + ' » ? Le fichier sera supprimé.')) return;
        const form = this.closest('.pv-form');
        const donnees = new FormData();
        donnees.set('csrf', form.elements.csrf.value);
        donnees.set('operation', 'delete_document');
        donnees.set('demande', this.dataset.demande);
        donnees.set('type', this.dataset.type);
        donnees.set('document', this.dataset.document);
        envoyer(donnees, $(form).find('.pv-message'), $(this), ouverts());
    });

    // ===== Ce qu'on vient de choisir =====
    // Le bouton dit ce qui est sélectionné, et la liste des fichiers choisis s'affiche
    // dessous — avec une vignette pour les photos : rien ne confirme sinon qu'on a pris le
    // bon fichier avant d'avoir enregistré.
    $(document).on('change', '.pv-ajout-input', function () {
        const ajout = this.closest('.pv-ajout');
        const texte = ajout.querySelector('.pv-ajout-texte');
        const photos = this.name === 'photos[]';
        const fichiers = Array.from(this.files || []);
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
