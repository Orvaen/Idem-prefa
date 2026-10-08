
function syncMatiereAvailability(focus) {
    var material = document.querySelector('[name="id_matiere"]');
    var availability = document.getElementById('matiere-disponibilite');
    var marker = document.getElementById('matiere-disponibilite-required');
    var required = Boolean(material && material.value);
    var group = document.getElementById('matiere-disponibilite-field');
    if (group) group.hidden = !required;
    if (marker) marker.hidden = !required;
    if (!availability) return;
    availability.required = required;
    if (!required) availability.value = '';
    if (focus && required) availability.focus();
}
function openMatiere() {
    var options = document.getElementById('matiere-options');
    var input = document.getElementById('matiere-search');
    if (!options || !input) return;
    options.hidden = false;
    options.style.display = 'block';
    input.setAttribute('aria-expanded', 'true');
    filterMatiere(input.value);
}

function closeMatiere() {
    var options = document.getElementById('matiere-options');
    var input = document.getElementById('matiere-search');
    if (!options) return;
    options.hidden = true;
    options.style.display = 'none';
    if (input) input.setAttribute('aria-expanded', 'false');
}

function filterMatiere(val) {
    var options = document.getElementById('matiere-options');
    if (!options) return;
    var query = (val || '').trim().toLowerCase();
    if (query === 'autre') query = '';
    var items = options.querySelectorAll('[role="option"]:not(#matiere-option-autre)');
    var visibleCount = 0;
    items.forEach(function (item) {
        var text = (item.textContent || '').trim().toLowerCase();
        var match = query === '' || text.indexOf(query) !== -1;
        item.hidden = !match;
        item.style.display = match ? 'block' : 'none';
        if (match) visibleCount++;
    });

    var noResults = options.querySelector('.matiere-no-results');
    if (noResults) {
        noResults.hidden = visibleCount > 0;
        noResults.style.display = visibleCount > 0 ? 'none' : 'block';
    }

    var autreOpt = document.getElementById('matiere-option-autre');
    if (autreOpt) {
        autreOpt.hidden = false;
        autreOpt.style.display = 'block';
    }
}

function selectMatiere(val, text) {
    var input = document.getElementById('matiere-search');
    var hiddenInput = document.querySelector('[name="id_matiere"]');
    var autreField = document.getElementById('matiere-autre-field');
    var autreInput = document.getElementById('matiere-autre');

    if (val === 'autre') {
        if (input) input.value = 'Autre';
        if (hiddenInput) hiddenInput.value = 'autre';
        if (autreField) {
            autreField.hidden = false;
            autreField.style.display = 'flex';
        }
        if (autreInput) {
            autreInput.required = true;
            var lastQuery = input ? input.dataset.lastQuery : '';
            if (lastQuery && lastQuery.toLowerCase() !== 'autre') {
                autreInput.value = lastQuery;
            }
            setTimeout(function () { autreInput.focus(); }, 50);
        }
    } else {
        if (input) input.value = val ? text : '';
        if (hiddenInput) hiddenInput.value = val || '';
        if (autreField) {
            autreField.hidden = true;
            autreField.style.display = 'none';
        }
        if (autreInput) {
            autreInput.required = false;
            autreInput.value = '';
        }
    }

    var allOptions = document.querySelectorAll('#matiere-options [role="option"]');
    allOptions.forEach(function (opt) {
        opt.setAttribute('aria-selected', opt.dataset.value === val ? 'true' : 'false');
    });

    closeMatiere();
    syncMatiereAvailability(val !== 'autre');
}

