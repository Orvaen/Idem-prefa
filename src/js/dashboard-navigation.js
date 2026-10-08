$(function () {

    let isResizing = false;

    const container = $('#contenaire');
    const left = $('#left-panel');
    const drag = $('#drag'); // <-- il manquait celui-ci

    drag.on('mousedown', function (e) {

        isResizing = true;
        drag.addClass('is-resizing');

        e.preventDefault();
    });

    $(document).on('mousemove', function (e) {

        if (!isResizing) {
            return;
        }

        const containerLeft = container.offset().left;
        const newWidth = e.clientX - containerLeft;

        const minWidth = 150;
        const maxWidth = 600;

        if (newWidth >= minWidth && newWidth <= maxWidth) {
            left.css('width', newWidth + 'px');
        }
    });

    $(document).on('mouseup', function () {

        isResizing = false;
        drag.removeClass('is-resizing');

    });

});

$('#btn-create-user, #btn-list-users, #btn-prefa, #btn-list-prefa, #btn-urgent-prefa, #btn-plans-iso, #btn-planning, #btn-pointage, #btn-pv, #btn-settings').on('click', function () {
    const button = $(this);
    const pages = {
        'btn-create-user': 'pages/create_users.php',
        'btn-list-users': 'pages/lists_users.php',
        'btn-prefa': 'pages/create_prefa.php',
        'btn-list-prefa': 'pages/lists_prefa.php',
        'btn-urgent-prefa': 'pages/lists_prefa.php?view=urgent',
        'btn-plans-iso': 'pages/plans_iso.php',
        'btn-planning': 'pages/planning.php',
        'btn-pointage': 'pages/pointage.php',
        'btn-pv': 'pages/pv.php',
        'btn-settings': 'pages/settings.php'
    };
    const page = pages[this.id];

    $('#content').load(page, function (response, status, xhr) {
        if (status === 'error') {
            const message = xhr?.status === 401
                ? 'Votre session a expiré. Reconnectez-vous puis réessayez.'
                : 'Impossible de charger la page. Rechargez le tableau de bord puis réessayez.';
            $('#content').text(message);
            return;
        }

        $('.nav-header .nav-btn').removeClass('is-active').removeAttr('aria-current');
        button.addClass('is-active').attr('aria-current', 'page');
        const urgentTotal = document.getElementById('prefa-urgent-total');
        if (urgentTotal) updateUrgentBadge(Number(urgentTotal.dataset.count));
        if (window.matchMedia('(max-width: 900px)').matches) {
            setSidebar(false);
        }
        htmx.process(document.getElementById('content'));
        $('#create-user-form [name="name"]').trigger('input');
    });
});


