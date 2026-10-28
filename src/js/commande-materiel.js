(() => {
    'use strict';

    // L'écran « Commande matériel » : les bons déposés sur les demandes, et la fenêtre où
    // on corrige ce que la lecture a cru comprendre.
    //
    // Délégation sur le document : la page arrive par AJAX, comme les autres écrans du
    // tableau de bord, et son balisage n'existe pas au chargement du script.

    const PAGE = '#materiel-page';

    const page = () => document.querySelector(PAGE);
    // Le volet du bon : un seul pour l'écran, rempli à chaque ouverture.
    const dialogue = () => document.getElementById('materiel-volet');
    const rang = id => document.getElementById('materiel-bon-' + id);

    /** Ce que porte une ligne du tableau : le bon, tel que le serveur l'a mis en forme. */
    function bonDuRang(ligne) {
        try {
            return JSON.parse(ligne.dataset.bon || '{}');
        } catch (erreur) {
            return {};
        }
    }

    // ------------------------------------------------------------- LES MANQUES SIGNALÉS
    //
    // L'atelier signale, l'encadrement fait avancer : vu, puis traité. Un manque traité
    // quitte la liste — elle ne garde que ce qui attend quelqu'un.

    /** La pastille de l'entrée « Commande matériel » : les manques que personne n'a vus. */
    function majPastilleManques() {
        const pastille = document.querySelector('.nav-urgent-badge[data-compteur="manques"]');
        if (!pastille) return;
        const nouveaux = document.querySelectorAll('.materiel-manque[data-statut="nouvelle"]').length;
        pastille.textContent = nouveaux;
        pastille.hidden = nouveaux === 0;
    }

    $(document).on('click', '.materiel-manque [data-traiter]', function () {
        const bouton = this;
        const ligne = bouton.closest('.materiel-manque');
        const section = bouton.closest('.materiel-manques');
        const statut = bouton.dataset.traiter;
        ligne.querySelectorAll('button').forEach(b => { b.disabled = true; });

        $.ajax({
            url: 'actions/traiter_manque.php',
            method: 'POST',
            data: { csrf: section.dataset.csrf, manque: ligne.dataset.manque, statut },
            dataType: 'json',
        })
            .done(reponse => {
                if (statut === 'traitee') {
                    ligne.remove();
                    const restants = section.querySelectorAll('.materiel-manque').length;
                    section.querySelector('[data-manques-compte]').textContent = restants;
                    if (!restants) {
                        section.querySelector('.prefa-table-scroll').hidden = true;
                        section.querySelector('.materiel-manques-vide').hidden = false;
                    }
                } else {
                    ligne.dataset.statut = statut;
                    const puce = ligne.querySelector('[data-manque-etat]');
                    puce.className = 'pv-puce ' + reponse.etat.puce;
                    puce.textContent = reponse.etat.libelle;
                    bouton.remove();
                    ligne.querySelectorAll('button').forEach(b => { b.disabled = false; });
                }
                majPastilleManques();
            })
            .fail(xhr => {
                ligne.querySelectorAll('button').forEach(b => { b.disabled = false; });
                bouton.title = xhr.responseJSON?.message || 'Opération impossible. Réessayez.';
                window.alert(bouton.title);
            });
    });

    // ------------------------------------------------------------------ LES FILTRES

    function filtrer() {
        const zone = page();
        if (!zone) return;

        const recherche = (zone.querySelector('#materiel-filtre-recherche')?.value || '').trim().toLowerCase();
        const etat = zone.querySelector('.materiel-onglet[aria-pressed="true"]')?.dataset.etat || '';

        let visibles = 0;
        const comptes = {};
        zone.querySelectorAll('.materiel-rang').forEach(ligne => {
            comptes[ligne.dataset.etat] = (comptes[ligne.dataset.etat] || 0) + 1;
            const montrer = (!etat || ligne.dataset.etat === etat)
                && (!recherche || (ligne.dataset.recherche || '').includes(recherche));
            ligne.hidden = !montrer;
            if (montrer) visibles++;
        });

        // Les comptes des onglets suivent les bons : valider une commande la fait passer
        // d'« À vérifier » à « Validée » sans recharger l'écran.
        zone.querySelectorAll('.materiel-onglet').forEach(onglet => {
            const compte = onglet.querySelector('.prefa-onglet-compte');
            if (!compte) return;
            compte.textContent = onglet.dataset.etat
                ? (comptes[onglet.dataset.etat] || 0)
                : zone.querySelectorAll('.materiel-rang').length;
        });

        const compte = zone.querySelector('#materiel-compte');
        const libelle = zone.querySelector('#materiel-compte-libelle');
        if (compte) compte.textContent = visibles;
        if (libelle) libelle.textContent = visibles === 1 ? 'bon de commande' : 'bons de commande';
        const vide = zone.querySelector('.materiel-aucun-resultat');
        if (vide) vide.hidden = visibles > 0 || !zone.querySelector('.materiel-rang');
    }

    $(document).on('input', '#materiel-filtre-recherche', filtrer);
    $(document).on('click', '.materiel-onglet', function () {
        document.querySelectorAll('.materiel-onglet').forEach(o => o.setAttribute('aria-pressed', String(o === this)));
        filtrer();
    });

    // ---------------------------------------------------------------- LE VOLET D'UN BON
    //
    // Deux tâches, deux vues. Tant que la commande n'est pas validée, on la RELIT : le
    // tableau du bon, case par case. Validée, on la REÇOIT : une liste à cocher, rangée
    // par livraison. La feuille de style montre l'une ou l'autre selon « est-figee ».

    // ----- Relire -----

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
            if (ligne.id) rangee.dataset.ligne = ligne.id;
            for (const [champ, valeur] of Object.entries(ligne)) {
                const champSaisie = rangee.querySelector(`[data-champ="${champ}"]`);
                if (champSaisie) champSaisie.value = valeur ?? '';
            }
            // La date se lit comme sur le bon : le format de la base n'est bon que pour
            // la base, et un champ de type date suivait la langue du navigateur.
            const date = rangee.querySelector('[data-champ="date_livraison"]');
            if (date) date.value = ligne.date_livraison_lue || '';
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

    // ----- Recevoir -----

    const sansAccents = texte => String(texte || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    /** Une cellule de texte, sans passer par innerHTML : une désignation peut tout porter. */
    function cellule(classe, texte) {
        const td = document.createElement('td');
        if (classe) td.className = classe;
        if (texte !== undefined) td.textContent = texte;
        return td;
    }

    /** « 13 PCE » : le nombre en clair, l'unité en sourd — une seule colonne, pas deux. */
    function celluleQuantite(ligne) {
        const td = cellule('materiel-col-nombre');
        const nombre = document.createElement('span');
        nombre.textContent = ligne.quantite;
        const unite = document.createElement('span');
        unite.className = 'materiel-unite';
        unite.textContent = ' ' + (ligne.unite || 'u');
        td.append(nombre, unite);
        return td;
    }

    function rangeeReception(ligne) {
        const tr = document.createElement('tr');
        tr.className = 'materiel-article';
        tr.dataset.ligne = ligne.id;
        tr.dataset.cherche = sansAccents((ligne.reference || '') + ' ' + ligne.designation);

        // La case : un vrai rôle de case à cocher, que l'espace coche au clavier. Toute la
        // ligne la coche aussi — c'est une liste à cocher, et sur une tablette d'atelier la
        // ligne est une cible, la case de seize pixels n'en est pas une.
        const caseTd = cellule('materiel-col-case');
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'materiel-case';
        bouton.setAttribute('role', 'checkbox');
        bouton.setAttribute('aria-label', 'Reçu : ' + ligne.designation);
        bouton.innerHTML = '<svg class="icon" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-6.5" /></svg>';
        caseTd.appendChild(bouton);

        tr.append(
            caseTd,
            cellule('materiel-col-ref', ligne.reference || '—'),
            cellule('materiel-col-nom', ligne.designation),
            celluleQuantite(ligne),
            cellule('materiel-col-nombre materiel-col-recu', ''),
        );
        dire(tr, !!ligne.recu, ligne.recu_court, ligne.recu_le);
        return tr;
    }

    /** L'état d'une ligne : sa case, son ton, et la date d'arrivée. */
    function dire(tr, recu, court, phrase) {
        tr.classList.toggle('est-recue', recu);
        const bouton = tr.querySelector('.materiel-case');
        bouton.setAttribute('aria-checked', String(recu));
        const quand = tr.querySelector('.materiel-col-recu');
        quand.textContent = recu ? (court || '') : '';
        tr.title = recu ? (phrase || 'Reçu') : '';
    }

    /** Aujourd'hui, au format de la colonne : la case vient d'être cochée ici même. */
    function aujourdhui() {
        const maintenant = new Date();
        return String(maintenant.getDate()).padStart(2, '0') + '/' + String(maintenant.getMonth() + 1).padStart(2, '0');
    }

    /**
     * La liste à cocher : un groupe par date de livraison, dans l'ordre où elles arrivent.
     * La date se lit une fois, en tête de son groupe, au lieu de seize fois dans une
     * colonne ; et « en retard » se dit une fois aussi, sur la livraison qui l'est.
     */
    function construireReception(bon) {
        const table = dialogue().querySelector('.materiel-recevoir');
        table.querySelectorAll('tbody').forEach(corps => corps.remove());

        const groupes = new Map();
        (bon.lignes || []).forEach(ligne => {
            const cle = ligne.date_livraison || '';
            if (!groupes.has(cle)) groupes.set(cle, { date: ligne.date_livraison_lue, lignes: [] });
            groupes.get(cle).lignes.push(ligne);
        });
        // Les dates d'abord, dans l'ordre ; les articles sans date à la fin.
        const cles = [...groupes.keys()].sort((x, y) => (x === '') - (y === '') || x.localeCompare(y));

        cles.forEach(cle => {
            const groupe = groupes.get(cle);
            const corps = document.createElement('tbody');
            corps.className = 'materiel-groupe';
            corps.dataset.echue = groupe.lignes.some(l => l.echue) ? '1' : '';

            const tete = document.createElement('tr');
            tete.className = 'materiel-groupe-tete';
            const th = document.createElement('th');
            th.colSpan = 5;
            th.scope = 'rowgroup';
            th.innerHTML = '<div class="materiel-groupe-ligne">'
                + '<span class="materiel-groupe-titre"></span>'
                + '<span class="pv-puce pv-puce-a_faire materiel-groupe-retard" hidden>En retard</span>'
                + '<span class="materiel-groupe-compte"></span>'
                + '<button type="button" class="materiel-groupe-tout" hidden title="Cocher tous les articles de cette livraison">Marquer reçue</button>'
                + '</div>';
            th.querySelector('.materiel-groupe-titre').textContent = cle
                ? 'Livraison prévue le ' + groupe.date
                : 'Sans date de livraison';
            tete.appendChild(th);
            corps.appendChild(tete);

            groupe.lignes.forEach(ligne => corps.appendChild(rangeeReception(ligne)));
            table.appendChild(corps);
        });

        majReception();
    }

    /**
     * Tout ce qui se compte se recompte ici, à partir des lignes : les segments, chaque
     * livraison, son retard, et le bouton de lot. Rien n'est tenu à part — une seconde
     * vérité finirait par contredire la première.
     */
    function majReception() {
        const volet = dialogue();
        const zone = volet.querySelector('.materiel-reception');
        const lignes = [...zone.querySelectorAll('.materiel-article')];
        const recues = lignes.filter(tr => tr.classList.contains('est-recue')).length;

        zone.querySelector('[data-compte="tous"]').textContent = lignes.length;
        zone.querySelector('[data-compte="attendu"]').textContent = lignes.length - recues;
        zone.querySelector('[data-compte="recu"]').textContent = recues;

        const groupes = [...zone.querySelectorAll('.materiel-groupe')];
        groupes.forEach(corps => {
            const siennes = [...corps.querySelectorAll('.materiel-article')];
            const faites = siennes.filter(tr => tr.classList.contains('est-recue')).length;
            const attendues = siennes.length - faites;
            corps.querySelector('.materiel-groupe-compte').textContent =
                faites + ' / ' + siennes.length + (siennes.length > 1 ? ' reçus' : ' reçu');
            // En retard : l'échéance est passée ET il manque encore quelque chose.
            corps.querySelector('.materiel-groupe-retard').hidden = !(corps.dataset.echue && attendues > 0);
            // Cocher une livraison entière n'a de sens que s'il y en a plusieurs : avec une
            // seule, c'est le bouton du haut.
            corps.querySelector('.materiel-groupe-tout').hidden = groupes.length < 2 || attendues === 0;
            corps.classList.toggle('est-complete', attendues === 0);
        });

        const tout = volet.querySelector('.materiel-tout-recu');
        tout.textContent = lignes.length && recues === lignes.length ? 'Tout marquer non reçu' : 'Tout marquer reçu';
        tout.disabled = lignes.length === 0;

        filtrerReception();
    }

    /** Le segment et la recherche : on cache les lignes, puis les livraisons vidées. */
    function filtrerReception() {
        const zone = dialogue().querySelector('.materiel-reception');
        const vue = zone.querySelector('input[name="materiel-vue"]:checked')?.value || '';
        const cherche = sansAccents(zone.querySelector('.materiel-reception-recherche input').value.trim());

        let visibles = 0;
        zone.querySelectorAll('.materiel-groupe').forEach(corps => {
            let dansLeGroupe = 0;
            corps.querySelectorAll('.materiel-article').forEach(tr => {
                const recue = tr.classList.contains('est-recue');
                const garde = (!vue || (vue === 'recu') === recue)
                    && (!cherche || tr.dataset.cherche.includes(cherche));
                tr.hidden = !garde;
                if (garde) dansLeGroupe++;
            });
            corps.hidden = dansLeGroupe === 0;
            visibles += dansLeGroupe;
        });

        const total = zone.querySelectorAll('.materiel-article').length;
        const vide = zone.querySelector('.materiel-reception-vide');
        vide.hidden = visibles > 0;
        vide.textContent = !total ? 'Cette commande ne porte aucun article.'
            : cherche ? 'Aucun article ne correspond à cette recherche.'
            : vue === 'attendu' ? 'Tout est arrivé : il ne reste rien à recevoir.'
            : 'Rien n’est encore arrivé.';
        zone.querySelector('.materiel-recevoir thead').hidden = visibles === 0;
    }

    $(document).on('change', '.materiel-reception input[name="materiel-vue"]', filtrerReception);
    $(document).on('input', '.materiel-reception-recherche input', filtrerReception);

    // ----- Le pointage -----
    //
    // Cocher déclare l'article reçu. Le pointage part tout seul, groupé : on coche rarement
    // une ligne isolée, et un bouton « enregistrer » de plus ferait hésiter sur ce qu'on
    // enregistre. Décocher se fait de la même façon, et efface qui et quand.

    let aPointer = {};
    let minuteur = null;

    function envoyerPointage() {
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        const lot = aPointer;
        aPointer = {};
        if (!id || !Object.keys(lot).length) return;

        const mot = fenetre.querySelector('.materiel-message');
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
                rafraichirRang(reponse.document);
                poserEntete(reponse.document);
                // Ce que le serveur a retenu fait foi — qui a pointé, et quand. Les lignes
                // qu'on est en train de recocher gardent ce qu'on vient d'y faire.
                (reponse.document.lignes || []).forEach(ligne => {
                    if (ligne.id in aPointer) return;
                    const tr = fenetre.querySelector('.materiel-article[data-ligne="' + ligne.id + '"]');
                    if (tr) dire(tr, !!ligne.recu, ligne.recu_court, ligne.recu_le);
                });
                majReception();
            })
            .fail(xhr => {
                // Le pointage n'est pas passé : on le dit, et l'écran revient à ce que le
                // serveur sait — rouvrir le bon le relira.
                mot.textContent = xhr.responseJSON?.message || 'Pointage impossible. Réessayez.';
            });
    }

    function pointer(tr, recu) {
        const id = tr.dataset.ligne;
        if (!id) return;
        dire(tr, recu, recu ? aujourdhui() : '', recu ? 'Reçu aujourd’hui' : '');
        aPointer[id] = recu;
        clearTimeout(minuteur);
        minuteur = setTimeout(envoyerPointage, 400);
    }

    // Toute la ligne coche — sauf quand on vient d'y sélectionner du texte, pour copier
    // une référence : ce glisser-là n'est pas un pointage.
    $(document).on('click', '.materiel-article', function (event) {
        if (!event.target.closest('.materiel-case') && String(window.getSelection() || '').trim()) return;
        pointer(this, !this.classList.contains('est-recue'));
        majReception();
    });

    $(document).on('click', '.materiel-groupe-tout', function () {
        this.closest('.materiel-groupe').querySelectorAll('.materiel-article:not(.est-recue)')
            .forEach(tr => pointer(tr, true));
        majReception();
    });

    $(document).on('click', '.materiel-tout-recu', function () {
        const lignes = [...dialogue().querySelectorAll('.materiel-article')];
        const recu = !lignes.every(tr => tr.classList.contains('est-recue'));
        // Tout décocher efface les dates de réception de toute la commande : on le
        // demande avant. Tout cocher se défait ligne par ligne, sans perte.
        if (!recu && !window.confirm('Marquer toute la commande comme non reçue ? Les dates de réception seront effacées.')) return;
        lignes.forEach(tr => {
            if (tr.classList.contains('est-recue') !== recu) pointer(tr, recu);
        });
        majReception();
    });

    // ----- Relire : les cases s'ouvrent au clic -----
    //
    // Une case fermée est du texte : la liste se lit comme le bon dont elle sort. Le clic
    // ouvre la case qu'on vise ; on la quitte, elle se referme.
    function ouvrirCase(td) {
        const champ = td.querySelector('.materiel-champ[readonly]');
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

    $(document).on('click', '.materiel-edition-ligne td', function (event) {
        if (event.target.closest('button, label, a')) return;
        if (event.target.closest('.materiel-champ:not([readonly])')) return;
        fermerCases(null);
        ouvrirCase(this);
    });

    // Entrée ou Échap valent sortie : il n'y a rien à confirmer ligne par ligne, c'est
    // « Enregistrer » qui écrit.
    $(document).on('blur', '.materiel-champ', function () {
        this.readOnly = true;
    });

    $(document).on('keydown', '.materiel-champ', function (event) {
        if (event.key !== 'Enter' && event.key !== 'Escape') return;
        event.preventDefault();
        this.readOnly = true;
        this.blur();
    });

    $(document).on('click', '.materiel-ajouter', function () {
        const rangee = ajouterLigne(null, false);
        compterLignes();
        // Elle est vide : inutile de cliquer encore pour commencer à la remplir.
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

    // ----- L'en-tête et l'ouverture -----

    /** Les puces de la maison, par état du bon. */
    const PUCES = {a_lire: 'pv-puce-sans_objet', a_verifier: 'pv-puce-a_faire', verifie: 'pv-puce-fait', echec: 'pv-puce-echec'};

    /** Le haut du volet et ses propriétés : ce qui identifie le bon, et où il en est. */
    function poserEntete(bon) {
        const volet = dialogue();
        const ligne = rang(bon.id);
        const texte = (selecteur, valeur) => { volet.querySelector(selecteur).textContent = valeur || '—'; };

        // Le numéro est le nom du bon. Sans lui — lecture ratée, en-tête illisible — on
        // retombe sur un intitulé qui le dit, plutôt que sur un titre vide.
        volet.querySelector('#materiel-volet-titre').textContent = bon.numero || 'Numéro non reconnu';
        volet.querySelector('.materiel-volet-sous-titre').textContent = ligne ? ligne.dataset.demande : '';

        const puce = volet.querySelector('.materiel-volet-etat');
        puce.className = 'pv-puce materiel-volet-etat ' + (PUCES[bon.etat] || '');
        puce.textContent = bon.etat_libelle;
        puce.title = bon.resume;

        texte('.materiel-volet-demande', ligne ? ligne.dataset.demande : '');
        const fichier = volet.querySelector('.materiel-volet-fichier');
        fichier.href = ligne ? ligne.dataset.fichier : '#';
        fichier.dataset.name = ligne ? ligne.dataset.nom : '';
        fichier.textContent = ligne ? ligne.dataset.nom : '—';
        texte('.materiel-volet-date', bon.date);
        texte('.materiel-volet-livraison', bon.livraison);
        // Ce qui est arrivé se compte en chiffres : c'est ce qu'on vient chercher.
        texte('.materiel-volet-reception', bon.articles
            ? (bon.recus || 0) + ' sur ' + bon.articles + ' reçu' + ((bon.recus || 0) > 1 ? 's' : '')
                + (bon.retards ? ' · ' + bon.retards + ' en retard' : '')
            : '');
        volet.querySelector('.materiel-volet-compte').textContent = bon.articles ? bon.articles : '';

        const note = volet.querySelector('.materiel-volet-note');
        note.textContent = noteDeLecture(bon);
        note.hidden = note.textContent === '';

        // Validée, la commande se reçoit ; avant, elle se relit. La feuille de style montre
        // la vue qui va avec, et retire ce qui écrit dans la commande.
        volet.classList.toggle('est-figee', !!bon.fige);
    }

    /**
     * Ce qu'il reste à dire quand la pastille ne suffit pas : ce qu'on attend de vous, ou
     * pourquoi la lecture a échoué.
     */
    function noteDeLecture(bon) {
        if (bon.etat === 'a_lire') return 'Lisez le bon, ou saisissez ses articles à la main.';
        if (bon.etat === 'a_verifier') return 'Relisez les articles et corrigez-les si besoin, puis validez la commande. La réception se pointe une fois la commande validée.';
        if (bon.etat === 'echec') return bon.resume;
        // Validée : qui l'a validée et quand, c'est ce qui la rend intouchable.
        if (bon.fige) return bon.resume + '. Cochez les articles à mesure qu’ils arrivent.';
        return '';
    }

    /** Les articles du bon, dans la vue qui va avec son état. */
    function remplirArticles(bon) {
        const volet = dialogue();
        volet.querySelector('.materiel-edition-lignes').innerHTML = '';
        if (bon.fige) {
            construireReception(bon);
        } else {
            (bon.lignes || []).forEach(l => ajouterLigne(l, l.douteuse));
            compterLignes();
        }
    }

    /** Remplit le volet d'un bon et l'ouvre. */
    function ouvrir(id) {
        const ligne = rang(id);
        if (!ligne) return;
        const bon = bonDuRang(ligne);
        const fenetre = dialogue();

        fenetre.querySelector('[name="document"]').value = id;
        fenetre.querySelector('.materiel-message').textContent = '';
        // Chaque bon s'ouvre sur tous ses articles, sans la recherche du précédent.
        fenetre.querySelector('input[name="materiel-vue"][value=""]').checked = true;
        fenetre.querySelector('.materiel-reception-recherche input').value = '';
        poserEntete(bon);
        remplirArticles(bon);

        // Un seul volet à la fois, comme partout.
        document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(v => { if (v !== fenetre) v.hidden = true; });
        fenetre.hidden = false;
        fenetre.scrollTop = 0;
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
        const recus = ligne.querySelector('.materiel-cellule-recus');
        if (recus) {
            if (bon.articles) recus.textContent = (bon.recus || 0) + '/' + bon.articles;
            else recus.innerHTML = '<span class="materiel-vide">—</span>';
        }
        const etat = ligne.querySelector('.materiel-etat');
        etat.className = 'materiel-etat materiel-etat-' + bon.etat;
        etat.textContent = bon.etat_libelle;
        etat.title = bon.resume;
        filtrer();
    }

    function envoyer(url, donnees, bouton, apres) {
        const fenetre = dialogue();
        const message = fenetre.querySelector('.materiel-message');
        const libelle = bouton.textContent;
        bouton.disabled = true;
        message.textContent = bouton.classList.contains('materiel-relire')
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
            poserEntete(reponse.document);
            remplirArticles(reponse.document);
        });
    });

    $(document).on('submit', '#materiel-form', function (event) {
        event.preventDefault();
        const fenetre = dialogue();
        const bouton = event.originalEvent?.submitter || this.querySelector('.materiel-enregistrer');
        const valider = bouton.value === '1';
        // Valider engage la commande pour de bon : on le dit avant, pas après.
        if (valider && !window.confirm('Valider la commande ? Ses articles ne pourront plus être modifiés ; seule la réception restera possible.')) return;

        envoyer('save_bon_materiel.php', {
            csrf: this.elements.csrf.value,
            document: this.elements.document.value,
            lignes: JSON.stringify(lignesSaisies()),
            valider: valider ? '1' : '0',
        }, bouton, reponse => {
            // Les lignes sont réécrites côté serveur : on reprend les siennes, avec leurs
            // nouveaux identifiants. Validée, la commande passe d'elle-même à la réception.
            poserEntete(reponse.document);
            remplirArticles(reponse.document);
        });
    });
})();
