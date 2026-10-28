// L'écran des affaires : ouvrir un chantier, le renommer, changer son chargé d'affaire,
// le supprimer tant qu'aucune demande n'y est rattachée.
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
        $('#content').load('pages/affaires.php', () => {
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
            const nom = carte.querySelector('.affaire-nom')?.value || '';
            const charge = carte.querySelector('.liste-filtrable-champ')?.value || '';
            const retenue = !q || aplatir(nom + ' ' + charge).includes(q);
            carte.classList.toggle('est-masquee', !retenue);
            if (retenue) visibles += 1;
        });

        const aucune = document.getElementById('affaires-aucune');
        if (aucune) aucune.hidden = visibles > 0 || cartes.length === 0;
    }

    $(document).on('input', '#affaires-search', filtrer);

    // ---------- La fenêtre d'ouverture ----------

    $(document).on('click', '#affaire-nouvelle', () => {
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

    $(document).on('click', '.affaire-dialog-fermer', function () {
        this.closest('dialog')?.close();
    });

    $(document).on('submit', '#affaire-form', function (evenement) {
        evenement.preventDefault();
        const nom = this.querySelector('[name="nom"]');
        if (!nom.value.trim()) return dire('Donnez un nom à l’affaire.', true);

        const donnees = new URLSearchParams();
        donnees.set('operation', 'ouvrir');
        donnees.set('nom', nom.value.trim());
        // Le champ filtrable dépose son choix dans un champ caché, id_charge ; la clé
        // envoyée reste « charge », c'est ce que l'action attend.
        donnees.set('charge', this.querySelector('[name="id_charge"]')?.value || '');

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
    function ouvrirLesDemandes(nom) {
        const bouton = document.getElementById('btn-list-prefa');
        if (!bouton || !nom) return;

        const page = bouton.dataset.page;
        ouvrirVue(bouton, { page: page + (page.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(nom) });
    }

    const nomDeLaCarte = (carte) => (carte?.querySelector('.affaire-nom')?.value || '').trim();

    $(document).on('click', '.affaire-carte', function (evenement) {
        // Les commandes de la carte gardent leur geste : on ne quitte pas l'écran en
        // cliquant dans le champ qu'on vient corriger, ni sur la corbeille.
        if (evenement.target.closest('input, button, a, .liste-filtrable')) return;
        // Une affaire sans demande ne mène nulle part : on n'ouvre pas une liste vide.
        // Le serveur ne pose le bouton que s'il y en a, c'est lui qui fait foi.
        if (!this.querySelector('button.affaire-demandes')) return;
        ouvrirLesDemandes(nomDeLaCarte(this));
    });

    // Le compte des demandes est le chemin annoncé : c'est lui qu'on atteint au clavier.
    $(document).on('click', 'button.affaire-demandes', function () {
        ouvrirLesDemandes(nomDeLaCarte(this.closest('.affaire-carte')));
    });

    // ---------- Le tableau ----------

    // Tant qu'on n'a rien changé, il n'y a rien à enregistrer : le bouton ne paraît qu'une
    // fois la ligne touchée. C'est aussi ce qui dit, d'un coup d'œil sur le tableau, quelles
    // lignes attendent encore d'être validées.
    $(document).on('input change', '.affaire-nom, .liste-filtrable input[type="hidden"]', function () {
        // « [data-affaire] » et non « tr » : les affaires sont des cartes depuis qu'elles
        // ont quitté le tableau, et un closest('tr') n'y trouvait plus rien — le bouton
        // « Enregistrer » ne serait jamais apparu.
        this.closest('[data-affaire]')?.classList.add('est-modifiee');
    });

    $(document).on('click', '.affaire-enregistrer', function () {
        const ligne = this.closest('[data-affaire]');
        const donnees = new URLSearchParams();
        donnees.set('operation', 'modifier');
        donnees.set('id', ligne.dataset.affaire);
        donnees.set('nom', ligne.querySelector('.affaire-nom').value.trim());
        // Le champ filtrable de la ligne range son choix dans son champ caché.
        donnees.set('charge', ligne.querySelector('.liste-filtrable input[type="hidden"]')?.value || '');

        envoyer(donnees).then(({ ok, corps }) => {
            const message = corps.message || (ok ? 'Affaire modifiée.' : 'Modification impossible.');
            if (!ok) return dire(message, true);
            recharger(() => dire(message, false));
        }).catch(() => dire('Modification impossible. Réessayez.', true));
    });

    $(document).on('click', '.affaire-supprimer', async function () {
        const ligne = this.closest('[data-affaire]');
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

    // ===== Aucun chargé d'affaire : créer le compte =====
    // Le bouton mène à « Utilisateurs » et y ouvre « Nouvel utilisateur », rôle « Chargé
    // d'affaire » déjà choisi : sans cela, il fallait savoir quel rôle donner, et où.
    // L'écran arrive par AJAX : on guette sa fenêtre avant de l'ouvrir.
    const ROLE_CHARGE_AFFAIRE = '5';
    $(document).on('click', '.affaires-creer-charge', function () {
        fenetre()?.close();
        document.getElementById('btn-list-users')?.click();
        const debut = Date.now();
        const guetter = setInterval(() => {
            const bouton = document.querySelector('.utilisateurs-nouveau');
            const dialogue = document.getElementById('utilisateur-dialogue');
            if (bouton && dialogue) {
                clearInterval(guetter);
                bouton.click();
                dialogue.querySelector('[role="option"][data-value="' + ROLE_CHARGE_AFFAIRE + '"]')?.click();
                dialogue.querySelector('[name="prenom"]')?.focus();
            } else if (Date.now() - debut > 5000) {
                clearInterval(guetter);
            }
        }, 100);
    });
})();
