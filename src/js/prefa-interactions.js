// Les gestes du formulaire de demande et de la liste des demandes : ce qui dépend
// d'un champ, le filtrage de la liste, l'envoi et la relecture d'une demande.
//
// Les deux listes déroulantes filtrables — passivation et matière — vivaient ici en
// double, et une troisième et une quatrième copie dormaient dans create_prefa.php. Les
// quatre répondaient aux mêmes sélecteurs : chaque frappe était traitée deux fois, et
// deux des copies portaient la même faute de frappe, « index » au lieu de « idx », qui
// cassait la flèche du haut. Elles sont remplacées par liste-filtrable.js, monté une
// fois sur chaque préfixe.

$(document).on('change', '#prefa-form [name="CDN"]', function () {
    const form = $(this).closest('form');
    form.find('#cdn-details').prop('hidden', !this.checked);
    form.find('[name="controles_cdn"], #controles-value').prop('disabled', !this.checked).prop('required', this.checked);
});

// Un revêtement demande lequel. Le formulaire appelait en plus une fonction globale
// définie dans sa propre page, qui faisait la même chose à un détail près — porter le
// curseur dans le champ de précision. Elle est reprise ici, et la page n'a plus de JS.
$(document).on('change', '#prefa-form #revetement-type', function () {
    const revetu = this.value !== 'aucun';
    const details = $('#revetement-details');
    details.prop('hidden', !revetu).css('display', revetu ? 'flex' : 'none');
    const precision = details.find('#revetement-precision');
    if (!revetu) precision.val('');
    // Le champ vient d'apparaître : on y porte le curseur, après que le navigateur l'a
    // réellement affiché — on ne peut pas donner le focus à ce qui est encore caché.
    else setTimeout(() => precision.trigger('focus'), 50);
});

let prefaFilterTimer;
let prefaListRequest;
function refreshPrefaList() {
    const form = document.getElementById('prefa-filters');
    if (!form) return;
    const params = new URLSearchParams(new FormData(form));
    params.set('results', '1');
    if (prefaListRequest) prefaListRequest.abort();
    const request = $.get('pages/lists_prefa.php?' + params.toString(), function (html) {
        $('#prefa-results').replaceWith(html);
        const total = document.getElementById('prefa-urgent-total');
        if (total) updateUrgentBadge(Number(total.dataset.count));
    });
    prefaListRequest = request;
    request.always(function () { if (prefaListRequest === request) prefaListRequest = null; });
}
$(document).on('input', '#prefa-search', function () {
    clearTimeout(prefaFilterTimer);
    prefaFilterTimer = setTimeout(refreshPrefaList, 350);
});
// Le formulaire émet « change » quand la barre de filtres bouge — c'est elle qui prévient,
// elle n'appelle pas le rechargement : c'est l'écran qui sait comment se recharger.
$(document).on('change', '#prefa-filters', refreshPrefaList);
$(document).on('click', '#refresh-prefa', refreshPrefaList);
$(document).on('input', '#controles', function () {
    $('#controles-value').val(this.value).prop('required', true).get(0).setCustomValidity('');
});
$(document).on('input change', '#controles-value', function (event) {
    const rawValue = this.value.trim();
    const normalizedValue = rawValue.replace(',', '.');
    const validFormat = /^\d*(?:\.\d*)?$/.test(normalizedValue);
    const value = Number(normalizedValue);
    const validValue = validFormat && normalizedValue !== '' && Number.isFinite(value) && value >= 1 && value <= 100;
    if (event.type === 'change' && rawValue !== normalizedValue) this.value = normalizedValue;
    this.setCustomValidity(validValue || rawValue === '' ? '' : 'Saisissez une valeur entre 1 et 100.');
    if (validValue) $('#controles').val(normalizedValue);
});

