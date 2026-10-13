// L'écran des affaires : ouvrir un chantier, le renommer, changer son chargé d'affaire,
// le supprimer tant qu'aucune demande n'y est rattachée.
//
// Délégation : la page arrive par AJAX dans #content.
(() => {
    'use strict';

    const page = () => document.querySelector('.affaires-page');
    const jeton = () => (page() || {}).dataset?.csrf || '';

    /** Le message sous le formulaire : vert quand ça passe, rouge quand ça ne passe pas. */
    function dire(texte, erreur) {
        const message = document.querySelector('.affaire-form .prefa-message');
        if (!message) return;
        message.textContent = texte;
        message.classList.toggle('is-error', Boolean(erreur));
    }

    function envoyer(donnees) {
        donnees.set('csrf', jeton());
        return fetch('actions/affaires.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })));
    }

    /** Recharge l'écran : le nombre de demandes et les boutons en dépendent. */
    function recharger() {
        $('#content').load('pages/affaires.php');
    }

    $(document).on('submit', '#affaire-form', function (evenement) {
        evenement.preventDefault();
        const nom = this.querySelector('[name="nom"]');
        if (!nom.value.trim()) return dire('Donnez un nom à l’affaire.', true);

        const donnees = new URLSearchParams();
        donnees.set('operation', 'ouvrir');
        donnees.set('nom', nom.value.trim());
        // Le champ filtrable dépose son choix dans un champ caché, id_charge ; la clé
        // envoyée reste « charge », c'est ce que l'action attend.
        donnees.set('charge', this.querySelector('[name="id_charge"]').value);

        envoyer(donnees).then(({ ok, corps }) => {
            if (!ok) return dire(corps.message || 'Impossible d’ouvrir cette affaire.', true);
            dire(corps.message, false);
            recharger();
        }).catch(() => dire('Impossible d’ouvrir cette affaire. Réessayez.', true));
    });

    // Tant qu'on n'a rien changé, il n'y a rien à enregistrer : le bouton ne paraît qu'une
    // fois la ligne touchée. C'est aussi ce qui dit, d'un coup d'œil sur le tableau, quelles
    // lignes attendent encore d'être validées.
    $(document).on('input change', '.affaire-nom, .liste-filtrable input[type="hidden"]', function () {
        this.closest('tr')?.classList.add('est-modifiee');
    });

    $(document).on('click', '.affaire-enregistrer', function () {
        const ligne = this.closest('[data-affaire]');
        const donnees = new URLSearchParams();
        donnees.set('operation', 'modifier');
        donnees.set('id', ligne.dataset.affaire);
        donnees.set('nom', ligne.querySelector('.affaire-nom').value.trim());
        // Le champ filtrable de la ligne range son choix dans son champ caché.
        donnees.set('charge', ligne.querySelector('.liste-filtrable input[type="hidden"]').value);

        envoyer(donnees).then(({ ok, corps }) => {
            dire(corps.message || (ok ? 'Affaire modifiée.' : 'Modification impossible.'), !ok);
            if (ok) recharger();
        }).catch(() => dire('Modification impossible. Réessayez.', true));
    });

    $(document).on('click', '.affaire-supprimer', function () {
        const ligne = this.closest('[data-affaire]');
        const nom = ligne.querySelector('.affaire-nom').value.trim();
        // Une affaire supprimée ne se retrouve pas : on demande avant.
        if (!window.confirm('Supprimer l’affaire « ' + nom + ' » ?')) return;

        const donnees = new URLSearchParams();
        donnees.set('operation', 'fermer');
        donnees.set('id', ligne.dataset.affaire);

        envoyer(donnees).then(({ ok, corps }) => {
            dire(corps.message || (ok ? 'Affaire supprimée.' : 'Suppression impossible.'), !ok);
            if (ok) recharger();
        }).catch(() => dire('Suppression impossible. Réessayez.', true));
    });
})();
