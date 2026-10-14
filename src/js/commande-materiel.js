(() => {
    'use strict';

    // L'écran « Commande matériel » : les bons déposés sur les demandes, et la fenêtre où
    // on corrige ce que la lecture a cru comprendre.
    //
    // Délégation sur le document : la page arrive par AJAX, comme les autres écrans du
    // tableau de bord, et son balisage n'existe pas au chargement du script.

    const PAGE = '#materiel-page';

    const page = () => document.querySelector(PAGE);
    const dialogue = () => document.getElementById('materiel-dialog');
    const rang = id => document.getElementById('materiel-bon-' + id);

    /** Ce que porte une ligne du tableau : le bon, tel que le serveur l'a mis en forme. */
    function bonDuRang(ligne) {
        try {
            return JSON.parse(ligne.dataset.bon || '{}');
        } catch (erreur) {
            return {};
        }
    }

    // ------------------------------------------------------------------ LES FILTRES

    function filtrer() {
        const zone = page();
        if (!zone) return;

        const recherche = (zone.querySelector('#materiel-filtre-recherche')?.value || '').trim().toLowerCase();
        const etat = zone.querySelector('#materiel-filtre-etat')?.value || '';
        const affaire = zone.querySelector('#materiel-filtre-affaire')?.value || '';

        let visibles = 0;
        zone.querySelectorAll('.materiel-rang').forEach(ligne => {
            // « À lire ou à vérifier » regroupe les deux états sur lesquels il reste
            // quelque chose à faire : c'est la question qu'on se pose en arrivant.
            const etatOk = !etat
                || (etat === 'a_faire' ? ['a_lire', 'a_verifier'].includes(ligne.dataset.etat) : ligne.dataset.etat === etat);
            const montrer = etatOk
                && (!affaire || ligne.dataset.affaire === affaire)
                && (!recherche || (ligne.dataset.recherche || '').includes(recherche));
            ligne.hidden = !montrer;
            if (montrer) visibles++;
        });

        const compte = zone.querySelector('#materiel-compte');
        const libelle = zone.querySelector('#materiel-compte-libelle');
        if (compte) compte.textContent = visibles;
        if (libelle) libelle.textContent = visibles === 1 ? 'bon de commande' : 'bons de commande';
        const vide = zone.querySelector('.materiel-aucun-resultat');
        if (vide) vide.hidden = visibles > 0 || !zone.querySelector('.materiel-rang');
    }

    $(document).on('input', '#materiel-filtre-recherche', filtrer);
    $(document).on('change', '#materiel-filtre-etat, #materiel-filtre-affaire', filtrer);

    // ------------------------------------------------------- LA FENÊTRE D'ÉDITION

    /** Les valeurs saisies, dans l'ordre des lignes. */
    function lignesSaisies() {
        return [...dialogue().querySelectorAll('.materiel-edition-ligne')].map(ligne => {
            const valeur = champ => ligne.querySelector(`[data-champ="${champ}"]`).value.trim();
            return {
                reference: valeur('reference'),
                designation: valeur('designation'),
                date_livraison: valeur('date_livraison'),
                unite: valeur('unite'),
                quantite: valeur('quantite'),
            };
        });
    }

    function ajouterLigne(ligne, douteuse) {
        const corps = dialogue().querySelector('.materiel-edition-lignes');
        const gabarit = document.getElementById('materiel-gabarit');
        const neuve = gabarit.content.cloneNode(true);
        const rangee = neuve.querySelector('.materiel-edition-ligne');
        // Rien ne distingue plus une ligne douteuse à l'œil : la raison reste au survol,
        // pour qui se demande d'où sort une quantité.
        if (douteuse) {
            rangee.title = 'Lecture incertaine : reconnaissance peu sûre, ou quantité en désaccord avec le total de la ligne.';
        }
        if (ligne) {
            for (const [champ, valeur] of Object.entries(ligne)) {
                const champSaisie = rangee.querySelector(`[data-champ="${champ}"]`);
                if (champSaisie) champSaisie.value = valeur ?? '';
            }
        }
        corps.appendChild(neuve);
        return rangee;
    }

    function compterLignes() {
        const fenetre = dialogue();
        const nombre = fenetre.querySelectorAll('.materiel-edition-ligne').length;
        fenetre.querySelector('.materiel-edition-vide').hidden = nombre > 0;
        fenetre.querySelector('.materiel-dialog-scroll').hidden = nombre === 0;
    }

    /** Le haut de la fenêtre : ce qui identifie le bon, et où il en est. */
    function poserEntete(bon) {
        const fenetre = dialogue();
        const ligne = rang(bon.id);

        // Le numéro est le nom du bon. Sans lui — lecture ratée, en-tête illisible — on
        // retombe sur un intitulé générique plutôt que sur un titre vide.
        fenetre.querySelector('#materiel-dialog-titre').textContent = bon.numero || 'Articles du bon';
        fenetre.querySelector('.materiel-dialog-sous-titre').textContent = [
            ligne ? ligne.dataset.demande : '',
            bon.articles ? bon.articles + ' article' + (bon.articles > 1 ? 's' : '') : '',
        ].filter(Boolean).join(' · ');

        const pastille = fenetre.querySelector('.materiel-dialog-etat');
        pastille.className = 'materiel-etat materiel-dialog-etat materiel-etat-' + bon.etat;
        pastille.textContent = bon.etat_libelle;
        pastille.title = bon.resume;

        const note = fenetre.querySelector('.materiel-dialog-note');
        note.textContent = noteDeLecture(bon);
        note.hidden = note.textContent === '';
    }

    /** Remplit la fenêtre d'un bon et l'ouvre. */
    function ouvrir(id) {
        const ligne = rang(id);
        if (!ligne) return;
        const bon = bonDuRang(ligne);
        const fenetre = dialogue();

        fenetre.querySelector('[name="document"]').value = id;
        poserEntete(bon);
        fenetre.querySelector('.materiel-message').textContent = '';

        fenetre.querySelector('.materiel-edition-lignes').innerHTML = '';
        (bon.lignes || []).forEach(l => ajouterLigne(l, l.douteuse));
        compterLignes();

        if (!fenetre.open) fenetre.showModal();
    }

    /**
     * Ce qu'il reste à dire quand la pastille ne suffit pas : ce qu'on attend de vous, ou
     * pourquoi la lecture a échoué. Devant une liste lue ou vérifiée, rien.
     */
    function noteDeLecture(bon) {
        if (bon.etat === 'a_lire') return 'Lisez le bon, ou saisissez ses articles à la main.';
        if (bon.etat === 'echec') return bon.resume;
        return '';
    }

    $(document).on('click', '.materiel-ouvrir', function () {
        ouvrir(this.dataset.document);
    });

    // Depuis la fiche d'une demande : on bascule sur l'écran, puis on ouvre le bon. La
    // page arrive en AJAX, d'où l'attente — même geste que « Voir les affectations ».
    $(document).on('click', '.btn-goto-commande-materiel', function () {
        const id = this.dataset.document;
        $('#btn-commande-materiel').trigger('click');
        if (!id) return;
        const attente = setInterval(() => {
            if (!rang(id)) return;
            clearInterval(attente);
            rang(id).scrollIntoView({behavior: 'smooth', block: 'center'});
            ouvrir(id);
        }, 50);
        setTimeout(() => clearInterval(attente), 3000);
    });

    $(document).on('click', '.materiel-fermer', function () {
        dialogue().close();
    });

    // Cliquer hors de la fenêtre la referme, comme les autres fenêtres de l'application.
    $(document).on('click', '#materiel-dialog', function (event) {
        if (event.target !== this) return;
        const cadre = this.getBoundingClientRect();
        const dehors = event.clientX < cadre.left || event.clientX > cadre.right
            || event.clientY < cadre.top || event.clientY > cadre.bottom;
        if (dehors) this.close();
    });

    $(document).on('click', '.materiel-ajouter', function () {
        const rangee = ajouterLigne(null, false);
        compterLignes();
        rangee.querySelector('input').focus();
    });

    $(document).on('click', '.materiel-retirer', function () {
        this.closest('.materiel-edition-ligne').remove();
        compterLignes();
    });

    // Une ligne qu'on vient de corriger n'est plus celle dont la lecture doutait.
    $(document).on('input', '.materiel-champ', function () {
        this.closest('.materiel-edition-ligne').removeAttribute('title');
    });

    // ------------------------------------------------- LIRE, ET ENREGISTRER

    /** Remet à jour la ligne du tableau avec ce que le serveur vient d'enregistrer. */
    function rafraichirRang(bon) {
        const ligne = rang(bon.id);
        if (!ligne) return;
        ligne.dataset.bon = JSON.stringify(bon);
        ligne.dataset.etat = bon.etat;

        const numero = ligne.querySelector('.materiel-cellule-numero');
        numero.innerHTML = '';
        if (bon.numero) {
            const nom = document.createElement('span');
            nom.className = 'materiel-numero';
            nom.textContent = bon.numero;
            numero.appendChild(nom);
        } else {
            numero.innerHTML = '<span class="materiel-vide">—</span>';
        }

        const livraison = ligne.querySelector('.materiel-cellule-livraison');
        if (bon.livraison) {
            livraison.textContent = bon.livraison;
        } else {
            livraison.innerHTML = '<span class="materiel-vide">—</span>';
        }
        ligne.querySelector('.materiel-cellule-articles').textContent = bon.articles || '—';
        const etat = ligne.querySelector('.materiel-etat');
        etat.className = 'materiel-etat materiel-etat-' + bon.etat;
        etat.textContent = bon.etat_libelle;
        etat.title = bon.resume;
        ligne.querySelector('.materiel-ouvrir').textContent =
            bon.etat === 'a_lire' ? 'Lire le bon' : 'Voir et corriger';
        filtrer();
    }

    function envoyer(url, donnees, bouton, apres) {
        const fenetre = dialogue();
        const message = fenetre.querySelector('.materiel-message');
        const libelle = bouton.textContent;
        bouton.disabled = true;
        message.textContent = bouton === fenetre.querySelector('.materiel-relire')
            ? 'Lecture du bon…' : 'Enregistrement…';

        $.ajax({url: 'actions/' + url, method: 'POST', data: donnees, dataType: 'json'})
            .done(reponse => {
                message.textContent = reponse.message || '';
                rafraichirRang(reponse.document);
                apres(reponse);
            })
            .fail(xhr => {
                message.textContent = xhr.responseJSON?.message || 'Opération impossible. Réessayez.';
            })
            .always(() => {
                bouton.disabled = false;
                bouton.textContent = libelle;
            });
    }

    $(document).on('click', '.materiel-relire', function () {
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        if (!id) return;
        // Une relecture remplace ce qui est à l'écran : on relit un bon pour corriger une
        // première lecture, pas pour en empiler une seconde.
        if (fenetre.querySelectorAll('.materiel-edition-ligne').length
            && !window.confirm('Relire le bon remplacera les articles affichés. Continuer ?')) return;

        envoyer('lire_bon_materiel.php', {
            csrf: fenetre.querySelector('[name="csrf"]').value,
            document: id,
        }, this, reponse => {
            fenetre.querySelector('.materiel-edition-lignes').innerHTML = '';
            (reponse.document.lignes || []).forEach(l => ajouterLigne(l, l.douteuse));
            compterLignes();
            poserEntete(reponse.document);
        });
    });

    $(document).on('submit', '#materiel-form', function (event) {
        event.preventDefault();
        const fenetre = dialogue();
        const bouton = this.querySelector('button[type="submit"]');

        envoyer('save_bon_materiel.php', {
            csrf: this.elements.csrf.value,
            document: this.elements.document.value,
            lignes: JSON.stringify(lignesSaisies()),
        }, bouton, reponse => {
            poserEntete(reponse.document);
            fenetre.querySelectorAll('.materiel-edition-ligne[title]')
                .forEach(ligne => ligne.removeAttribute('title'));
        });
    });
})();