$(document).on('change', '#plan-documents', function () {
    const files = Array.from(this.files);
    const summary = files.length ? files.length + ' document(s) à envoyer.' : 'Nombre de documents illimité · 50 Mo maximum par fichier.';
    $('#plan-documents-selected').text(summary);
    const list = $('#plan-selected-files').empty();
    files.forEach((file, index) => {
        const row = $('<div class="prefa-file-row">');
        row.append($('<span>').text(file.name));
        row.append($('<button type="button" class="prefa-remove-selected">').attr('data-index', index).text('Retirer'));
        list.append(row);
    });
});
$(document).on('change', '#qmos, #dmos, #materiel, #ccpu', function () {
    const kind = this.id;
    const files = Array.from(this.files);
    if (kind === 'qmos') {
        const saved = $('#qmos-saved-files .prefa-file-row:not(.is-removed)').length;
        const total = saved + files.length;
        const summary = total ? total + ' PDF QMOS à envoyer.' : 'Aucun QMOS sélectionné.';
        $('#qmos-help').text(summary);
    } else if (kind === 'dmos') {
        $('#dmos-help').text(files.length ? files.length + ' PDF DMOS à envoyer.' : 'Aucun nouveau fichier sélectionné.');
    } else if (kind === 'materiel') {
        $('#materiel-help').text(files.length ? files.length + ' fiche(s) matériel à envoyer.' : 'Aucun nouveau fichier sélectionné.');
    } else {
        $('#ccpu-help').text(files.length ? files.length + ' certificat(s) à envoyer.' : 'Aucun nouveau fichier sélectionné.');
    }
    const list = $('#' + kind + '-selected-files').empty();
    files.forEach((file, index) => {
        const row = $('<div class="prefa-file-row">');
        row.append($('<span>').text(file.name));
        row.append($('<button type="button" class="prefa-remove-pdf-selected">').attr({'data-index': index, 'data-input': kind}).text('Retirer'));
        list.append(row);
    });
});
$(document).on('click', '.prefa-remove-pdf-selected', function () {
    const input = document.getElementById(this.dataset.input);
    const remaining = new DataTransfer();
    Array.from(input.files).forEach((file, index) => {
        if (index !== Number(this.dataset.index)) remaining.items.add(file);
    });
    input.files = remaining.files;
    $(input).trigger('change');
});
$(document).on('click', '.prefa-remove-selected', function () {
    const input = document.getElementById('plan-documents');
    const remaining = new DataTransfer();
    Array.from(input.files).forEach((file, index) => {
        if (index !== Number(this.dataset.index)) remaining.items.add(file);
    });
    input.files = remaining.files;
    $(input).trigger('change');
});
$(document).on('click', '.prefa-remove-saved', function () {
    const row = $(this).closest('.prefa-file-row');
    const form = $(this).closest('form');
    if (row.hasClass('is-removed')) {
        row.removeClass('is-removed');
        form.find('input[name="remove_documents[]"]').filter((_, input) => input.value === this.dataset.key).remove();
        $(this).text('Retirer');
    } else {
        row.addClass('is-removed');
        form.append($('<input type="hidden" name="remove_documents[]">').val(this.dataset.key));
        $(this).text('Annuler');
    }
});
$(document).on('click', '.prefa-remove-qmos-saved, .prefa-remove-dmos-saved, .prefa-remove-materiel-saved, .prefa-remove-ccpu-saved', function () {
    const row = $(this).closest('.prefa-file-row');
    const form = $(this).closest('form');
    const name = $(this).hasClass('prefa-remove-qmos-saved') ? 'remove_qmos[]'
        : $(this).hasClass('prefa-remove-dmos-saved') ? 'remove_dmos[]'
        : $(this).hasClass('prefa-remove-materiel-saved') ? 'remove_materiel[]'
        : 'remove_ccpu[]';
    if (row.hasClass('is-removed')) {
        row.removeClass('is-removed');
        form.find('input[name="' + name + '"]').filter((_, input) => input.value === this.dataset.id).remove();
        $(this).text('Retirer');
    } else {
        row.addClass('is-removed');
        form.append($('<input type="hidden">').attr('name', name).val(this.dataset.id));
        $(this).text('Annuler');
    }
    if (name === 'remove_qmos[]') $('#qmos').trigger('change');
});
$(document).on('keydown', '.prefa-dropzone', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        document.getElementById(this.htmlFor).click();
    }
});
$(document).on('dragover', '.prefa-dropzone', function (event) {
    event.preventDefault();
    $(this).addClass('is-dragging');
});
$(document).on('dragleave drop', '.prefa-dropzone', function (event) {
    event.preventDefault();
    $(this).removeClass('is-dragging');
    if (event.type === 'drop') {
        const input = document.getElementById(this.htmlFor);
        const dropped = event.originalEvent.dataTransfer.files;
        if (input.multiple) {
            const combined = new DataTransfer();
            Array.from(input.files).concat(Array.from(dropped)).forEach(file => combined.items.add(file));
            input.files = combined.files;
        } else {
            input.files = dropped;
        }
        $(input).trigger('change');
    }
});

document.addEventListener('htmx:afterSwap', function () {
    const total = document.getElementById('prefa-urgent-total');
    if (total) updateUrgentBadge(Number(total.dataset.count));
});

