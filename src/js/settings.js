(function () {
    var tabs = document.querySelectorAll('.settings-tab-btn');
    var panes = document.querySelectorAll('.settings-pane');

    function show(name) {
        tabs.forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on);
        });
        panes.forEach(function (p) {
            var on = p.id === 'panel-' + name;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            show(t.dataset.tab);
            history.replaceState(null, '', location.pathname + location.search + '#' + t.dataset.tab);
        });
    });
    var start = location.hash.slice(1);
    if (start === 'hours' || start === 'passivation' || start === 'theme') show(start);

    // Gestion du sélecteur de thème. La décision vit dans theme.js ; ici on montre l'état
    // et on transmet le choix.
    function updateThemeSelectionUI() {
        var cur = window.Theme ? window.Theme.choix() : 'auto';
        document.querySelectorAll('.theme-card').forEach(function (card) {
            var val = card.dataset.themeVal;
            var isCur = val === cur;
            if (val === 'light') {
                card.style.borderColor = isCur ? '#2563eb' : '#cbd5e1';
                card.style.boxShadow = isCur ? '0 0 0 3px #2563eb, 0 6px 16px rgba(37, 99, 235, 0.25)' : 'none';
            } else {
                card.style.borderColor = isCur ? 'var(--accent)' : 'var(--border)';
                card.style.boxShadow = isCur ? '0 0 0 2px var(--accent)' : 'none';
            }
            var check = card.querySelector('.theme-check');
            if (check) check.textContent = isCur ? '✓' : '';
        });
    }

    var DITS = {
        auto: 'Le thème suit maintenant celui de votre système.',
        light: 'Thème clair activé.',
        dark: 'Thème sombre activé.'
    };

    function applyTheme(themeName) {
        if (window.Theme) window.Theme.appliquer(themeName);
        var msg = document.getElementById('theme-status-message');
        if (msg) {
            msg.className = 'prefa-message success';
            msg.textContent = DITS[themeName] || DITS.auto;
            msg.style.display = 'block';
        }
        updateThemeSelectionUI();
    }

    $(document).off('click', '.btn-apply-theme, .theme-card')
        .on('click', '.btn-apply-theme', function (e) {
            e.stopPropagation();
            applyTheme(this.dataset.theme);
        })
        .on('click', '.theme-card', function () {
            applyTheme(this.dataset.themeVal);
        });

    updateThemeSelectionUI();
})();