$(document).off('.matiere')
    .on('focus.matiere click.matiere', '#matiere-search', function () {
        openMatiere();
    })
    .on('input.matiere', '#matiere-search', function () {
        var raw = this.value.trim();
        if (raw && raw.toLowerCase() !== 'autre') {
            this.dataset.lastQuery = raw;
        }
        var form = $(this).closest('form');
        var curVal = form.find('[name="id_matiere"]').val();
        if (curVal !== 'autre' || raw.toLowerCase() !== 'autre') {
            form.find('[name="id_matiere"]').val('');
            syncMatiereAvailability(false);
            var f = document.getElementById('matiere-autre-field');
            if (f) { f.hidden = true; f.style.display = 'none'; }
            var inp = document.getElementById('matiere-autre');
            if (inp) { inp.required = false; }
        }
        openMatiere();
    })
    .on('click.matiere', '#matiere-options [role="option"]', function (e) {
        e.stopPropagation();
        selectMatiere(this.dataset.value, $(this).text().trim());
    })
    .on('click.matiere', '#matiere-clear', function (e) {
        e.stopPropagation();
        selectMatiere('', '');
        var input = document.getElementById('matiere-search');
        if (input) {
            delete input.dataset.lastQuery;
            input.focus();
        }
    })
    .on('change.matiere', '#matiere-autre', function () {
        if (this.value.trim()) syncMatiereAvailability(true);
    })
    .on('keydown.matiere', '#matiere-search', function (e) {
        var options = $('#matiere-options [role="option"]:visible');
        var selected = options.filter('[aria-selected="true"]');
        if (e.key === 'Escape' || e.key === 'Tab') {
            closeMatiere();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (selected.length) {
                selected.trigger('click');
            } else if (options.length === 1) {
                options.trigger('click');
            } else {
                $('#matiere-option-autre').trigger('click');
            }
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            openMatiere();
            if (!options.length) return;
            var idx = options.index(selected);
            idx = e.key === 'ArrowDown' ? (idx + 1) % options.length : (idx <= 0 ? options.length - 1 : idx - 1);
            options.attr('aria-selected', 'false');
            var opt = options.eq(idx).attr('aria-selected', 'true');
            if (opt[0]) opt[0].scrollIntoView({ block: 'nearest' });
        }
    })
    .on('click.matiere', function (e) {
        if (!$(e.target).closest('.matiere-select').length) {
            closeMatiere();
        }
    });




function openPassivation() {
    var options = document.getElementById('passivation-options');
    var input = document.getElementById('passivation-search');
    if (!options || !input) return;
    options.hidden = false;
    options.style.display = 'block';
    input.setAttribute('aria-expanded', 'true');
    filterPassivation(input.value);
}

function closePassivation() {
    var options = document.getElementById('passivation-options');
    var input = document.getElementById('passivation-search');
    if (!options) return;
    options.hidden = true;
    options.style.display = 'none';
    if (input) input.setAttribute('aria-expanded', 'false');
}

function filterPassivation(val) {
    var options = document.getElementById('passivation-options');
    if (!options) return;
    var query = (val || '').trim().toLowerCase();
    if (query === 'autre') query = '';
    var items = options.querySelectorAll('[role="option"]:not(#passivation-option-autre)');
    var visibleCount = 0;
    items.forEach(function (item) {
        var text = (item.textContent || '').trim().toLowerCase();
        var match = query === '' || text.indexOf(query) !== -1;
        item.hidden = !match;
        item.style.display = match ? 'block' : 'none';
        if (match) visibleCount++;
    });

    var noResults = options.querySelector('.passivation-no-results');
    if (noResults) {
        noResults.hidden = visibleCount > 0;
        noResults.style.display = visibleCount > 0 ? 'none' : 'block';
    }

    var autreOpt = document.getElementById('passivation-option-autre');
    if (autreOpt) {
        autreOpt.hidden = false;
        autreOpt.style.display = 'block';
    }
}