$(document).on('click', '.edit-prefa', function () {
    $('#content').load('pages/create_prefa.php?id=' + encodeURIComponent(this.dataset.id), function (response, status) {
        if (status === 'error') $(this).text('Impossible de charger cette demande. Revenez à la liste puis réessayez.');
    });
});
$(document).on('click', '.cancel-prefa-edit', function () { $('#btn-list-prefa').trigger('click'); });

$(document).on('click', '.delete-prefa', function () {
    const id = this.dataset.id;
    if (!window.confirm('Supprimer cette demande ? Cette action est irréversible.')) return;
    const button = $(this).prop('disabled', true);
    $.ajax({
        url: 'actions/delete_prefa.php', method: 'POST', dataType: 'json',
        data: {id: id, csrf: this.dataset.csrf},
        success: function (response) { refreshPrefaList(); },
        error: function (xhr) { window.alert(xhr.responseJSON?.message || 'Suppression impossible. Réessayez.'); button.prop('disabled', false); }
    });
});

$(document).on('click', '.prefa-pdf-link', function (event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    let viewer = document.getElementById('prefa-pdf-viewer');
    if (!viewer) {
        viewer = document.createElement('dialog');
        viewer.id = 'prefa-pdf-viewer';
        viewer.className = 'prefa-pdf-viewer';
        viewer.setAttribute('aria-labelledby', 'prefa-pdf-title');
        viewer.innerHTML = '<header class="prefa-pdf-toolbar"><div><small>Aperçu PDF</small><h2 id="prefa-pdf-title"></h2></div><a class="prefa-pdf-download" download>Télécharger</a><button type="button" class="prefa-pdf-close" autofocus>Fermer</button></header><iframe title="Aperçu PDF"></iframe>';
        document.body.appendChild(viewer);
        viewer.querySelector('.prefa-pdf-close').addEventListener('click', () => viewer.close());
        viewer.addEventListener('click', event => {
            const bounds = viewer.getBoundingClientRect();
            if (event.target === viewer && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) viewer.close();
        });
        viewer.addEventListener('close', () => {
            viewer.querySelector('iframe').src = 'about:blank';
            document.body.classList.remove('prefa-viewer-open');
        });
    }
    viewer.querySelector('h2').textContent = this.dataset.name;
    viewer.querySelector('.prefa-pdf-download').href = this.href;
    const preview = new URL(this.href);
    preview.searchParams.set('preview', '1');
    viewer.querySelector('iframe').src = preview.href;
    viewer.showModal();
    document.body.classList.add('prefa-viewer-open');
});

