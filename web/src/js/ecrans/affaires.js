// L'écran des affaires : ouvrir un chantier, le renommer, changer son chargé d'affaire,
// le supprimer tant qu'aucune demande n'y est rattachée. Le chargé d'affaire y voit les
// siennes en lecture — « Mes affaires » : seules la recherche et l'ouverture des demandes
// le concernent, le dépôt passe par prefa-interactions.js.
//
// Délégation : la page arrive par AJAX dans #content.
(() => {
    'use strict';

    const page = () => document.querySelector('.affaires-page');
    const jeton = () => (page() || {}).dataset?.csrf || '';
    const fenetre = () => document.getElementById('affaire-dialog');

    /**
     * Ce que l'écran répond.
     *
     * Deux places, selon d'où vient le geste. **Fenêtre ouverte**, le refus se lit dans la
     * fenêtre — au-dessus du bouton qu'on vient de presser, et sans la refermer sur une
     * saisie à corriger. **Fenêtre fermée**, c'est un toast en bas à droite.
     *
     * Le toast a remplacé un paragraphe posé sous le bandeau. Celui-ci réservait sa ligne
     * en permanence pour un message de trois secondes, et surtout il partait avec l'écran :
     * une affaire enregistrée recharge la liste, donc effaçait la réponse qu'on venait
     * d'écrire. C'est pour ça que « dire » était appelé dans le rappel du rechargement.
     */
    function dire(texte, erreur) {
        const boite = fenetre();

        if (boite?.open) {
            const message = boite.querySelector('.prefa-message');
            if (!message) return;
            message.textContent = texte;
            message.classList.toggle('is-error', Boolean(erreur));
            return;
        }

        window.toast?.(texte, { erreur: Boolean(erreur) });
    }

    function envoyer(donnees) {
        donnees.set('csrf', jeton());
        return fetch('actions/affaires.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })));
    }

    /**
     * Recharge l'écran : le nombre de demandes et les boutons en dépendent.
     *
     * Le mot de la fin n'est dit qu'une fois la page revenue : elle est remplacée entière,
     * et un message écrit avant le rechargement partirait avec l'ancienne.
     */
    function recharger(ensuite) {
        // La recherche en cours survit au rechargement : on vient de renommer une affaire
        // dans une liste filtrée, et la voir repartir entière à chaque enregistrement
        // obligerait à retaper sa recherche entre deux corrections.
        const q = document.getElementById('affaires-search')?.value || '';
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, 'pages/affaires.php').then(() => {
            const champ = document.getElementById('affaires-search');
            if (champ && q) {
                champ.value = q;
                filtrer();
            }
            ensuite && ensuite();
        });
    }

    // ---------- La recherche ----------

    /** Sans accents ni casse : on tape « prefa » et « Préfa » répond. */
    const aplatir = (texte) => texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    /**
     * Le nom d'une carte : la valeur du champ pour le chargé de production, qui le corrige
     * sur place ; le texte du titre pour le chargé d'affaire, qui ne fait que le lire.
     */
    const nomDeLaCarte = (carte) => {
        const nom = carte?.querySelector('.affaire-nom');
        return (nom ? nom.value ?? nom.textContent : '').trim();
    };

    /**
     * Le nom tel qu'il est enregistré — celui que portent les demandes. C'est lui qui ouvre la
     * liste : un renommage en cours, pas encore enregistré, la filtrait sur un nom que
     * personne ne porte, et l'affaire s'ouvrait sur une liste vide.
     */
    const nomEnregistre = (carte) => {
        const nom = carte?.querySelector('.affaire-nom');
        return (nom ? nom.defaultValue ?? nom.textContent : '').trim();
    };

    /**
     * Filtre les cartes sur le nom de l'affaire et sur son chargé d'affaire.
     *
     * Les deux se lisent dans la valeur d'un champ et non dans le texte de la page : ils
     * sont modifiables sur place, et c'est ce qu'on voit à l'écran qui doit répondre, même
     * si la saisie n'est pas encore enregistrée.
     */
    function filtrer() {
        const grille = document.querySelector('.affaires-grille');
        if (!grille) return;

        const q = aplatir((document.getElementById('affaires-search')?.value || '').trim());
        const cartes = grille.querySelectorAll('.affaire-carte');
        let visibles = 0;

        cartes.forEach(carte => {
            const nom = nomDeLaCarte(carte);
            // Un chargé d'affaire déjà désigné n'a plus de champ : son nom est du texte.
            const charge = carte.querySelector('.liste-filtrable-champ')?.value
                ?? carte.querySelector('.affaire-charge')?.textContent ?? '';
            const retenue = !q || aplatir(nom + ' ' + charge).includes(q);
            carte.classList.toggle('est-masquee', !retenue);
            if (retenue) visibles += 1;
        });

        const aucune = document.getElementById('affaires-aucune');
        if (aucune) aucune.hidden = visibles > 0 || cartes.length === 0;
    }

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#affaires-search')) filtrer();
    });

    // ---------- La fenêtre d'ouverture ----------

    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('#affaire-nouvelle')) return;
        const boite = fenetre();
        if (!boite) return;
        // Une fenêtre s'ouvre vierge : la saisie abandonnée la fois d'avant n'a rien à
        // faire là.
        //
        // reset() ne suffit pas pour le champ filtrable. Il range son choix dans un
        // <input type="hidden">, et la valeur d'un champ caché *est* son attribut : en
        // l'écrivant, le composant a changé la valeur par défaut — celle-là même que
        // reset() restaure. Le choix d'un chargé d'affaire survivait donc à la fermeture,
        // et la fenêtre rouvrait sur un nom vide et une personne déjà désignée.
        boite.querySelector('form')?.reset();
        boite.querySelectorAll('.liste-filtrable-champ, .liste-filtrable input[type="hidden"]')
            .forEach(champ => { champ.value = ''; });
        boite.querySelectorAll('.liste-filtrable [role="option"]').forEach(option => {
            option.setAttribute('aria-selected', option.dataset.value === '' ? 'true' : 'false');
        });
        // Le refus de la fois d'avant s'efface ici, et non par dire() : la fenêtre n'est pas
        // encore ouverte, le message serait allé sur la ligne de la page.
        const message = boite.querySelector('.prefa-message');
        if (message) {
            message.textContent = '';
            message.classList.remove('is-error');
        }
        boite.showModal();
        boite.querySelector('#affaire-nom')?.focus();
    });

    document.addEventListener('click', evenement => {
        evenement.target.closest('.affaire-dialog-fermer')?.closest('dialog')?.close();
    });

    document.addEventListener('submit', evenement => {
        const form = evenement.target.closest('#affaire-form');
        if (!form) return;
        evenement.preventDefault();
        const nom = form.querySelector('[name="nom"]');
        if (!nom.value.trim()) return dire('Donnez un nom à l’affaire.', true);

        const donnees = new URLSearchParams();
        donnees.set('operation', 'ouvrir');
        donnees.set('nom', nom.value.trim());
        // Le champ filtrable dépose son choix dans un champ caché, id_charge ; la clé
        // envoyée reste « charge », c'est ce que l'action attend.
        donnees.set('charge', form.querySelector('[name="id_charge"]')?.value || '');

        envoyer(donnees).then(({ ok, corps }) => {
            // Un refus garde la fenêtre ouverte, avec la saisie : c'est le nom qu'on
            // corrige, pas tout le formulaire qu'on recommence.
            if (!ok) return dire(corps.message || 'Impossible d’ouvrir cette affaire.', true);
            fenetre()?.close();
            recharger(() => dire(corps.message, false));
        }).catch(() => dire('Impossible d’ouvrir cette affaire. Réessayez.', true));
    });

    // ---------- Ouvrir les demandes d'une affaire ----------

    /**
     * La liste des demandes, filtrée sur le nom de l'affaire.
     *
     * On passe par la recherche de l'écran et non par un filtre caché : le nom s'écrit
     * alors dans le champ de recherche, à la vue de tous. On voit pourquoi la liste est
     * courte, et on l'efface d'un geste — un filtre invisible, on le subit.
     *
     * Les demandes portent le *nom* de l'affaire et non son identifiant (d.nom_affaire) :
     * la recherche est donc exactement le bon outil, pas un pis-aller.
     */
    /**
     * La liste des demandes sur une recherche, depuis la fiche. Rend la promesse de
     * ouvrirVue : vrai une fois la liste posée.
     */
    function ouvrirLaListe(q) {
        const bouton = document.getElementById('btn-list-prefa');
        if (!bouton || !q) return Promise.resolve(false);

        const page = bouton.dataset.page;
        return ouvrirVue(bouton, { page: page + (page.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q) });
    }

    const ouvrirLesDemandes = (nom) => ouvrirLaListe(nom);

    /**
     * Une demande de l'affaire : la liste, resserrée sur sa référence, et sa fiche dépliée
     * — c'est elle qu'on a cliquée, pas la liste.
     */
    function ouvrirUneDemande(reference, id) {
        ouvrirLaListe(reference).then(ok => {
            if (!ok) return;
            const bascule = document.querySelector('.prefa-table-demandes .prefa-toggle[aria-controls="prefa-detail-' + id + '"]');
            if (bascule && bascule.getAttribute('aria-expanded') !== 'true') bascule.click();
        });
    }

    // ---------- La fiche d'une affaire ----------
    //
    // Un clic sur une carte ouvre sa fiche : le chargé d'affaire, les demandes par statut, et
    // chaque demande. Le clic menait directement à la liste des demandes, et seulement quand
    // il y en avait : sur une affaire vide, ou sur le nom — un champ —, il ne se passait rien.
    const fiche = () => document.getElementById('affaire-fiche');

    function ouvrirFiche(carte) {
        const boite = fiche();
        const modele = carte?.querySelector('template.affaire-fiche-modele');
        if (!boite || !modele) return;
        boite.replaceChildren(modele.content.cloneNode(true));
        boite.carte = carte;
        boite.showModal();
        // Le focus va à la première demande, à défaut au pied : la croix d'en-tête n'est pas
        // ce qu'on vient chercher.
        (boite.querySelector('.affaire-fiche-demande')
            || boite.querySelector('.fenetre-pied button:last-child'))?.focus();
    }

    document.addEventListener('click', evenement => {
        const carte = evenement.target.closest('.affaire-carte');
        if (!carte) return;
        // Les commandes de la carte gardent leur geste : on ne quitte pas l'écran en
        // cliquant dans le champ qu'on vient corriger, ni sur la corbeille. Le nom au repos,
        // en lecture seule, n'en est pas une : c'est le titre de la carte, et il l'ouvre.
        // Le compte des demandes l'ouvre aussi, par son propre gestionnaire.
        if (evenement.target.closest('input:not([readonly]), button, a, .liste-filtrable')) return;

        // Une correction en cours : la fiche montrerait le nom enregistré à côté de celui
        // qu'on tape. On le dit plutôt que de ne rien faire.
        if (carte.classList.contains('est-modifiee')) {
            window.toast?.('Enregistrez ou annulez la modification de cette affaire avant de l’ouvrir.', { info: true });
            return;
        }
        ouvrirFiche(carte);
    });

    // Le compte des demandes est le chemin annoncé : c'est lui qu'on atteint au clavier.
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('button.affaire-demandes');
        if (bouton) ouvrirFiche(bouton.closest('.affaire-carte'));
    });

    document.addEventListener('click', evenement => {
        const boite = evenement.target.closest('#affaire-fiche');
        if (!boite) return;

        // Un clic sur le voile — la fenêtre elle-même, hors de sa boîte — la referme.
        if (evenement.target === boite || evenement.target.closest('.affaire-fiche-fermer')) {
            boite.close();
            return;
        }

        const demande = evenement.target.closest('.affaire-fiche-demande');
        if (demande) {
            boite.close();
            ouvrirUneDemande(demande.dataset.reference, demande.dataset.id);
            return;
        }

        if (evenement.target.closest('.affaire-fiche-toutes')) {
            boite.close();
            ouvrirLesDemandes(nomEnregistre(boite.carte));
            return;
        }

        // « Déposer une demande » : prefa-interactions.js ouvre le formulaire sur ce même
        // clic. La fiche s'efface pour ne pas rester dessous.
        if (evenement.target.closest('.affaire-fiche-deposer')) boite.close();
    });

    // ---------- Renommer ----------

    // Le crayon rend le nom modifiable, le curseur dedans et le texte sélectionné : on tape
    // le nouveau nom par-dessus l'ancien.
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.affaire-renommer');
        if (!bouton) return;
        const champ = bouton.closest('[data-affaire]')?.querySelector('input.affaire-nom');
        if (!champ) return;
        champ.readOnly = false;
        champ.tabIndex = 0;
        champ.focus();
        champ.select();
    });

    // Annuler rend la carte telle qu'elle est enregistrée. L'écran est relu plutôt que la
    // carte remise à la main : le champ filtrable range son choix dans un champ caché dont
    // la valeur par défaut a déjà changé — on ne saurait plus la rétablir.
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.affaire-annuler')) recharger();
    });

    // Au clavier, dans le nom : Entrée enregistre, Échap annule.
    document.addEventListener('keydown', evenement => {
        const champ = evenement.target.closest?.('.affaire-carte input.affaire-nom:not([readonly])');
        if (!champ) return;
        if (evenement.key === 'Enter') {
            evenement.preventDefault();
            champ.closest('[data-affaire]')?.querySelector('.affaire-enregistrer')?.click();
        } else if (evenement.key === 'Escape') {
            evenement.preventDefault();
            recharger();
        }
    });

    // Une demande déposée depuis une carte change son compte : l'écran se recharge derrière
    // la fenêtre qui vient de se refermer. Le focus était sur « Déposer une demande », et
    // il y retourne — sur la carte redessinée, l'ancien bouton n'existe plus.
    document.addEventListener('demande:enregistree', (evenement) => {
        if (!page()) return;
        const affaire = evenement.detail?.declencheur?.dataset?.affaire;
        recharger(() => {
            if (affaire) document.querySelector('.affaire-deposer[data-affaire="' + affaire + '"]')?.focus();
        });
    });

    // ---------- Le tableau ----------

    // Tant qu'on n'a rien changé, il n'y a rien à enregistrer : le bouton ne paraît qu'une
    // fois la ligne touchée. C'est aussi ce qui dit, d'un coup d'œil sur le tableau, quelles
    // lignes attendent encore d'être validées.
    const surModification = evenement => {
        const champ = evenement.target.closest('.affaire-nom, .liste-filtrable input[type="hidden"]');
        if (!champ) return;
        // « [data-affaire] » et non « tr » : les affaires sont des cartes depuis qu'elles
        // ont quitté le tableau, et un closest('tr') n'y trouvait plus rien — le bouton
        // « Enregistrer » ne serait jamais apparu.
        champ.closest('[data-affaire]')?.classList.add('est-modifiee');
    };
    document.addEventListener('input', surModification);
    document.addEventListener('change', surModification);

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.affaire-enregistrer');
        if (!bouton) return;
        const ligne = bouton.closest('[data-affaire]');
        const donnees = new URLSearchParams();
        donnees.set('operation', 'modifier');
        donnees.set('id', ligne.dataset.affaire);
        donnees.set('nom', ligne.querySelector('.affaire-nom').value.trim());
        // Le champ filtrable de la ligne range son choix dans son champ caché. Un chargé
        // d'affaire déjà désigné n'a plus de champ : on renvoie celui qui est en place, et le
        // serveur refuserait tout autre.
        const choix = ligne.querySelector('.liste-filtrable input[type="hidden"]');
        donnees.set('charge', choix ? choix.value : (ligne.querySelector('.affaire-charge')?.dataset.charge || ''));

        envoyer(donnees).then(({ ok, corps }) => {
            const message = corps.message || (ok ? 'Affaire modifiée.' : 'Modification impossible.');
            if (!ok) return dire(message, true);
            recharger(() => dire(message, false));
        }).catch(() => dire('Modification impossible. Réessayez.', true));
    });

    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('.affaire-supprimer');
        if (!bouton) return;
        const ligne = bouton.closest('[data-affaire]');
        const nom = ligne.querySelector('.affaire-nom').value.trim();
        // Une affaire supprimée ne se retrouve pas : on demande avant.
        if (!await window.confirmer('Supprimer l’affaire « ' + nom + ' » ?', {
            action: 'Supprimer',
            ton: 'danger',
        })) return;

        const donnees = new URLSearchParams();
        donnees.set('operation', 'fermer');
        donnees.set('id', ligne.dataset.affaire);

        envoyer(donnees).then(({ ok, corps }) => {
            const message = corps.message || (ok ? 'Affaire supprimée.' : 'Suppression impossible.');
            if (!ok) return dire(message, true);
            recharger(() => dire(message, false));
        }).catch(() => dire('Suppression impossible. Réessayez.', true));
    });

    // ===== Aucun chargé d'affaire : créer le compte, sur place =====
    // Une petite fenêtre par-dessus ce qu'on faisait (pages/affaires.php, #charge-dialog). Le
    // bouton menait à « Utilisateurs » : la fenêtre d'ouverture se refermait sur ce qu'on avait
    // tapé, et rien ne ramenait à l'affaire (6 novembre 2026). Créé, le compte montre son code
    // d'accès, puis :
    //  - depuis la fenêtre d'ouverture, il s'ajoute à son champ et s'y choisit — l'affaire
    //    reste telle qu'on l'avait tapée ;
    //  - depuis une carte, l'écran se recharge : chaque affaire sans chargé le propose.
    const fenetreCharge = () => document.getElementById('charge-dialog');
    let origineCharge = null;

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.affaires-creer-charge');
        const boite = fenetreCharge();
        if (!bouton || !boite) return;
        origineCharge = bouton;
        const form = boite.querySelector('form');
        form.reset();
        const message = form.querySelector('.fenetre-message');
        message.textContent = '';
        message.classList.remove('is-error');
        boite.showModal();
        form.elements.prenom.focus();
    });

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('[data-fermer-charge]')) fenetreCharge()?.close();
    });

    /** Le compte créé rejoint le champ de la fenêtre d'ouverture, et s'y choisit. */
    function choisirNouveauCharge(compte) {
        const boite = fenetre();
        const champ = boite?.querySelector('.affaire-dialog-charge');
        const liste = champ?.querySelector('[role="listbox"]');
        if (!liste || !compte) return;
        const option = document.createElement('div');
        option.id = 'charge-option-' + compte.id;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.dataset.value = String(compte.id);
        option.textContent = compte.libelle;
        liste.insertBefore(option, liste.querySelector('.liste-filtrable-vide'));
        champ.hidden = false;
        boite.querySelector('.affaire-dialog-sans-charge')?.remove();
        // Le composant choisit au clic (liste-filtrable.js) : le texte, le champ caché, la marque.
        option.click();
        // Le focus sur « Ouvrir l’affaire » : posé sur le champ, il en rouvrait la liste.
        boite.querySelector('.affaire-ouvrir')?.focus();
    }

    document.addEventListener('submit', async evenement => {
        const form = evenement.target.closest('#charge-form');
        if (!form) return;
        evenement.preventDefault();
        const message = form.querySelector('.fenetre-message');
        const bouton = form.querySelector('[type="submit"]');
        const donnees = new FormData(form);
        donnees.set('csrf', jeton());
        bouton.disabled = true;
        message.textContent = '';
        message.classList.remove('is-error');
        let corps = null;
        let ok = false;
        try {
            const reponse = await fetch('actions/create_users.php', { method: 'POST', body: donnees });
            corps = await reponse.json().catch(() => null);
            ok = reponse.ok && Boolean(corps?.success);
        } catch {
            ok = false;
        }
        bouton.disabled = false;
        if (!ok) {
            message.textContent = corps?.message || 'Création impossible. Réessayez.';
            message.classList.add('is-error');
            return;
        }

        fenetreCharge().close();
        // Son code d'abord : il n'est montré qu'une fois, et la personne en a besoin pour entrer.
        await window.montrerCodeAcces?.(corps.codeAcces);
        if (origineCharge?.closest('#affaire-dialog') && fenetre()?.open) {
            choisirNouveauCharge(corps.compte);
        } else {
            recharger(() => window.toast?.('Compte créé : choisissez-le sur l’affaire.'));
        }
        origineCharge = null;
    });
})();