function selectPassivation(val, text) {
    var input = document.getElementById('passivation-search');
    var hiddenInput = document.querySelector('[name="id_passivation"]');
    var autreField = document.getElementById('passivation-autre-field');
    var autreInput = document.getElementById('passivation-autre');

    if (val === 'autre') {
        if (input) input.value = 'Autre';
        if (hiddenInput) hiddenInput.value = 'autre';
        if (autreField) {
            autreField.hidden = false;
            autreField.style.display = 'flex';
        }
        if (autreInput) {
            autreInput.required = true;
            var lastQuery = input ? input.dataset.lastQuery : '';
            if (lastQuery && lastQuery.toLowerCase() !== 'autre') {
                autreInput.value = lastQuery;
            }
            setTimeout(function () { autreInput.focus(); }, 50);
        }
    } else {
        if (input) input.value = val ? text : '';
        if (hiddenInput) hiddenInput.value = val || '';
        if (autreField) {
            autreField.hidden = true;
            autreField.style.display = 'none';
        }
        if (autreInput) {
            autreInput.required = false;
            autreInput.value = '';
        }
    }

    var allOptions = document.querySelectorAll('#passivation-options [role="option"]');
    allOptions.forEach(function (opt) {
        opt.setAttribute('aria-selected', opt.dataset.value === val ? 'true' : 'false');
    });

    closePassivation();
}

$(document)
    .on('focus click', '#passivation-search', function () {
        openPassivation();
    })
    .on('input', '#passivation-search', function () {
        var raw = this.value.trim();
        if (raw && raw.toLowerCase() !== 'autre') {
            this.dataset.lastQuery = raw;
        }
        var form = $(this).closest('form');
        var curVal = form.find('[name="id_passivation"]').val();
        if (curVal !== 'autre' || raw.toLowerCase() !== 'autre') {
            form.find('[name="id_passivation"]').val('');
            var f = document.getElementById('passivation-autre-field');
            if (f) { f.hidden = true; f.style.display = 'none'; }
            var inp = document.getElementById('passivation-autre');
            if (inp) { inp.required = false; }
        }
        openPassivation();
    })
    .on('click', '#passivation-options [role="option"]', function (e) {
        e.stopPropagation();
        selectPassivation(this.dataset.value, $(this).text().trim());
    })
    .on('click', '#passivation-clear', function (e) {
        e.stopPropagation();
        selectPassivation('', '');
        var input = document.getElementById('passivation-search');
        if (input) {
            delete input.dataset.lastQuery;
            input.focus();
        }
    })
    .on('keydown', '#passivation-search', function (e) {
        var options = $('#passivation-options [role="option"]:visible');
        var selected = options.filter('[aria-selected="true"]');
        if (e.key === 'Escape' || e.key === 'Tab') {
            closePassivation();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (selected.length) {
                selected.trigger('click');
            } else if (options.length === 1) {
                options.trigger('click');
            } else {
                $('#passivation-option-autre').trigger('click');
            }
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            openPassivation();
            if (!options.length) return;
            var idx = options.index(selected);
            idx = e.key === 'ArrowDown' ? (idx + 1) % options.length : (idx <= 0 ? options.length - 1 : index - 1);
            options.attr('aria-selected', 'false');
            var opt = options.eq(idx).attr('aria-selected', 'true');
            if (opt[0]) opt[0].scrollIntoView({ block: 'nearest' });
        }
    })
    .on('click', function (e) {
        if (!$(e.target).closest('.passivation-select').length) {
            closePassivation();
        }
    });




$(document).on('change', '#prefa-form [name="CDN"]', function () {
    const form = $(this).closest('form');
    form.find('#cdn-details').prop('hidden', !this.checked);
    form.find('[name="controles_cdn"], #controles-value').prop('disabled', !this.checked).prop('required', this.checked);
});

$(document).on('change', '#prefa-form #revetement-type', function () {
    if (typeof toggleRevetementDetails === 'function') {
        toggleRevetementDetails(this);
    } else {
        const isCoated = this.value !== 'aucun';
        const details = $('#revetement-details');
        details.prop('hidden', !isCoated).css('display', isCoated ? 'flex' : 'none');
        if (!isCoated) details.find('#revetement-precision').val('');
    }
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
$(document).on('change', '#prefa-filters select', refreshPrefaList);
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


