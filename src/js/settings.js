(function () {
    // Les anciennes adresses visaient un onglet (#hours, #passivation, #theme). Les onglets
    // sont devenus des sections de la même page : on y descend.
    var cible = location.hash.slice(1);
    if (cible === 'hours' || cible === 'passivation' || cible === 'theme') {
        var section = document.getElementById(cible);
        if (section) section.scrollIntoView({ block: 'start' });
    }

    // Le thème. La décision vit dans theme.js ; ici on montre l'état et on transmet le choix.
    var DITS = {
        auto: 'Le thème suit maintenant celui de votre système.',
        light: 'Thème clair activé.',
        dark: 'Thème sombre activé.'
    };

    var liste = document.getElementById('theme-choix');
    if (liste) liste.value = window.Theme ? window.Theme.choix() : 'auto';

    $(document).off('change.reglages').on('change.reglages', '#theme-choix', function () {
        if (window.Theme) window.Theme.appliquer(this.value);
        var message = document.getElementById('theme-status-message');
        if (message) {
            message.className = 'prefa-message success';
            message.textContent = DITS[this.value] || DITS.auto;
        }
    });
})();