$(document).on('click', '.prefa-toggle[aria-controls]:not(.plans-iso-toggle)', function () {
    const details = document.getElementById(this.getAttribute('aria-controls'));
    if (!details) return;
    const expanded = this.getAttribute('aria-expanded') === 'true';

    // Fermer les autres panneaux de détails ouverts pour n'en afficher qu'un à droite
    if (!expanded) {
        document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(row => {
            row.hidden = true;
        });
        document.querySelectorAll('.prefa-toggle[aria-expanded="true"]').forEach(btn => {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    this.setAttribute('aria-expanded', String(!expanded));
    details.hidden = expanded;
});

$(document).on('click', '.prefa-detail-close', function () {
    const targetId = this.dataset.closeTarget;
    const details = targetId ? document.getElementById(targetId) : this.closest('.prefa-detail-row');
    if (details) {
        details.hidden = true;
        const toggleBtn = document.querySelector(`.prefa-toggle[aria-controls="${details.id}"]`);
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }
});

// ===== Certificats matière (CCPU) =====
// Un certificat est lu à la première ouverture de la fiche qui le montre, pas à
// l'enregistrement de la demande : un scan demande plusieurs secondes par page.
function lireCcpu(bloc, csrf) {
    if (bloc.dataset.ccpuEnCours) return;
    bloc.dataset.ccpuEnCours = '1';
    const donnees = new URLSearchParams({ csrf: csrf, document: bloc.dataset.ccpuLire });
    $.ajax({ url: 'actions/lire_ccpu.php', method: 'POST', data: donnees.toString(), dataType: 'json' })
        .done(reponse => $(bloc).replaceWith(reponse.html))
        .fail(xhr => {
            // Le bloc reste en place avec le motif et de quoi relancer : une lecture
            // ratée ne doit pas tourner en boucle à chaque rafraîchissement de la liste.
            const relance = $('<button type="button" class="ccpu-relire">').text('Réessayer')
                .attr({ 'data-ccpu-document': bloc.dataset.ccpuLire, 'data-csrf': csrf });
            $(bloc).removeClass('is-en-attente').addClass('is-echec').removeAttr('data-ccpu-lire')
                .empty().append($('<p>').text(xhr.responseJSON?.message || 'Lecture impossible. Réessayez.'), relance);
        });
}

// Les fiches s'ouvrent par clic, mais sont aussi rouvertes après un rechargement de la
// liste : on regarde donc dans les deux cas quels certificats visibles restent à lire.
function lireCcpuVisibles() {
    document.querySelectorAll('.prefa-detail-row:not([hidden]) [data-ccpu-lire]').forEach(bloc => lireCcpu(bloc, bloc.dataset.csrf));
}
$(document).on('click', '.prefa-toggle[aria-controls]', () => setTimeout(lireCcpuVisibles, 0));
$(document).on('ajaxComplete', lireCcpuVisibles);

$(document).on('click', '.ccpu-relire', function () {
    const bloc = $('<div class="ccpu-lecture is-en-attente" role="status">').text('Lecture des coulées…')
        .attr('data-ccpu-lire', this.dataset.ccpuDocument)[0];
    $(this).closest('.ccpu-lecture').replaceWith(bloc);
    lireCcpu(bloc, this.dataset.csrf);
});

// Referme le volet ouvert et rend son bouton à l'état replié. Renvoie false s'il n'y
// en avait aucun.
function fermerVoletOuvert() {
    const openDetail = document.querySelector('.prefa-detail-row:not([hidden])');
    if (!openDetail) return false;
    openDetail.hidden = true;
    const toggleBtn = document.querySelector(`.prefa-toggle[aria-controls="${openDetail.id}"]`);
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    return true;
}

$(document).on('keydown', function (event) {
    if (event.key === 'Escape') {
        // Échap ferme d'abord la fenêtre posée par-dessus le volet. Sans ce garde-fou,
        // la même touche refermait la fenêtre ET le volet qui l'a ouverte.
        if (document.querySelector('dialog[open]')) return;
        fermerVoletOuvert();
    }
});

// Le voile est dessiné par body::before : un clic dessus a donc body pour cible.
// Comme il couvre toute la page quand un volet est ouvert, c'est le geste « cliquer
// à côté pour fermer » des fenêtres modales.
$(document).on('click', function (event) {
    if (event.target === document.body) fermerVoletOuvert();
});

$(document).on('click', '.btn-goto-plans-iso', function () {
    const reqId = $(this).data('request-id');
    $('#btn-plans-iso').trigger('click');
    if (!reqId) return;
    const checkCard = setInterval(function () {
        const card = document.getElementById('plans-iso-card-' + reqId);
        if (card) {
            clearInterval(checkCard);
            const button = card.querySelector('.plans-iso-toggle');
            if (button && button.getAttribute('aria-expanded') !== 'true') button.click();
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 50);
    setTimeout(function () { clearInterval(checkCard); }, 2000);
});

$(document).on('submit', '#prefa-form, .prefa-review', function (event) {
    event.preventDefault();
    const form = $(this);
    if (form.data('submitting')) return;
    const review = form.hasClass('prefa-review');
    const status = event.originalEvent?.submitter?.value;
    const message = form.find('.prefa-message');
    message.removeClass('success error');
    if (review && !['2', '3'].includes(status)) {
        message.addClass('error').text('Choisissez Valider ou Refuser.');
        return;
    }
    if (review && status === '3' && !form.find('[name="commentaire"]').val().trim()) {
        message.addClass('error').text('Indiquez le motif du refus.');
        form.find('[name="commentaire"]').trigger('focus');
        return;
    }
    if (!review) {
        if (!form.find('[name="id"]').length) {
            const planInput = form.find('#plan-documents')[0];
            const planFiles = planInput?.files?.length || 0;
            const savedPlans = form.find('#plan-saved-files .prefa-file-row').length;
            if (planFiles === 0 && savedPlans === 0) {
                message.addClass('error').text('Ajoutez au moins un document du plan BPE / ISO.');
                form.find('label.prefa-dropzone[for="plan-documents"]').trigger('focus');
                return;
            }
        }
        const invalidField = form.find(':input[required]:visible').filter(function () { return !this.checkValidity(); }).first();
        if (invalidField.length) {
            message.addClass('error').text('Veuillez remplir les champs obligatoires.');
            invalidField.trigger('focus');
            return;
        }
    }
    const data = new FormData(form[0]);
    if (review) data.append('statut', status);
    form.data('submitting', true).find('button').prop('disabled', true);
    message.text('Enregistrement…');
    $.ajax({
        url: review ? 'actions/review_prefa.php' : 'actions/create_prefa.php',
        method: 'POST', data: data, dataType: 'json', processData: false, contentType: false,
        success: function (response) {
            message.addClass('success').text(response.message);
            if (review) {
                const request = form.closest('.prefa-request');
                if (request.hasClass('is-urgent-pending')) {
                    request.removeClass('is-urgent-pending');
                    const counter = $('#urgent-pending-count');
                    updateUrgentBadge(Math.max(0, Number(counter.text()) - 1));
                }
                form.closest('.prefa-request').find('.prefa-status')
                    .removeClass('status-1').addClass('status-' + status)
                    .text(status === '2' ? 'Validée' : 'Refusée');
                form.data('reviewed', true).find('textarea').prop('disabled', true);
                setTimeout(refreshPrefaList, 700);
            } else if (form.find('[name="id"]').length) {
                // Garder les valeurs enregistrées et actualiser le compteur administrateur.
                $.get('pages/lists_prefa.php', function (html) {
                    const total = $('<div>').html($.parseHTML(html)).find('#prefa-urgent-total');
                    if (total.length) updateUrgentBadge(Number(total.attr('data-count')));
                });
                const id = form.find('[name="id"]').val();
                $.get('pages/create_prefa.php?id=' + encodeURIComponent(id), function (html) {
                    if (!document.contains(form[0])) return;
                    const refreshed = $('<div>').html($.parseHTML(html)).find('#prefa-form');
                    if (refreshed.length) {
                        form.replaceWith(refreshed);
                        refreshed.find('.prefa-message').addClass('success').text(response.message);
                    }
                });
            } else {
                form[0].reset();
                form.find('.prefa-file-list').empty();
                form.find('[name="CDN"], [name="RT"], [name="PT"]').trigger('change');
                form.find('#revetement-type').trigger('change');
                form.find('#controles, #controles-rt, #controles-pt').trigger('input');
                form.find('#plan-documents').trigger('change');
                form.find('#qmos').trigger('change');
                form.find('#dmos').trigger('change');
                form.find('#materiel').trigger('change');
                form.find('#ccpu').trigger('change');
                if (typeof selectPassivation === 'function') {
                    selectPassivation('', '');
                }
                setTimeout(function () {
                    if ($('#btn-list-prefa').length) {
                        $('#btn-list-prefa').trigger('click');
                    }
                }, 1200);
            }
        },
        error: function (xhr) {
            message.addClass('error').text(xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.');
        },
        complete: function () {
            form.data('submitting', false).find('button').prop('disabled', !!form.data('reviewed'));
        }
    });
});


$(document).on('change', '#prefa-form [name="RT"]', function () {
    const form = $(this).closest('form');
    form.find('#rt-details').prop('hidden', !this.checked);
    form.find('[name="controles_rt"], #controles-rt-value').prop('disabled', !this.checked).prop('required', this.checked);
});

$(document).on('input', '#controles-rt', function () {
    $('#controles-rt-value').val(this.value).prop('required', true).get(0).setCustomValidity('');
});
$(document).on('input change', '#controles-rt-value', function (event) {
    const rawValue = this.value.trim();
    const normalizedValue = rawValue.replace(',', '.');
    const validFormat = /^\d*(?:\.\d*)?$/.test(normalizedValue);
    const value = Number(normalizedValue);
    const validValue = validFormat && normalizedValue !== '' && Number.isFinite(value) && value >= 1 && value <= 100;
    if (event.type === 'change' && rawValue !== normalizedValue) this.value = normalizedValue;
    this.setCustomValidity(validValue || rawValue === '' ? '' : 'Saisissez une valeur entre 1 et 100.');
    if (validValue) $('#controles-rt').val(normalizedValue);
});

$(document).on('change', '#prefa-form [name="PT"]', function () {
    const form = $(this).closest('form');
    form.find('#pt-details').prop('hidden', !this.checked);
    form.find('[name="controles_pt"], #controles-pt-value').prop('disabled', !this.checked).prop('required', this.checked);
});

$(document).on('input', '#controles-pt', function () {
    $('#controles-pt-value').val(this.value).prop('required', true).get(0).setCustomValidity('');
});
$(document).on('input change', '#controles-pt-value', function (event) {
    const rawValue = this.value.trim();
    const normalizedValue = rawValue.replace(',', '.');
    const validFormat = /^\d*(?:\.\d*)?$/.test(normalizedValue);
    const value = Number(normalizedValue);
    const validValue = validFormat && normalizedValue !== '' && Number.isFinite(value) && value >= 1 && value <= 100;
    if (event.type === 'change' && rawValue !== normalizedValue) this.value = normalizedValue;
    this.setCustomValidity(validValue || rawValue === '' ? '' : 'Saisissez une valeur entre 1 et 100.');
    if (validValue) $('#controles-pt').val(normalizedValue);
});


