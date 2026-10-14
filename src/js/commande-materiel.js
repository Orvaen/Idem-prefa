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
                id: ligne.dataset.ligne || '',
                reference: valeur('reference'),
                designation: valeur('designation'),
                date_livraison: valeur('date_livraison'),   // jj/mm/aaaa, le serveur sait lire les deux
                unite: valeur('unite'),
                quantite: valeur('quantite'),
            };
        });
    }

    /** L'état de réception d'une ligne, posé en tête de celle-ci. */
    function caseReception(rangee) {
        const cellule = document.createElement('td');
        cellule.className = 'materiel-colonne-recu';
        const bouton = document.createElement('button');
        bouton.type = 'button';
        // « bouton-discret » : le marqueur qui sort de la forme de référence des boutons
        // de formulaire, qui sinon les peint tous en bleu plein — reçu comme pas reçu.
        bouton.className = 'materiel-recu bouton-discret';
        bouton.setAttribute('aria-pressed', 'false');
        bouton.innerHTML = '<span class="materiel-recu-point" aria-hidden="true"></span>'
            + '<span class="materiel-recu-mot">Pas reçu</span>';
        cellule.appendChild(bouton);
        rangee.appendChild(cellule);
        return cellule;
    }

    /** Une ligne d'une commande validée : du texte, et rien qui se saisisse — sauf sa case. */
    function ajouterLigneFigee(ligne) {
        const rangee = document.createElement('tr');
        rangee.className = 'materiel-edition-ligne';
        caseReception(rangee);
        // La date se lit à la française : le format de la base est celui du champ de
        // saisie, et une commande validée n'en a pas.
        for (const champ of ['reference', 'designation', 'date_livraison_lue', 'unite', 'quantite']) {
            const cellule = document.createElement('td');
            cellule.textContent = ligne[champ] || '—';
            if (champ === 'quantite') cellule.className = 'materiel-champ-nombre';
            rangee.appendChild(cellule);
        }
        rangee.appendChild(document.createElement('td'));
        dialogue().querySelector('.materiel-edition-lignes').appendChild(rangee);
        marquerRetard(rangee, ligne.en_retard);
        return rangee;
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
            // La date se lit comme sur le bon : le format de la base n'est bon que pour
            // la base, et un champ de type date suivait la langue du navigateur.
            const date = rangee.querySelector('[data-champ="date_livraison"]');
            if (date) date.value = ligne.date_livraison_lue || '';
            marquerRetard(rangee, ligne.en_retard);
        }
        corps.appendChild(neuve);
        return rangee;
    }

    /** Une échéance passée sur un article qui n'est pas arrivé se signale. */
    function marquerRetard(rangee, enRetard) {
        const cellule = rangee.querySelector('[data-champ="date_livraison"]')?.closest('td')
            || rangee.children[3];
        if (!cellule) return;
        cellule.classList.toggle('est-en-retard', !!enRetard);
        if (enRetard) cellule.title = 'Échéance dépassée : cet article n’est pas arrivé.';
        else cellule.removeAttribute('title');
    }

    /** Dit l'état de réception d'une ligne, en toutes lettres. */
    function direReception(rangee, recu, quand) {
        const bouton = rangee.querySelector('.materiel-recu');
        if (!bouton) return;
        bouton.setAttribute('aria-pressed', String(recu));
        bouton.querySelector('.materiel-recu-mot').textContent = recu ? 'Reçu' : 'Pas reçu';
        bouton.title = recu && quand ? quand : (recu ? 'Cliquer pour revenir à « Pas reçu »' : 'Cliquer pour marquer reçu');
        rangee.classList.toggle('est-recue', recu);
    }

    /** Pose sur une rangée ce qui la rattache à la base : son identifiant et sa réception. */
    function poserReception(rangee, ligne) {
        if (!ligne || !ligne.id) return;
        rangee.dataset.ligne = ligne.id;
        direReception(rangee, !!ligne.recu, ligne.recu_le);
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
        fenetre.querySelector('#materiel-dialog-titre').textContent = bon.numero || 'Numéro non reconnu';
        fenetre.querySelector('.materiel-dialog-sous-titre').textContent = [
            ligne ? ligne.dataset.demande : '',
            bon.articles ? bon.articles + ' article' + (bon.articles > 1 ? 's' : '') : '',
            // Ce qui est arrivé se compte ici, en chiffres : c'est l'information qu'on
            // vient chercher, pas la phrase qui l'enrobait.
            bon.recus ? bon.recus + ' reçu' + (bon.recus > 1 ? 's' : '') : '',
            bon.retards ? bon.retards + ' en retard' : '',
        ].filter(Boolean).join(' · ');

        const pastille = fenetre.querySelector('.materiel-dialog-etat');
        pastille.className = 'materiel-etat materiel-dialog-etat materiel-etat-' + bon.etat;
        pastille.textContent = bon.etat_libelle;
        pastille.title = bon.resume;

        const note = fenetre.querySelector('.materiel-dialog-note');
        note.textContent = noteDeLecture(bon);
        note.hidden = note.textContent === '';

        // Une commande validée ne se modifie plus : la fenêtre s'ouvre en lecture, sans
        // champ ni bouton d'écriture. La feuille de style se charge de les retirer.
        fenetre.classList.toggle('est-figee', !!bon.fige);
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
        (bon.lignes || []).forEach(l => {
            poserReception(bon.fige ? ajouterLigneFigee(l) : ajouterLigne(l, l.douteuse), l);
        });
        compterLignes();
        majCaseTout();

        if (!fenetre.open) fenetre.showModal();
    }

    /**
     * Ce qu'il reste à dire quand la pastille ne suffit pas : ce qu'on attend de vous, ou
     * pourquoi la lecture a échoué. Devant une liste lue ou vérifiée, rien.
     */
    function noteDeLecture(bon) {
        if (bon.etat === 'a_lire') return 'Lisez le bon, ou saisissez ses articles à la main.';
        if (bon.etat === 'echec') return bon.resume;
        // Sur une commande validée, la pastille le dit et les boutons d'écriture ont
        // disparu : une phrase de plus ne ferait que redire ce qui se voit. Qui l'a
        // validée et quand restent au survol de la pastille.
        return '';
    }

    // ---------------------------------------------------- LA RÉCEPTION
    //
    // Cocher une ligne la déclare reçue. Le pointage part tout seul, groupé : on coche
    // rarement une ligne isolée, et un bouton « enregistrer » de plus dans une fenêtre
    // qui en a déjà un pour la commande ferait hésiter sur ce qu'on enregistre.

    let aPointer = {};
    let minuteur = null;

    const estRecue = rangee => rangee.classList.contains('est-recue');

    /** Le bouton de lot dit ce qu'il va faire, pas ce qui est. */
    function majCaseTout() {
        const fenetre = dialogue();
        const lignes = [...fenetre.querySelectorAll('.materiel-edition-ligne')];
        const bouton = fenetre.querySelector('.materiel-tout-recu');
        if (!bouton) return;
        const toutes = lignes.length > 0 && lignes.every(estRecue);
        bouton.textContent = toutes ? 'Tout marquer non reçu' : 'Tout marquer reçu';
        bouton.disabled = lignes.length === 0;
    }

    function envoyerPointage() {
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        const lot = aPointer;
        aPointer = {};
        if (!id || !Object.keys(lot).length) return;

        const mot = fenetre.querySelector('.materiel-message');
        mot.textContent = 'Pointage…';

        $.ajax({
            url: 'actions/receptionner_bon_materiel.php',
            method: 'POST',
            data: {
                csrf: fenetre.querySelector('[name="csrf"]').value,
                document: id,
                lignes: JSON.stringify(lot),
            },
            dataType: 'json',
        })
            .done(reponse => {
                mot.textContent = reponse.message || '';
                rafraichirRang(reponse.document);
                // Le haut de la fenêtre compte les reçus : sans cela il gardait le compte
                // d'avant le pointage, et contredisait les lignes qu'on venait de changer.
                poserEntete(reponse.document);
            })
            .fail(xhr => {
                mot.textContent = xhr.responseJSON?.message || 'Pointage impossible. Réessayez.';
            });
    }

    function pointer(rangee, recu) {
        const id = rangee.dataset.ligne;
        if (!id) return;
        direReception(rangee, recu, '');
        // Un article arrivé n'est plus en retard, quelle que soit son échéance.
        if (recu) marquerRetard(rangee, false);
        aPointer[id] = recu;
        clearTimeout(minuteur);
        minuteur = setTimeout(envoyerPointage, 400);
    }

    $(document).on('click', '.materiel-recu', function () {
        const rangee = this.closest('.materiel-edition-ligne');
        pointer(rangee, !estRecue(rangee));
        majCaseTout();
    });

    $(document).on('click', '.materiel-tout-recu', function () {
        const lignes = [...dialogue().querySelectorAll('.materiel-edition-ligne')];
        const recu = !lignes.every(estRecue);
        lignes.forEach(r => pointer(r, recu));
        majCaseTout();
    });

    // Un clic sur la ligne la coche ; un double-clic ouvre la case qu'on vise. Les deux
    // gestes partagent le même premier clic, d'où l'attente : sans elle, ouvrir une case
    // ferait clignoter le pointage au passage.
    const DELAI_DOUBLE_CLIC = 220;
    let clicEnAttente = null;

    function ouvrirCase(cellule) {
        const champ = cellule.querySelector('.materiel-champ[readonly]');
        if (!champ) return false;
        champ.readOnly = false;
        champ.focus();
        if (champ.type !== 'date') champ.select();
        return true;
    }

    function fermerCases(sauf) {
        dialogue().querySelectorAll('.materiel-champ:not([readonly])').forEach(champ => {
            if (champ !== sauf) champ.readOnly = true;
        });
    }

    $(document).on('click', '.materiel-edition-ligne', function (event) {
        // La case à cocher et les boutons gardent leur propre geste, et une case déjà
        // ouverte n'est pas un endroit où l'on pointe.
        if (event.target.closest('.materiel-colonne-recu, button, label, a')) return;
        if (event.target.closest('.materiel-champ:not([readonly])')) return;

        const rangee = this;
        clearTimeout(clicEnAttente);
        clicEnAttente = setTimeout(() => {
            fermerCases(null);
            pointer(rangee, !estRecue(rangee));
            majCaseTout();
        }, DELAI_DOUBLE_CLIC);
    });

    $(document).on('dblclick', '.materiel-edition-ligne td', function (event) {
        clearTimeout(clicEnAttente);
        if (event.target.closest('.materiel-colonne-recu, button, label, a')) return;
        fermerCases(null);
        ouvrirCase(this);
    });

    // Une case se referme dès qu'on la quitte, et Entrée ou Échap valent sortie : il n'y a
    // rien à confirmer ligne par ligne, c'est « Enregistrer » qui écrit.
    $(document).on('blur', '.materiel-champ', function () {
        this.readOnly = true;
    });

    $(document).on('keydown', '.materiel-champ', function (event) {
        if (event.key !== 'Enter' && event.key !== 'Escape') return;
        event.preventDefault();
        this.readOnly = true;
        this.blur();
    });

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
        // Elle est vide : inutile de demander un double-clic pour commencer à la remplir.
        const champ = rangee.querySelector('.materiel-champ');
        if (champ) {
            champ.readOnly = false;
            champ.focus();
        }
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
        const recus = ligne.querySelector('.materiel-cellule-recus');
        if (recus) {
            if (bon.articles) recus.textContent = (bon.recus || 0) + '/' + bon.articles;
            else recus.innerHTML = '<span class="materiel-vide">—</span>';
        }
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
