(function () {
    // Les anciennes adresses visaient un onglet (#hours, #passivation, #theme). Les onglets
    // sont devenus des sections de la même page : on y descend.
    var cible = location.hash.slice(1);
    if (cible === 'hours' || cible === 'passivation' || cible === 'theme') {
        var section = document.getElementById(cible);
        if (section) section.scrollIntoView({ block: 'start' });
    }

    // Le thème. La décision vit dans theme.js ; ici on montre l'état et on transmet le choix.
    function montrerChoix() {
        var courant = window.Theme ? window.Theme.choix() : 'auto';
        document.querySelectorAll('.theme-choix').forEach(function (bouton) {
            bouton.setAttribute('aria-pressed', String(bouton.dataset.theme === courant));
        });
    }

    var DITS = {
        auto: 'Le thème suit maintenant celui de votre système.',
        light: 'Thème clair activé.',
        dark: 'Thème sombre activé.'
    };

    $(document).off('click.reglages').on('click.reglages', '.theme-choix', function () {
        var choix = this.dataset.theme;
        if (window.Theme) window.Theme.appliquer(choix);
        var message = document.getElementById('theme-status-message');
        if (message) {
            message.className = 'prefa-message success';
            message.textContent = DITS[choix] || DITS.auto;
        }
        montrerChoix();
    });

    montrerChoix();
})();
