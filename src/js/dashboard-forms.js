$(document).on('submit', '#passivation-form', function (event) {
    event.preventDefault();
    const form = $(this);
    if (form.data('submitting')) return;
    const message = form.find('.prefa-message');
    message.removeClass('success error').text('Enregistrement…');
    form.data('submitting', true).find('button').prop('disabled', true);
    $.ajax({
        url: 'actions/create_passivation.php', method: 'POST', data: form.serialize(), dataType: 'json',
        success: function (response) {
            $('#passivation-rows .passivation-empty').remove();
            const row = $('#passivation-rows tr').filter(function () { return $(this).attr('data-id') === String(response.id); });
            if (row.length) {
                row.find('.passivation-name').text(response.libelle);
            } else {
                $('<tr>').attr('data-id', response.id).append(
                    $('<td>').text(response.id),
                    $('<td>').addClass('passivation-name').text(response.libelle),
                    $('<td>').append($('<button>').attr('type', 'button').addClass('edit-passivation').attr({'hx-get': 'actions/edit_passivation.php?id=' + response.id, 'hx-target': 'closest tr', 'hx-swap': 'outerHTML'}).text('Modifier'))
                ).appendTo('#passivation-rows');
            }
            form[0].reset();
            htmx.process(document.getElementById('passivation-rows'));
            message.addClass('success').text(response.message);
        },
        error: function (xhr) { message.addClass('error').text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.'); },
        complete: function () { form.data('submitting', false).find('button').prop('disabled', false); }
    });
});

// Seules les pastilles qui comptent des demandes urgentes. Le sélecteur prenait toutes
// les pastilles de la barre, celle des PV comprise.
function updateUrgentBadge(count) {
    $('.nav-urgent-badge[data-compteur="urgentes"]').text(count).prop('hidden', count === 0)
        .attr('aria-label', count + ' demandes urgentes en attente');
}

function identityKey(form) {
    return JSON.stringify([
        form.find('[name="name"]').val().trim(),
        form.find('[name="prenom"]').val().trim()
    ]);
}

$(document).on('input change', '#create-user-form [name="name"], #create-user-form [name="prenom"]', function () {
    const form = $(this).closest('form');
    const message = form.find('#identifiant-message');
    const key = identityKey(form);
    const revision = (form.data('identity-revision') || 0) + 1;
    form.data('identity-revision', revision).removeData('available-identity');
    clearTimeout(form.data('identity-timer'));
    form.find('[type="submit"]').prop('disabled', true);
    message.removeClass('success error');

    if (!form.find('[name="name"]').val().trim() || !form.find('[name="prenom"]').val().trim()) {
        message.text('Saisissez le nom et le prénom.');
        return;
    }

    message.text('Vérification…');
    form.data('identity-timer', setTimeout(function () {
        $.ajax({
            url: 'actions/check_identifiant.php',
            method: 'POST',
            dataType: 'json',
            data: {
                name: form.find('[name="name"]').val().trim(),
                prenom: form.find('[name="prenom"]').val().trim()
            },
            success: function (response) {
                if (form.data('identity-revision') !== revision || identityKey(form) !== key) return;
                const available = response.available === true;
                message.addClass(available ? 'success' : 'error').text(response.message);
                if (available) form.data('available-identity', key);
                form.find('[type="submit"]').prop('disabled', !available || !!form.data('submitting'));
            },
            error: function (xhr) {
                if (form.data('identity-revision') !== revision || identityKey(form) !== key) return;
                message.addClass('error').text(xhr.responseJSON?.message || 'Vérification impossible. Modifiez le nom ou le prénom pour réessayer.');
            }
        });
    }, 300));
});

$(document).on('submit', '#create-user-form', function (e) {
    e.preventDefault();

    const form = $(this);
    const message = $('#form-message');

    if (form.data('submitting')) return;
    if (form.data('available-identity') !== identityKey(form)) {
        form.find('[name="name"]').trigger('input');
        return;
    }
    form.data('submitting', true);
    form.find('[type="submit"]').prop('disabled', true);

    // Nettoyage de l'ancien message
    message
        .removeClass('success error')
        .text('');

    $.ajax({
        url: 'actions/create_users.php',
        method: 'POST',
        data: form.serialize(),
        dataType: 'json',

        success: function (response) {

            message
                .addClass('success')
                .text(response.message);

            // Vider le formulaire
            form[0].reset();
            form.removeData('available-identity');
            form.data('identity-revision', (form.data('identity-revision') || 0) + 1);
            clearTimeout(form.data('identity-timer'));
            form.find('#identifiant-message').removeClass('success error').text('Saisissez le nom et le prénom.');

            // Ton select custom
            $('#role').val('');
        },

        error: function (xhr) {
            if (xhr.status === 409) form.removeData('available-identity');

            let errorMessage = 'Une erreur est survenue.';

            if (xhr.responseJSON?.message) {
                errorMessage = xhr.responseJSON.message;
            }

            message
                .addClass('error')
                .text(errorMessage);
        },
        complete: function () {
            form.data('submitting', false);
            form.find('[type="submit"]').prop('disabled', form.data('available-identity') !== identityKey(form));
        }
    });
});


$(document).on('input change', '#prefa-form [name="pouces_total_iso"]', function () {
    const form = $(this).closest('form');
    const rate = Number(form.attr('data-hours-per-inch'));
    const inches = Number(this.value);
    form.find('[name="heures_chiffrees"]').val(this.value !== '' && this.validity.valid ? (Math.round(inches * rate / 24 * 100) / 100).toFixed(2) : '');
});

$(document).on('submit', '#prefa-settings-form', function (event) {
    event.preventDefault();
    const form = $(this);
    if (form.data('submitting')) return;

    const message = form.find('.prefa-message').removeClass('success error');
    const hoursInput = form.find('[name="duree_heures"]');
    const minutesInput = form.find('[name="duree_minutes"]');

    if (hoursInput.length && minutesInput.length) {
        const h = parseInt(hoursInput.val(), 10) || 0;
        const m = parseInt(minutesInput.val(), 10) || 0;
        if (h === 0 && m === 0) {
            message.addClass('error').text('La durée doit être d’au moins 1 minute.');
            return;
        }
    }

    message.text('Enregistrement…');
    form.data('submitting', true).find('button').prop('disabled', true);
    $.ajax({
        url: 'actions/save_prefa_settings.php', method: 'POST', data: form.serialize(), dataType: 'json',
        success: function (response) {
            message.addClass('success').text(response.message);
            if (response.formatted) {
                form.find('.settings-preview-text').text('Actuellement : ' + response.formatted);
            }
        },
        error: function (xhr) { message.addClass('error').text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.'); },
        complete: function () { form.data('submitting', false).find('button').prop('disabled', false); }
    });
});

