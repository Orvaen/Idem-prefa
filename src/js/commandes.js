(() => {
    'use strict';

    // Le tableau des commandes du PV « Réception matériel » : ajouter une ligne, en retirer
    // une. Rien de plus — l'enregistrement part avec le formulaire du PV.
    //
    // Délégation sur le document : le volet des PV arrive par AJAX et se referme.

    const bloc = cible => cible.closest('[data-commandes]');

    /** Le message « aucune commande » ne s'affiche que quand il n'y en a vraiment aucune. */
    function compter(zone) {
        const lignes = zone.querySelectorAll('.commande-ligne').length;
        const vide = zone.querySelector('.commandes-vide');
        if (vide) vide.hidden = lignes > 0;
        zone.querySelector('.commandes-scroll').hidden = lignes === 0;
    }

    $(document).on('click', '.commandes-ajouter', function () {
        const zone = bloc(this);
        const gabarit = zone.querySelector('.commande-gabarit');
        const ligne = gabarit.content.firstElementChild.cloneNode(true);
        zone.querySelector('.commandes-lignes').appendChild(ligne);
        compter(zone);
        // Le curseur va dans le numéro : c'est par là qu'on commence à recopier le bon.
        ligne.querySelector('input').focus();
    });

    $(document).on('click', '.commande-retirer', function () {
        const zone = bloc(this);
        const ligne = this.closest('.commande-ligne');
        const numero = ligne.querySelector('[name="commande[numero][]"]').value.trim();
        // On ne demande confirmation que pour une ligne déjà remplie : retirer une ligne
        // qu'on vient d'ajouter par erreur ne mérite pas une question.
        if (numero && !window.confirm('Retirer la commande « ' + numero + ' » ? Elle sera supprimée à l’enregistrement.')) return;
        ligne.remove();
        compter(zone);
    });

    // La seconde échéance se déplie à la demande : une commande arrive rarement en deux
    // fois, et le champ resterait vide sur toutes les lignes le reste du temps.
    $(document).on('click', '.commande-plier-date', function () {
        const cellule = this.closest('.commande-livraison');
        cellule.classList.add('est-depliee');
        cellule.querySelector('.commande-date-2e').focus();
    });

    // Une commande marquée reçue sans date de livraison laisse le suivi aveugle : on
    // propose celle du jour plutôt que de refuser l'enregistrement.
    $(document).on('change', '[name="commande[statut][]"]', function () {
        if (this.value !== 'complete') return;
        const ligne = this.closest('.commande-ligne');
        const date = ligne.querySelector('[name="commande[date_bl][]"]');
        if (date && !date.value) date.value = new Date().toISOString().slice(0, 10);
    });
})();
