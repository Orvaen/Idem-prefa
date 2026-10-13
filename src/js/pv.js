(() => {
    // Le volet ouvert est rouvert après rechargement : on enregistre PV par PV et il
    // serait pénible de rouvrir la demande à chaque fois.
    const ouverts = () => Array.from(document.querySelectorAll('#pv-page .prefa-detail-row:not([hidden])')).map(ligne => ligne.id);

    function recharger(deplies = []) {
        $('#content').load('pages/pv.php', function (_, statut) {
            if (statut === 'error') {
                $(this).text('Impossible de charger les PV. Actualisez.');
                return;
            }
            deplies.forEach(id => {
                const ligne = document.getElementById(id);
                if (ligne) ligne.hidden = false;
                document.querySelectorAll('.prefa-toggle[aria-controls="' + id + '"]').forEach(bouton => bouton.setAttribute('aria-expanded', 'true'));
            });
        });
    }

    $(document).on('click', '.pv-refresh', () => recharger(ouverts()));

    // Filtrage sans aller-retour serveur : les quatre PV de chaque demande sont deja
    // dans la page, il n'y a qu'a masquer les lignes qui ne correspondent pas.
    function filtrer() {
        const texte = (document.getElementById('pv-filtre-recherche')?.value || '').trim().toLowerCase();
        const avancement = document.getElementById('pv-filtre-avancement')?.value || '';
        const demandeur = document.getElementById('pv-filtre-demandeur')?.value || '';
        let visibles = 0;
        document.querySelectorAll('#pv-page .pv-ligne').forEach(ligne => {
            const restants = Number(ligne.dataset.restants || 0);
            const garder = (!texte || (ligne.dataset.recherche || '').includes(texte))
                && (!demandeur || ligne.dataset.demandeur === demandeur)
                && (avancement !== 'restants' || restants > 0)
                && (avancement !== 'complets' || restants === 0);
            ligne.hidden = !garder;
            // Un volet laisse ouvert sur une ligne masquee resterait affiche tout seul.
            if (!garder) {
                ligne.querySelectorAll('.prefa-detail-row').forEach(volet => { volet.hidden = true; });
                ligne.querySelectorAll('.prefa-toggle[aria-expanded="true"]').forEach(bouton => bouton.setAttribute('aria-expanded', 'false'));
            }
            if (garder) visibles++;
        });
        const compte = document.getElementById('pv-compte');
        if (compte) compte.textContent = visibles;
        const libelle = document.getElementById('pv-compte-libelle');
        if (libelle) libelle.textContent = visibles === 1 ? 'demande affichée' : 'demandes affichées';
    }

    $(document).on('input', '#pv-filtre-recherche', filtrer);
    $(document).on('change', '#pv-filtre-avancement, #pv-filtre-demandeur', filtrer);

    function envoyer(donnees, message, boutons, deplies) {
        boutons.prop('disabled', true);
        message.text('Enregistrement…');
        $.ajax({ url: 'actions/pv.php', method: 'POST', data: donnees, processData: false, contentType: false, dataType: 'json' })
            .done(reponse => { message.text(reponse.message || 'Enregistré.'); recharger(deplies); })
            .fail(xhr => message.text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.'))
            .always(() => boutons.prop('disabled', false));
    }

    $(document).on('submit', '.pv-form', function (event) {
        event.preventDefault();
        envoyer(new FormData(this), $(this).find('.atelier-message'), $(this).find('button'), ouverts());
    });

    // L'aperçu d'un document déposé, déplié sur place. Le fichier n'est demandé qu'au
    // premier dépli : une liste de cinq PDF les chargerait tous avec la page, pour des
    // aperçus que personne n'a encore ouverts.
    $(document).on('click', '.pv-apercu-bascule', function () {
        const zone = this.closest('.pv-document').querySelector('.pv-apercu');
        const ouvert = this.getAttribute('aria-expanded') === 'true';

        if (!ouvert && !zone.firstElementChild) {
            if (this.dataset.image === '1') {
                const image = document.createElement('img');
                image.src = this.dataset.src;
                image.alt = this.dataset.nom;
                zone.appendChild(image);
            } else {
                const cadre = document.createElement('iframe');
                cadre.src = this.dataset.src;
                cadre.title = 'Aperçu de ' + this.dataset.nom;
                zone.appendChild(cadre);
            }
        }

        zone.hidden = ouvert;
        this.setAttribute('aria-expanded', ouvert ? 'false' : 'true');
        this.textContent = ouvert ? 'Aperçu' : 'Masquer';
    });

    // La zone de dépôt remplace l'input natif : c'est à elle d'annoncer ce qui est sélectionné.
    // Elle montre aussi ce qui vient d'être choisi : sans cela on dépose un bon de commande
    // et rien ne confirme que c'est le bon fichier avant de l'avoir enregistré.
    $(document).on('change', '.pv-fichiers-input', function () {
        const zone = this.closest('.pv-fichiers');
        const texte = zone.querySelector('.pv-fichiers-texte');
        const fichiers = Array.from(this.files || []);
        if (!fichiers.length) {
            texte.textContent = 'Ajouter des documents';
        } else if (fichiers.length === 1) {
            texte.textContent = fichiers[0].name;
        } else {
            texte.textContent = fichiers.length + ' fichiers sélectionnés';
        }

        // La liste se pose après la zone de dépôt, pas dedans : « .pv-depot » est une grille
        // à deux colonnes — le champ et le bouton « Enregistrer » — et un troisième enfant
        // s'y retrouverait coincé sur une demi-largeur.
        const depot = zone.closest('.pv-depot');
        const formulaire = this.closest('.pv-form');

        // Les adresses de la sélection précédente sont révoquées : le navigateur garde
        // sinon chaque fichier en mémoire jusqu'au rechargement de la page.
        let choisis = formulaire.querySelector('.pv-choisis');
        if (choisis) {
            choisis.querySelectorAll('[data-objet]').forEach(el => URL.revokeObjectURL(el.dataset.objet));
            choisis.remove();
        }
        if (!fichiers.length) return;

        choisis = document.createElement('ul');
        choisis.className = 'pv-choisis';
        fichiers.forEach(fichier => {
            const item = document.createElement('li');
            const nom = document.createElement('span');
            nom.className = 'pv-choisi-nom';
            nom.textContent = fichier.name;
            item.appendChild(nom);

            const adresse = URL.createObjectURL(fichier);
            const vue = fichier.type === 'application/pdf'
                ? document.createElement('iframe')
                : (fichier.type.startsWith('image/') ? document.createElement('img') : null);
            if (vue) {
                vue.dataset.objet = adresse;
                vue.src = adresse;
                if (vue.tagName === 'IMG') vue.alt = fichier.name; else vue.title = 'Aperçu de ' + fichier.name;
                item.appendChild(vue);
            }
            choisis.appendChild(item);
        });
        depot.insertAdjacentElement('afterend', choisis);
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
        envoyer(donnees, $(form).find('.atelier-message'), $(this), ouverts());
    });
})();
