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

    /**
     * Poste un formulaire encodé et rend la réponse JSON. Un refus du serveur, une réponse
     * qui n'est pas du JSON ou une panne du réseau rejettent avec le corps lu, ou null.
     */
    function posterJson(url, donnees) {
        const corps = new URLSearchParams();
        Object.entries(donnees).forEach(([cle, valeur]) => corps.append(cle, valeur ?? ''));
        return fetch(url, {method: 'POST', body: corps, credentials: 'same-origin'})
            .then(async reponse => {
                const lu = await reponse.json().catch(() => null);
                if (!reponse.ok || lu === null) throw lu;
                return lu;
            }, () => { throw null; });
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

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.materiel-manque [data-traiter]');
        if (!bouton) return;
        const ligne = bouton.closest('.materiel-manque');
        const section = bouton.closest('.materiel-manques');
        const statut = bouton.dataset.traiter;
        ligne.querySelectorAll('button').forEach(b => { b.disabled = true; });

        posterJson('actions/traiter_manque.php', { csrf: section.dataset.csrf, manque: ligne.dataset.manque, statut })
            .then(reponse => {
                if (statut === 'traitee') {
                    ligne.remove();
                    const restants = section.querySelectorAll('.materiel-manque').length;
                    // Deux comptes : le titre de la section, et le résumé des onglets qui y mène.
                    document.querySelectorAll(PAGE + ' [data-manques-compte]').forEach(compte => { compte.textContent = restants; });
                    const mot = document.querySelector(PAGE + ' [data-manques-mot]');
                    if (mot) mot.textContent = restants === 1 ? 'manque à traiter' : 'manques à traiter';
                    if (!restants) {
                        section.querySelector('.prefa-table-scroll').hidden = true;
                        section.querySelector('.materiel-manques-vide').hidden = false;
                        const lien = document.querySelector(PAGE + ' [data-manques-lien]');
                        if (lien) lien.hidden = true;
                    }
                } else {
                    // Le signe suit data-statut dans la feuille : seul le mot est à réécrire.
                    ligne.dataset.statut = statut;
                    const libelle = ligne.querySelector('[data-manque-libelle]');
                    if (libelle) libelle.textContent = reponse.etat.libelle;
                    bouton.remove();
                    ligne.querySelectorAll('button').forEach(b => { b.disabled = false; });
                }
                majPastilleManques();
            }, lu => {
                ligne.querySelectorAll('button').forEach(b => { b.disabled = false; });
                bouton.title = lu?.message || 'Opération impossible. Réessayez.';
                window.alerte(bouton.title, { ton: 'danger' });
            });
    });

    // Le résumé des onglets annonce les manques ; ils vivent sous les bons, et le clic y mène.
    document.addEventListener('click', evenement => {
        if (!evenement.target.closest(PAGE + ' [data-manques-lien]')) return;
        document.getElementById('materiel-manques')?.scrollIntoView({behavior: 'smooth', block: 'start'});
    });

    // ------------------------------------------------------------------ LES FILTRES

    /**
     * Onglet et recherche se combinent, comme sur les PV : un titre de groupe disparaît avec
     * sa dernière ligne, et chaque compte dit ce qui reste visible.
     */
    function filtrer() {
        const zone = page();
        if (!zone) return;

        const recherche = (zone.querySelector('#materiel-filtre-recherche')?.value || '').trim().toLowerCase();
        const etat = zone.querySelector('.materiel-onglet[aria-pressed="true"]')?.dataset.etat || '';

        const parEtat = {};
        zone.querySelectorAll('.materiel-rang').forEach(ligne => {
            const trouvee = !recherche || (ligne.dataset.recherche || '').includes(recherche);
            if (trouvee) parEtat[ligne.dataset.etat] = (parEtat[ligne.dataset.etat] || 0) + 1;
            ligne.hidden = !(trouvee && (!etat || ligne.dataset.etat === etat));
        });

        let visibles = 0;
        zone.querySelectorAll('.materiel-liste-groupe').forEach(groupe => {
            const nombre = parEtat[groupe.dataset.etat] || 0;
            const affiche = nombre > 0 && (!etat || groupe.dataset.etat === etat);
            groupe.hidden = !affiche;
            groupe.querySelector('.prefa-groupe-compte').textContent = nombre;
            if (affiche) visibles += nombre;
        });

        // Les comptes des onglets suivent les bons : valider une commande la fait passer
        // d'« À vérifier » à « Validée » sans recharger l'écran.
        zone.querySelectorAll('.materiel-onglet').forEach(onglet => {
            const compte = onglet.querySelector('.prefa-onglet-compte');
            if (!compte) return;
            compte.textContent = onglet.dataset.etat
                ? (parEtat[onglet.dataset.etat] || 0)
                : Object.values(parEtat).reduce((a, b) => a + b, 0);
        });

        const compte = zone.querySelector('#materiel-compte');
        const libelle = zone.querySelector('#materiel-compte-libelle');
        if (compte) compte.textContent = visibles;
        if (libelle) libelle.textContent = visibles === 1 ? 'bon de commande' : 'bons de commande';
        const vide = zone.querySelector('.materiel-aucun-resultat');
        if (vide) vide.hidden = visibles > 0 || !zone.querySelector('.materiel-rang');
    }

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#materiel-filtre-recherche')) filtrer();
    });
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.materiel-onglet');
        if (!bouton) return;
        document.querySelectorAll('.materiel-onglet').forEach(o => o.setAttribute('aria-pressed', String(o === bouton)));
        filtrer();
    });

    // « Actualiser », comme sur les PV : l'écran se recharge, sa recherche et son onglet avec.
    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.materiel-refresh')) return;
        const zone = page();
        const recherche = zone?.querySelector('#materiel-filtre-recherche')?.value || '';
        const etat = zone?.querySelector('.materiel-onglet[aria-pressed="true"]')?.dataset.etat || '';
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, 'pages/commande_materiel.php').then(({ ok }) => {
            if (!ok) {
                contenu.textContent = 'Impossible de recharger cet écran. Actualisez la page.';
                return;
            }
            const champ = document.getElementById('materiel-filtre-recherche');
            if (champ) champ.value = recherche;
            document.querySelectorAll('.materiel-onglet').forEach(o => o.setAttribute('aria-pressed', String(o.dataset.etat === etat)));
            if (!document.querySelector('.materiel-onglet[aria-pressed="true"]')) {
                document.querySelector('.materiel-onglet[data-etat=""]')?.setAttribute('aria-pressed', 'true');
            }
            filtrer();
        });
    });

    // ---------------------------------------------------------------- LE VOLET D'UN BON
    //
    // Une liste d'articles à cocher, à toutes les étapes de la commande, et trois étapes en
    // tête pour dire où elle en est : bon lu, commande validée, matériel reçu.
    //
    // Avant sa validation, une commande ne montrait que sa relecture — des cases qui
    // s'ouvrent au clic —, et rien ne s'y cochait : « pourquoi j'ai le bouton Valider la
    // commande, alors que je ne peux pas cocher puis valider ? » (6 novembre 2026). La liste à
    // cocher est maintenant la vue de toutes les étapes : les coches d'une commande à valider
    // partent avec « Valider la commande », celles d'une commande validée avec « Valider la
    // réception ». Corriger les articles est un mode à part, « Corriger les articles ».

    // Le bon tel que le serveur l'a rendu en dernier : la correction repart de lui.
    let bonOuvert = null;

    // ----- Corriger : le tableau du bon, case par case -----

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

    // ----- La liste à cocher -----

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

    /**
     * Ce qui reconnaît un article d'une écriture à l'autre, comme materielClefArticle() :
     * une correction réécrit les lignes, et les coches en attente les retrouvent par là.
     */
    function clefArticle(ligne) {
        const reference = String(ligne.reference || '').trim().toLowerCase();
        return reference ? 'r:' + reference : 'd:' + String(ligne.designation || '').trim().toLowerCase();
    }

    function rangeeReception(ligne) {
        const tr = document.createElement('tr');
        tr.className = 'materiel-article';
        tr.dataset.ligne = ligne.id;
        tr.dataset.clef = clefArticle(ligne);
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
        retenir(tr, ligne);
        direServeur(tr);
        return tr;
    }

    /**
     * L'état d'une ligne : sa case, son ton, et la date d'arrivée. Une coche pas encore
     * validée le dit à la place de la date — « à valider » —, et sa case prend la couleur de
     * ce qui attend.
     */
    function dire(tr, recu, court, phrase, enAttente = false) {
        tr.classList.toggle('est-recue', recu);
        tr.classList.toggle('est-a-valider', enAttente);
        const bouton = tr.querySelector('.materiel-case');
        bouton.setAttribute('aria-checked', String(recu));
        const quand = tr.querySelector('.materiel-col-recu');
        quand.textContent = enAttente ? 'à valider' : (recu ? (court || '') : '');
        tr.title = enAttente ? 'Pas encore enregistré : il part avec le bouton « Valider », en bas.'
            : (recu ? (phrase || 'Reçu') : '');
    }

    /** Ce que le serveur sait d'une ligne, gardé sur elle : y revenir défait la coche en attente. */
    function retenir(tr, ligne) {
        tr.dataset.recuServeur = ligne.recu ? '1' : '';
        tr.dataset.recuCourt = ligne.recu_court || '';
        tr.dataset.recuLe = ligne.recu_le || '';
    }

    /** La ligne telle que le serveur la connaît. */
    function direServeur(tr) {
        dire(tr, tr.dataset.recuServeur === '1', tr.dataset.recuCourt, tr.dataset.recuLe);
    }

    /**
     * La liste à cocher : un groupe par date de livraison, dans l'ordre où elles arrivent.
     * La date se lit une fois, en tête de son groupe, au lieu de seize fois dans une
     * colonne ; et « en retard » se dit une fois aussi, sur la livraison qui l'est.
     */
    function construireReception(bon) {
        const table = dialogue().querySelector('.materiel-recevoir');
        table.querySelectorAll('tbody').forEach(corps => corps.remove());
        // La liste repart de ce que le serveur sait. Les coches en attente dont la ligne
        // existe encore restent cochées.
        const enAttente = aPointer;
        aPointer = {};

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
                + '</div>';
            th.querySelector('.materiel-groupe-titre').textContent = cle
                ? 'Livraison prévue le ' + groupe.date
                : 'Sans date de livraison';
            tete.appendChild(th);
            corps.appendChild(tete);

            groupe.lignes.forEach(ligne => corps.appendChild(rangeeReception(ligne)));
            table.appendChild(corps);
        });

        Object.entries(enAttente).forEach(([id, recu]) => {
            const tr = table.querySelector('.materiel-article[data-ligne="' + id + '"]');
            if (tr) pointer(tr, recu);
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
            corps.classList.toggle('est-complete', attendues === 0);
        });

        const tout = volet.querySelector('.materiel-tout-recu');
        tout.textContent = lignes.length && recues === lignes.length ? 'Tout marquer non reçu' : 'Tout marquer reçu';
        tout.disabled = lignes.length === 0;

        filtrerReception();
        majValidation();
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
        vide.textContent = !total ? 'Cette commande ne porte aucun article. « Corriger les articles » permet de les ajouter.'
            : cherche ? 'Aucun article ne correspond à cette recherche.'
            : vue === 'attendu' ? 'Tout est arrivé : il ne reste rien à recevoir.'
            : 'Rien n’est encore arrivé.';
        zone.querySelector('.materiel-recevoir thead').hidden = visibles === 0;
    }

    document.addEventListener('change', evenement => {
        if (evenement.target.closest('.materiel-reception input[name="materiel-vue"]')) filtrerReception();
    });
    document.addEventListener('input', evenement => {
        if (evenement.target.closest('.materiel-reception-recherche input')) filtrerReception();
    });

    // ----- Les coches, et ce qui les enregistre -----
    //
    // Cocher prépare : l'article passe « reçu » à l'écran, « à valider » dans sa colonne, et
    // rien ne part. Le bouton plein du pied enregistre d'un coup tout ce qui est coché —
    // « Valider la commande » tant qu'elle est à valider, « Valider la réception » ensuite.
    // Le pointage partait tout seul au toucher de la ligne : une ligne touchée par erreur sur
    // la tablette était enregistrée sans un mot (6 novembre 2026). Décocher se fait de la
    // même façon ; validé, il efface qui et quand.

    // Ce qui attend d'être validé : { identifiant de ligne : reçu ou non }. Une ligne
    // revenue à ce que le serveur sait n'y est plus : le compte est celui des vrais
    // changements.
    let aPointer = {};
    let envoiEnCours = false;
    // Ce que dit le pied quand rien n'attend : le compte rendu du dernier enregistrement.
    let dernierMessage = '';

    const aValider = () => Object.keys(aPointer).length;

    /** Le pied du volet : combien d'articles attendent, et le bouton qui les enregistre. */
    function majValidation() {
        const volet = dialogue();
        if (!volet) return;
        const nombre = aValider();
        const figee = volet.classList.contains('est-figee');
        const correction = volet.classList.contains('en-correction');
        volet.classList.toggle('a-valider', nombre > 0);
        volet.querySelector('.materiel-valider-reception').disabled = nombre === 0 || envoiEnCours;
        volet.querySelector('.materiel-valider-commande').disabled = envoiEnCours
            || volet.dataset.etat === 'a_lire' || !(bonOuvert?.lignes || []).length;
        volet.querySelector('.materiel-annuler-reception').disabled = envoiEnCours;
        const mot = volet.querySelector('.materiel-message');
        mot.classList.toggle('est-a-valider', !correction && nombre > 0);
        if (correction) return;
        if (!nombre) {
            mot.textContent = dernierMessage;
        } else if (figee) {
            mot.textContent = nombre > 1 ? nombre + ' articles à valider' : '1 article à valider';
        } else {
            mot.textContent = nombre > 1 ? nombre + ' articles cochés' : '1 article coché';
        }
    }

    function pointer(tr, recu) {
        const id = tr.dataset.ligne;
        // Pendant un envoi, la liste attend : ce qui part est ce qui était coché.
        if (!id || envoiEnCours) return;
        if (recu === (tr.dataset.recuServeur === '1')) {
            delete aPointer[id];
            direServeur(tr);
        } else {
            aPointer[id] = recu;
            dire(tr, recu, '', '', true);
        }
    }

    /** Les coches en attente, par article : elles survivent à une réécriture des lignes. */
    function cochesParArticle() {
        const coches = {};
        dialogue().querySelectorAll('.materiel-article').forEach(tr => {
            if (tr.dataset.ligne in aPointer) coches[tr.dataset.clef] = aPointer[tr.dataset.ligne];
        });
        return coches;
    }

    function reposerCoches(coches) {
        dialogue().querySelectorAll('.materiel-article').forEach(tr => {
            if (tr.dataset.clef in coches) pointer(tr, coches[tr.dataset.clef]);
        });
        majReception();
    }

    /** Enregistre les coches d'une commande validée, d'un seul envoi. */
    async function validerReception() {
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        const lot = aPointer;
        if (!id || !Object.keys(lot).length || envoiEnCours) return;

        // Remettre « à recevoir » un article reçu efface qui l'a reçu et quand : on le
        // demande avant. Une ligne en attente diffère du serveur : « non reçu » y veut dire
        // qu'elle l'était.
        const effaces = Object.values(lot).filter(recu => !recu).length;
        if (effaces && !await window.confirmer(effaces > 1
            ? 'Remettre ' + effaces + ' articles « à recevoir » ?'
            : 'Remettre un article « à recevoir » ?', {
            texte: effaces > 1 ? 'Leurs dates de réception seront effacées.' : 'Sa date de réception sera effacée.',
            action: 'Valider la réception',
            ton: 'danger',
        })) return;

        envoiEnCours = true;
        const bouton = fenetre.querySelector('.materiel-valider-reception');
        bouton.textContent = 'Enregistrement…';
        majValidation();

        posterJson('actions/receptionner_bon_materiel.php', {
            csrf: fenetre.querySelector('[name="csrf"]').value,
            document: id,
            lignes: JSON.stringify(lot),
        })
            .then(reponse => {
                aPointer = {};
                rafraichirRang(reponse.document);
                poserEntete(reponse.document);
                // Ce que le serveur a retenu fait foi — qui a pointé, et quand.
                (reponse.document.lignes || []).forEach(ligne => {
                    const tr = fenetre.querySelector('.materiel-article[data-ligne="' + ligne.id + '"]');
                    if (!tr) return;
                    retenir(tr, ligne);
                    direServeur(tr);
                });
                dernierMessage = 'Réception enregistrée. ' + (reponse.message || '');
            }, lu => {
                // Rien n'est passé : les coches attendent toujours, et l'on peut réessayer.
                window.alerte(lu?.message || 'La réception n’a pas pu être enregistrée. Réessayez.', { ton: 'danger' });
            })
            .finally(() => {
                envoiEnCours = false;
                bouton.textContent = 'Valider la réception';
                majReception();
            });
    }

    /**
     * Valide une commande, et enregistre du même geste ce qui est coché : une réception ne
     * se pointe que sur une commande validée, et c'est la validation qui l'emporte.
     */
    async function validerCommande() {
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        if (!id || envoiEnCours) return;
        const lot = aPointer;
        const recus = Object.values(lot).filter(Boolean).length;
        const effaces = Object.keys(lot).length - recus;
        let texte = 'Ses articles ne se corrigeront plus : il ne restera qu’à cocher ce qui arrive.';
        if (recus) texte += recus > 1 ? ' Les ' + recus + ' articles cochés seront marqués reçus.' : ' L’article coché sera marqué reçu.';
        if (effaces) {
            texte += effaces > 1
                ? ' ' + effaces + ' articles repasseront « à recevoir » : leurs dates de réception seront effacées.'
                : ' Un article repassera « à recevoir » : sa date de réception sera effacée.';
        }
        if (!await window.confirmer('Valider la commande ?', { texte, action: 'Valider la commande' })) return;

        envoiEnCours = true;
        const bouton = fenetre.querySelector('.materiel-valider-commande');
        bouton.textContent = 'Validation…';
        majValidation();

        posterJson('actions/valider_bon_materiel.php', {
            csrf: fenetre.querySelector('[name="csrf"]').value,
            document: id,
            lignes: JSON.stringify(lot),
        })
            .then(reponse => {
                aPointer = {};
                dernierMessage = reponse.message || '';
                rafraichirRang(reponse.document);
                poserEntete(reponse.document);
                remplirArticles(reponse.document);
            }, lu => {
                window.alerte(lu?.message || 'La commande n’a pas pu être validée. Réessayez.', { ton: 'danger' });
                // Validée, mais les coches n'ont pas suivi : l'écran se relit, la commande
                // s'y montre telle qu'elle est.
                if (String(lu?.message || '').startsWith('Commande validée')) {
                    document.querySelector(PAGE + ' .materiel-refresh')?.click();
                }
            })
            .finally(() => {
                envoiEnCours = false;
                bouton.textContent = 'Valider la commande';
                majReception();
            });
    }

    // Toute la ligne coche — sauf quand on vient d'y sélectionner du texte, pour copier
    // une référence : ce glisser-là n'est pas un pointage.
    document.addEventListener('click', evenement => {
        const tr = evenement.target.closest('.materiel-article');
        if (!tr) return;
        if (!evenement.target.closest('.materiel-case') && String(window.getSelection() || '').trim()) return;
        pointer(tr, !tr.classList.contains('est-recue'));
        majReception();
    });

    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.materiel-tout-recu')) return;
        const lignes = [...dialogue().querySelectorAll('.materiel-article')];
        const recu = !lignes.every(tr => tr.classList.contains('est-recue'));
        // Tout décocher n'efface rien sur-le-champ : les coches attendent la validation, qui
        // demande avant d'effacer des dates.
        lignes.forEach(tr => {
            if (tr.classList.contains('est-recue') !== recu) pointer(tr, recu);
        });
        majReception();
    });

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.materiel-valider-reception')) validerReception();
        if (evenement.target.closest('.materiel-valider-commande')) validerCommande();
    });

    // « Annuler » : les lignes reviennent à ce que le serveur sait.
    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.materiel-annuler-reception') || envoiEnCours) return;
        dialogue().querySelectorAll('.materiel-article.est-a-valider').forEach(tr => direServeur(tr));
        aPointer = {};
        majReception();
    });

    // ----- Corriger les articles -----
    //
    // Un mode à part : le tableau du bon, dont chaque case s'ouvre au clic. Dans la liste à
    // cocher, toucher une ligne la coche ; il fallait un geste distinct pour la corriger.
    // Une commande validée se rouvre d'abord, après confirmation — c'est « Modifier », sur sa
    // ligne.

    let correctionModifiee = false;

    function entrerCorrection() {
        const volet = dialogue();
        volet.querySelector('.materiel-edition-lignes').innerHTML = '';
        (bonOuvert?.lignes || []).forEach(l => ajouterLigne(l, l.douteuse));
        compterLignes();
        correctionModifiee = false;
        volet.classList.add('en-correction');
        // Le compte rendu d'avant ne dit plus rien de la liste qu'on corrige.
        const mot = volet.querySelector('.materiel-message');
        mot.classList.remove('est-a-valider');
        mot.textContent = '';
        dernierMessage = '';
        poserConsigne();
        majValidation();
    }

    function quitterCorrection() {
        dialogue().classList.remove('en-correction');
        correctionModifiee = false;
        poserConsigne();
        majValidation();
    }

    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.materiel-corriger');
        if (!bouton || envoiEnCours) return;
        const volet = dialogue();
        if (volet.classList.contains('est-figee')) {
            if (!await window.confirmer('Corriger la commande validée ?', {
                texte: 'Elle repasse « à valider » : ses articles redeviennent modifiables, et il faudra la valider de nouveau. Les réceptions déjà pointées sont gardées.',
                action: 'Corriger',
            })) return;
            bouton.disabled = true;
            try {
                const reponse = await posterJson('actions/rouvrir_bon_materiel.php', {
                    csrf: volet.querySelector('[name="csrf"]').value,
                    document: volet.querySelector('[name="document"]').value,
                });
                rafraichirRang(reponse.document);
                poserEntete(reponse.document);
                remplirArticles(reponse.document);
            } catch (lu) {
                window.alerte(lu?.message || 'Impossible de rouvrir cette commande. Réessayez.', { ton: 'danger' });
                return;
            } finally {
                bouton.disabled = false;
            }
        }
        entrerCorrection();
    });

    document.addEventListener('click', async evenement => {
        if (!evenement.target.closest('.materiel-quitter-correction')) return;
        if (correctionModifiee && !await window.confirmer('Abandonner les corrections ?', {
            texte: 'Les articles reviendront tels qu’ils étaient.',
            action: 'Abandonner',
            annuler: 'Continuer à corriger',
        })) return;
        quitterCorrection();
    });

    // Les cases s'ouvrent au clic. Une case fermée est du texte : la liste se lit comme le
    // bon dont elle sort. Le clic ouvre la case qu'on vise ; on la quitte, elle se referme.
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

    document.addEventListener('click', evenement => {
        const td = evenement.target.closest('.materiel-edition-ligne td');
        if (!td) return;
        if (evenement.target.closest('button, label, a')) return;
        if (evenement.target.closest('.materiel-champ:not([readonly])')) return;
        fermerCases(null);
        ouvrirCase(td);
    });

    // Entrée ou Échap valent sortie : il n'y a rien à confirmer case par case, c'est
    // « Enregistrer les corrections » qui écrit.
    document.addEventListener('focusout', evenement => {
        const champ = evenement.target.closest('.materiel-champ');
        if (champ) champ.readOnly = true;
    });

    document.addEventListener('keydown', evenement => {
        const champ = evenement.target.closest('.materiel-champ');
        if (!champ) return;
        if (evenement.key !== 'Enter' && evenement.key !== 'Escape') return;
        evenement.preventDefault();
        champ.readOnly = true;
        champ.blur();
    });

    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.materiel-ajouter')) return;
        const rangee = ajouterLigne(null, false);
        compterLignes();
        correctionModifiee = true;
        // Elle est vide : inutile de cliquer encore pour commencer à la remplir.
        const champ = rangee.querySelector('.materiel-champ');
        if (champ) {
            champ.readOnly = false;
            champ.focus();
        }
    });

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.materiel-retirer');
        if (!bouton) return;
        bouton.closest('.materiel-edition-ligne').remove();
        compterLignes();
        correctionModifiee = true;
    });

    // Une ligne qu'on vient de corriger n'est plus celle dont la lecture doutait.
    document.addEventListener('input', evenement => {
        const champ = evenement.target.closest('.materiel-champ');
        if (!champ) return;
        champ.closest('.materiel-edition-ligne').removeAttribute('title');
        correctionModifiee = true;
    });

    // ----- Fermer avec du travail pas enregistré -----
    //
    // Le volet se ferme par sa croix, par Échap et d'un clic à côté (prefa-interactions.js) :
    // des coches pas validées, des corrections pas enregistrées s'y perdraient sans un mot.
    // On le demande avant — en capture, pour passer avant la fermeture.
    function travailEnCours() {
        const volet = dialogue();
        if (!volet || volet.hidden || envoiEnCours) return '';
        if (volet.classList.contains('en-correction') && correctionModifiee) return 'correction';
        return aValider() ? 'coches' : '';
    }

    async function fermerQuandMeme(travail) {
        const volet = dialogue();
        const nombre = aValider();
        const confirme = travail === 'correction'
            ? await window.confirmer('Fermer sans enregistrer les corrections ?', {
                texte: 'Les articles reviendront tels qu’ils étaient.',
                action: 'Fermer sans enregistrer',
                annuler: 'Revenir',
            })
            : await window.confirmer(volet.classList.contains('est-figee')
                ? 'Fermer sans valider la réception ?'
                : 'Fermer sans valider la commande ?', {
                texte: nombre > 1
                    ? 'Les ' + nombre + ' articles cochés ne seront pas enregistrés.'
                    : 'L’article coché ne sera pas enregistré.',
                action: 'Fermer sans valider',
                annuler: 'Revenir',
            });
        if (!confirme) return;
        aPointer = {};
        correctionModifiee = false;
        volet.classList.remove('en-correction');
        volet.hidden = true;
    }

    document.addEventListener('click', evenement => {
        const travail = travailEnCours();
        if (!travail) return;
        const croix = evenement.target.closest('#materiel-volet .prefa-detail-close');
        if (!croix && evenement.target !== document.body) return;
        evenement.stopImmediatePropagation();
        fermerQuandMeme(travail);
    }, true);

    document.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape') return;
        // Échap dans une case ouverte la referme, et seulement elle : le volet se fermait
        // avec (prefa-interactions.js), et les corrections partaient avec lui.
        const champ = evenement.target.closest?.('.materiel-champ');
        if (champ) {
            evenement.preventDefault();
            evenement.stopImmediatePropagation();
            champ.readOnly = true;
            champ.blur();
            return;
        }
        const travail = travailEnCours();
        // Échap ferme d'abord la fenêtre posée par-dessus, comme partout.
        if (!travail || document.querySelector('dialog[open]')) return;
        evenement.stopImmediatePropagation();
        fermerQuandMeme(travail);
    }, true);

    // Recharger ou quitter la page avec du travail pas enregistré : le navigateur le demande.
    window.addEventListener('beforeunload', evenement => {
        if (!travailEnCours()) return;
        evenement.preventDefault();
        evenement.returnValue = '';
    });

    // ----- L'en-tête, les étapes et l'ouverture -----

    /** Le haut du volet : ce qui identifie le bon, ses étapes, et ce qu'on attend de vous. */
    function poserEntete(bon) {
        bonOuvert = bon;
        const volet = dialogue();
        const ligne = rang(bon.id);
        const texte = (selecteur, valeur) => { volet.querySelector(selecteur).textContent = valeur || '—'; };

        // Le numéro est le nom du bon. Sans lui — lecture ratée, en-tête illisible — on
        // retombe sur un intitulé qui le dit, plutôt que sur un titre vide.
        volet.querySelector('#materiel-volet-titre').textContent = bon.numero || 'Numéro non reconnu';
        volet.querySelector('.materiel-volet-sous-titre').textContent = ligne ? ligne.dataset.demande : '';
        volet.dataset.etat = bon.etat;

        const fichier = volet.querySelector('.materiel-volet-fichier');
        fichier.href = ligne ? ligne.dataset.fichier : '#';
        fichier.dataset.name = ligne ? ligne.dataset.nom : '';
        fichier.textContent = ligne ? ligne.dataset.nom : '—';
        texte('.materiel-volet-date', bon.date);
        texte('.materiel-volet-livraison', bon.livraison);
        volet.querySelector('.materiel-volet-compte').textContent = bon.articles ? bon.articles : '';

        const note = volet.querySelector('.materiel-volet-note');
        note.textContent = noteDeLecture(bon);
        note.hidden = note.textContent === '';

        // La commande en Excel, dès qu'elle porte des articles.
        const exporter = volet.querySelector('.materiel-exporter');
        exporter.href = 'actions/export_bon_materiel.php?document=' + encodeURIComponent(bon.id);
        exporter.hidden = !bon.articles;

        volet.classList.toggle('est-figee', !!bon.fige);
        poserEtapes(bon);
        poserConsigne();
    }

    /**
     * Les trois étapes : chacune faite, en cours ou à venir — ou en échec, pour une lecture
     * ratée —, avec deux ou trois mots sur ce qui la concerne.
     */
    function poserEtapes(bon) {
        const volet = dialogue();
        const poser = (etape, etat, libelle, detail) => {
            const li = volet.querySelector('.materiel-etape[data-etape="' + etape + '"]');
            li.className = 'materiel-etape est-' + etat;
            li.querySelector('.materiel-etape-libelle').textContent = libelle;
            li.querySelector('.materiel-etape-detail').textContent = detail;
            if (etat === 'en-cours') li.setAttribute('aria-current', 'step');
            else li.removeAttribute('aria-current');
        };
        const articles = bon.articles || 0;
        const recus = bon.recus || 0;

        // L'intitulé suit l'étape : « Commande à valider » tant qu'elle ne l'est pas,
        // « Commande validée » ensuite — jamais un état qu'elle n'a pas encore.
        if (bon.etat === 'a_lire') poser('lu', 'en-cours', 'Lecture du bon', 'en cours…');
        else if (bon.etat === 'echec') poser('lu', 'en-echec', 'Lecture du bon', 'impossible');
        else poser('lu', 'faite', 'Bon lu', articles > 1 ? articles + ' articles' : articles + ' article');

        if (bon.fige) poser('valide', 'faite', 'Commande validée', bon.valide_le ? 'le ' + bon.valide_le : '');
        else if (bon.etat === 'a_lire' || bon.etat === 'echec') poser('valide', 'a-venir', 'Validation', '');
        else poser('valide', 'en-cours', 'Commande à valider', 'vérifiez, puis validez');

        if (!bon.fige) poser('recu', 'a-venir', 'Réception', 'après la validation');
        else if (articles && recus === articles) poser('recu', 'faite', 'Matériel reçu', 'tout est arrivé');
        else {
            poser('recu', 'en-cours', 'Matériel à recevoir', recus + ' sur ' + articles + ' reçu' + (recus > 1 ? 's' : '')
                + (bon.retards ? ' · ' + bon.retards + ' en retard' : ''));
        }
    }

    /** Ce qu'on attend de vous, en une phrase, selon l'étape et la vue. */
    function poserConsigne() {
        const volet = dialogue();
        const consigne = volet.querySelector('.materiel-consigne');
        const etat = volet.dataset.etat;
        consigne.textContent = volet.classList.contains('en-correction')
            ? 'Touchez une case pour la corriger, puis enregistrez les corrections.'
            : etat === 'a_lire' || etat === 'echec' ? ''
            : volet.classList.contains('est-figee') ? 'Cochez ce qui est arrivé, puis validez la réception.'
            : 'Vérifiez les articles avec le bon PDF, puis validez la commande. Ce qui est déjà arrivé peut être coché.';
        consigne.hidden = consigne.textContent === '';
    }

    /** Pourquoi la liste est vide : la lecture se lance, ou elle a échoué. */
    function noteDeLecture(bon) {
        if (bon.etat === 'a_lire') return 'Le bon n’a pas encore été lu : sa lecture se lance.';
        if (bon.etat === 'echec') return bon.resume + '. Ajoutez les articles à la main, ou relisez le bon.';
        return '';
    }

    /** Les articles du bon : la liste à cocher toujours, le tableau de correction s'il est ouvert. */
    function remplirArticles(bon) {
        construireReception(bon);
        const volet = dialogue();
        if (!volet.classList.contains('en-correction')) return;
        volet.querySelector('.materiel-edition-lignes').innerHTML = '';
        (bon.lignes || []).forEach(l => ajouterLigne(l, l.douteuse));
        compterLignes();
        correctionModifiee = false;
    }

    /** Remplit le volet d'un bon et l'ouvre. */
    function ouvrir(id) {
        const ligne = rang(id);
        if (!ligne) return;
        const bon = bonDuRang(ligne);
        const fenetre = dialogue();

        fenetre.querySelector('[name="document"]').value = id;
        // Un bon s'ouvre sur sa liste, sans les coches, la recherche ni le message d'un autre.
        const mot = fenetre.querySelector('.materiel-message');
        mot.textContent = '';
        mot.classList.remove('est-a-valider');
        aPointer = {};
        dernierMessage = '';
        correctionModifiee = false;
        fenetre.classList.remove('en-correction');
        fenetre.querySelector('input[name="materiel-vue"][value=""]').checked = true;
        fenetre.querySelector('.materiel-reception-recherche input').value = '';
        poserEntete(bon);
        remplirArticles(bon);
        // Une commande sans article, à valider : on vient la remplir.
        if (!bon.fige && !(bon.lignes || []).length && bon.etat !== 'a_lire') entrerCorrection();

        // Un seul volet à la fois, comme partout.
        document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(v => { if (v !== fenetre) v.hidden = true; });
        fenetre.hidden = false;
        fenetre.scrollTop = 0;

        // Un bon se lit de lui-même dès son dépôt, en tâche de fond. Celui qui ne l'a pas
        // été — déposé avant, ou dont la lecture n'a pas pu tourner — se lit à l'ouverture :
        // personne n'a plus à cliquer « Lire ». Si la tâche de fond est en cours, le serveur
        // l'attend au lieu de relire (« si_besoin »).
        if (bon.etat === 'a_lire' && !bon.fige) {
            envoyer('lire_bon_materiel.php', {
                csrf: fenetre.querySelector('[name="csrf"]').value,
                document: id,
                si_besoin: '1',
            }, fenetre.querySelector('.materiel-relire'), reponse => {
                // Un autre bon a pu être ouvert pendant la lecture : on ne remplit que le sien.
                if (fenetre.querySelector('[name="document"]').value !== String(id)) return;
                poserEntete(reponse.document);
                remplirArticles(reponse.document);
                if (!reponse.document.fige && !(reponse.document.lignes || []).length) entrerCorrection();
            });
        }
    }

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.materiel-ouvrir');
        if (bouton) ouvrir(bouton.dataset.document);
    });

    // Depuis la fiche d'une demande : on bascule sur l'écran, puis on ouvre le bon. La
    // page arrive en AJAX, d'où l'attente — même geste que « Plans & équipes ».
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.btn-goto-commande-materiel');
        if (!bouton) return;
        const id = bouton.dataset.document;
        document.getElementById('btn-commande-materiel')?.click();
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
        // L'état n'a pas de colonne : c'est le groupe qui le dit. Un bon qui change d'état
        // passe en tête de son nouveau groupe — tous sont écrits dans la page, même vides.
        const groupe = page()?.querySelector('.materiel-liste-groupe[data-etat="' + bon.etat + '"]');
        if (groupe && ligne.previousElementSibling?.dataset.etat !== bon.etat) groupe.after(ligne);
        ligne.querySelector('.materiel-ouvrir')?.setAttribute('title', bon.resume || '');
        // « Modifier » n'a de sens que sur une commande validée : il paraît quand on la valide,
        // et repart quand on la rouvre.
        const modifier = ligne.querySelector('.materiel-modifier');
        if (modifier) modifier.hidden = !bon.fige;
        filtrer();
    }

    function envoyer(url, donnees, bouton, apres) {
        const fenetre = dialogue();
        const message = fenetre.querySelector('.materiel-message');
        const libelle = bouton.textContent;
        bouton.disabled = true;
        message.classList.remove('est-a-valider');
        message.textContent = bouton.classList.contains('materiel-relire')
            ? 'Lecture du bon…' : 'Enregistrement…';

        posterJson('actions/' + url, donnees)
            .then(reponse => {
                // Le compte rendu reste au pied tant que rien n'attend d'être validé.
                message.textContent = reponse.message || '';
                dernierMessage = reponse.message || '';
                rafraichirRang(reponse.document);
                apres(reponse);
            }, lu => {
                message.textContent = lu?.message || 'Opération impossible. Réessayez.';
            })
            .finally(() => {
                bouton.disabled = false;
                bouton.textContent = libelle;
            });
    }

    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.materiel-relire');
        if (!bouton) return;
        const fenetre = dialogue();
        const id = fenetre.querySelector('[name="document"]').value;
        if (!id) return;
        // Une relecture remplace ce qui est à l'écran : on relit un bon pour corriger une
        // première lecture, pas pour en empiler une seconde.
        if (fenetre.querySelectorAll('.materiel-edition-ligne').length
            && !await window.confirmer('Relire le bon ?', {
                texte: 'Les articles affichés seront remplacés par ceux de la nouvelle lecture.',
                action: 'Relire',
            })) return;

        const coches = cochesParArticle();
        envoyer('lire_bon_materiel.php', {
            csrf: fenetre.querySelector('[name="csrf"]').value,
            document: id,
        }, bouton, reponse => {
            poserEntete(reponse.document);
            remplirArticles(reponse.document);
            reposerCoches(coches);
        });
    });

    // « Enregistrer les corrections » : la liste revient, les coches en attente avec elle.
    document.addEventListener('submit', evenement => {
        const form = evenement.target.closest('#materiel-form');
        if (!form) return;
        evenement.preventDefault();
        const fenetre = dialogue();
        // Entrée dans une recherche soumettait le formulaire : seules les corrections
        // s'enregistrent par lui.
        if (!fenetre.classList.contains('en-correction')) return;
        const bouton = form.querySelector('.materiel-enregistrer');
        const coches = cochesParArticle();

        envoyer('save_bon_materiel.php', {
            csrf: form.elements.csrf.value,
            document: form.elements.document.value,
            lignes: JSON.stringify(lignesSaisies()),
            valider: '0',
        }, bouton, reponse => {
            // Les lignes sont réécrites côté serveur, avec de nouveaux identifiants : la
            // liste repart des siennes, et les coches retrouvent leurs articles.
            quitterCorrection();
            poserEntete(reponse.document);
            remplirArticles(reponse.document);
            reposerCoches(coches);
        });
    });

    // ----- « Modifier », sur la ligne d'une commande validée -----
    //
    // Validée, la commande est figée. « Modifier » la rouvre après confirmation, puis ouvre
    // le volet sur la correction de ses articles — c'est pour cela qu'on l'a demandé.
    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.materiel-modifier');
        if (!bouton) return;
        const id = bouton.dataset.document;
        if (!await window.confirmer('Modifier la commande validée ?', {
            texte: 'Elle repasse « à valider » : ses articles redeviennent modifiables, et il faudra la valider de nouveau. Les réceptions déjà pointées sont gardées.',
            action: 'Modifier',
        })) return;

        bouton.disabled = true;
        posterJson('actions/rouvrir_bon_materiel.php', {
            csrf: dialogue().querySelector('[name="csrf"]').value,
            document: id,
        })
            .then(reponse => {
                rafraichirRang(reponse.document);
                ouvrir(id);
                entrerCorrection();
                // Après ouvrir(), qui efface le message du bon précédent.
                dialogue().querySelector('.materiel-message').textContent = reponse.message || '';
            }, lu => {
                window.alerte(lu?.message || 'Impossible de rouvrir cette commande. Réessayez.', { ton: 'danger' });
            })
            .finally(() => { bouton.disabled = false; });
    });
})();
