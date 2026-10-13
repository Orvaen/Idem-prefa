(() => {
    // « Mes affectations » et « Pointage » partagent le même endpoint : on recharge la page ouverte.
    const pagePersonnel = () => document.getElementById('pointage-page') ? 'pages/pointage.php' : 'pages/atelier.php';

    function refreshStaff(expanded = []) {
        if (!Array.isArray(expanded)) expanded = [];
        $('#content').load(pagePersonnel(), function (_, status) {
            if (status === 'error') {
                $(this).text('Impossible de charger vos affectations. Actualisez la page.');
                return;
            }
            expanded.forEach(id => {
                const row = document.getElementById(id);
                if (row) row.hidden = false;
                document.querySelectorAll('.prefa-toggle').forEach(button => {
                    if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
                });
            });
        });
    }
    $(document).on('click', '.atelier-refresh', refreshStaff);
    $(document).on('click', '.atelier-open-plan-create', function () {
        const dialog = document.getElementById(this.dataset.dialog);
        if (!dialog) return;
        // Le dialogue est rendu dans le panneau « Plans & affectations », masqué tant qu'il est replié :
        // on le sort du panneau le temps de l'afficher, sinon il faudrait le déplier pour voir la fenêtre.
        dialog.atelierHome ??= dialog.parentNode;
        document.body.append(dialog);
        dialog.addEventListener('close', () => { if (!dialog.open) dialog.atelierHome.append(dialog); }, { once: true });
        const form = dialog.querySelector('form');
        form.reset();
        form.querySelector('.atelier-message').textContent = '';
        dialog.showModal();
        form.elements.reference.focus();
    });
    $(document).on('click', '.atelier-open-plan-edit', function () {
        const dialog = document.getElementById(this.dataset.dialog);
        if (!dialog) return;
        const form = dialog.querySelector('form');
        form.reset();
        form.elements.element.value = this.dataset.element;
        form.elements.reference.value = this.dataset.reference;
        form.elements.libelle.value = this.dataset.description;
        form.querySelector('.atelier-message').textContent = '';
        dialog.showModal();
        form.elements.reference.focus();
    });
    $(document).on('click', '.atelier-edit-progress', function () {
        const dialog = document.getElementById(this.dataset.dialog);
        if (!dialog) return;
        const form = dialog.querySelector('form');
        form.reset();
        form.elements.affectation.value = this.dataset.affectation;
        form.elements.revision.value = this.dataset.revision;
        form.elements.avancement.value = this.dataset.avancement;
        form.elements.commentaire.value = this.dataset.commentaire;
        dialog.querySelector('.atelier-progress-target').textContent = this.dataset.label;
        form.querySelector('.atelier-message').textContent = '';
        dialog.showModal();
        form.elements.avancement.focus();
    });
    $(document).on('click', '.atelier-close-plan-edit', function () {
        this.closest('dialog')?.close();
    });
    $(document).on('click', '.atelier-tab', function () {
        this.closest('.atelier-tablist').querySelectorAll('.atelier-tab').forEach(onglet => {
            const actif = onglet === this;
            onglet.setAttribute('aria-selected', String(actif));
            onglet.tabIndex = actif ? 0 : -1;
            document.getElementById(onglet.getAttribute('aria-controls')).hidden = !actif;
        });
    });
    $(document).on('keydown', '.atelier-tab', function (event) {
        const pas = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        if (!pas) return;
        event.preventDefault();
        const onglets = Array.from(this.closest('.atelier-tablist').querySelectorAll('.atelier-tab'));
        const suivant = onglets[(onglets.indexOf(this) + pas + onglets.length) % onglets.length];
        suivant.focus();
        suivant.click();
    });
    $(document).on('click', '.atelier-edit-assignment', function () {
        const panel = document.getElementById(this.dataset.form);
        if (!panel) return;
        const form = panel.querySelector('form');
        const user = this.dataset.user || '';
        form.elements.type.value = this.dataset.type;
        form.elements.utilisateur.value = user;
        panel.querySelector('.atelier-manage-title').textContent = (user ? 'Modifier : ' : 'Affecter : ') + this.dataset.label;
        panel.querySelector('.atelier-remove-assignment').hidden = !user;
        panel.querySelector('.atelier-message').textContent = '';
        document.querySelectorAll('.atelier-edit-assignment').forEach(button => {
            if (button.dataset.form === this.dataset.form) button.setAttribute('aria-expanded', String(button === this));
        });
        panel.hidden = false;
        const editorRow = panel.closest('.atelier-editor-row');
        if (editorRow) editorRow.hidden = false;
        form.elements.utilisateur.focus();
    });
    $(document).on('click', '.atelier-cancel-assignment', function () {
        const panel = this.closest('.atelier-manage-panel');
        panel.hidden = true;
        const editorRow = panel.closest('.atelier-editor-row');
        if (editorRow) editorRow.hidden = true;
        document.querySelectorAll('.atelier-edit-assignment').forEach(button => {
            if (button.dataset.form === panel.id) button.setAttribute('aria-expanded', 'false');
        });
    });
    $(document).on('submit', '.atelier-action', function (event) {
        event.preventDefault();
        const form = $(this);
        const endpoint = this.dataset.endpoint;
        if (!['save_atelier.php', 'update_atelier.php'].includes(endpoint)) return;
        if (this.classList.contains('atelier-delete-plan') && !window.confirm(
            'Supprimer le plan « ' + this.dataset.planName + ' » ? Ses affectations et leur suivi seront supprimés. Les fichiers joints à la demande seront conservés.'
        )) return;
        const data = new URLSearchParams(form.serialize());
        if (event.originalEvent?.submitter?.value === 'revoke') data.set('operation', 'revoke');
        const message = form.find('.atelier-message');
        const buttons = form.find('button');
        buttons.prop('disabled', true);
        message.text('Enregistrement…');
        const expanded = Array.from(document.querySelectorAll('.prefa-toggle[aria-expanded="true"]')).map(button => button.getAttribute('aria-controls'));
        $.ajax({ url: 'actions/' + endpoint, method: 'POST', data: data.toString(), dataType: 'json' })
            .done(function () {
                form[0].closest('dialog')?.close();
                // Sur la page Plans / ISO, c’est elle qu’il faut recharger, pas « Mes affectations ».
                if (endpoint === 'update_atelier.php' && !document.getElementById('plans-iso-page')) { refreshStaff(expanded); return; }
                const prefaFilters = document.getElementById('prefa-filters');
                if (prefaFilters) {
                    const params = new URLSearchParams(new FormData(prefaFilters));
                    params.set('results', '1');
                    $.get('pages/lists_prefa.php?' + params.toString()).done(function (html) {
                        $('#prefa-results').replaceWith(html);
                        expanded.forEach(id => {
                            const row = document.getElementById(id);
                            if (row) row.hidden = false;
                            document.querySelectorAll('.prefa-toggle').forEach(button => {
                                if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
                            });
                        });
                    }).fail(function () { message.text('Enregistrement effectué. Actualisez pour voir le résultat.'); });
                } else if (document.getElementById('plans-iso-page')) {
                    const openCardIds = Array.from(document.querySelectorAll('.plans-iso-detail:not([hidden])')).map(c => c.id);
                    // Enregistrement fait depuis une fenêtre : déplier le panneau pour montrer le résultat.
                    const detailId = 'plans-iso-detail-' + form[0].elements.id.value;
                    if (!openCardIds.includes(detailId)) openCardIds.push(detailId);
                    $('#content').load('pages/plans_iso.php', function () {
                        openCardIds.forEach(id => {
                            const card = document.getElementById(id);
                            if (card) {
                                card.hidden = false;
                                document.querySelectorAll('.plans-iso-toggle').forEach(button => {
                                    if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
                                });
                            }
                        });
                    });
                }
            })
            .fail(xhr => message.text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.'))
            .always(() => buttons.prop('disabled', false));
    });
})();
