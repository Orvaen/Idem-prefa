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
// Le même délai pour la frappe et pour les filtres : cocher trois cases d'affilée ne doit
// pas partir en trois allers-retours, pas plus que taper trois lettres. « Tout
// sélectionner » bascule d'ailleurs toutes les cases d'un coup — sans ce délai, il partait
// autant de fois qu'il y a d'options.
const PREFA_DELAI_FILTRE = 350;

function differerRafraichissement() {
    clearTimeout(prefaFilterTimer);
    prefaFilterTimer = setTimeout(refreshPrefaList, PREFA_DELAI_FILTRE);
}

$(document).on('input', '#prefa-search', differerRafraichissement);
// Le formulaire émet « change » quand la barre de filtres bouge — c'est elle qui prévient,
// elle n'appelle pas le rechargement : c'est l'écran qui sait comment se recharger.
$(document).on('change', '#prefa-filters', differerRafraichissement);
$(document).on('click', '#refresh-prefa', refreshPrefaList);

// ===== Les onglets de statut =====
// L'onglet n'est qu'une autre manière de remplir le champ « status » du formulaire : le
// rafraîchissement sérialise le formulaire, il n'a rien d'autre à savoir. Un ancien lien
// pouvait retenir plusieurs statuts ; choisir un onglet n'en garde qu'un.
$(document).on('click', '.prefa-onglet', function () {
    const form = document.getElementById('prefa-filters');
    if (!form) return;
    const champs = form.querySelectorAll('.prefa-statut-choisi');
    champs.forEach((champ, rang) => { if (rang > 0) champ.remove(); });
    if (champs[0]) champs[0].value = this.dataset.statut;
    document.querySelectorAll('.prefa-onglet').forEach(onglet => {
        onglet.setAttribute('aria-pressed', String(onglet === this));
    });
    clearTimeout(prefaFilterTimer);
    refreshPrefaList();
});

// ===== Valider et Refuser depuis la ligne =====
// Les deux passent par le formulaire d'examen du volet : une seule voie vers
// review_prefa.php, avec ses contrôles et son jeton.
function formulaireExamen(bouton) {
    const volet = document.getElementById(bouton.dataset.detail);
    return volet ? volet.querySelector('form.prefa-review') : null;
}

// Valider n'a besoin de rien d'autre : le formulaire part tel quel, comme si l'on avait
// cliqué son propre bouton — « submitter » porte la valeur 2 jusqu'au gestionnaire.
$(document).on('click', '.prefa-ligne-valider', function () {
    const form = formulaireExamen(this);
    const valider = form?.querySelector('button[value="2"]');
    if (!valider) return;
    this.closest('.prefa-decision').querySelectorAll('button').forEach(b => { b.disabled = true; });
    form.requestSubmit(valider);
});

// Refuser demande un motif : on ouvre le volet sur son champ plutôt que de refuser à l'aveugle.
$(document).on('click', '.prefa-ligne-refuser', function () {
    const form = formulaireExamen(this);
    if (!form) return;
    const volet = form.closest('.prefa-detail-row');
    if (volet.hidden) {
        document.querySelector(`.prefa-toggle[aria-controls="${volet.id}"]`)?.click();
    }
    form.querySelector('.prefa-message').textContent = 'Indiquez le motif, puis cliquez sur Refuser.';
    const motif = form.querySelector('[name="commentaire"]');
    motif.focus();
    motif.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});
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
/**
 * Quitter le formulaire de demande : par le bouton du haut, par « Annuler », ou par Échap.
 *
 * Une saisie en cours ne se jette pas sans un mot : le formulaire porte une marque dès
 * qu'un champ bouge, et c'est elle qui décide s'il faut demander confirmation.
 */
function quitterFormulairePrefa() {
    const formulaire = document.getElementById('prefa-form');
    if (formulaire?.dataset.modifie === '1'
        && !window.confirm('Quitter sans enregistrer ? Les modifications seront perdues.')) return;
    $('#btn-list-prefa').trigger('click');
}

$(document).on('click', '.cancel-prefa-edit, .prefa-retour', quitterFormulairePrefa);
$(document).on('input change', '#prefa-form', function () { this.dataset.modifie = '1'; });

// Échap quitte le formulaire — mais seulement s'il n'y a rien d'autre à refermer d'abord.
// Les fenêtres, les volets de filtres et les listes déroulantes se ferment eux aussi sur
// Échap, sans arrêter l'événement : c'est donc à nous de leur laisser la main.
const OUVERT_AVANT_LE_FORMULAIRE =
    'dialog[open], .filtre-volet:not([hidden]), .liste-filtrable-champ[aria-expanded="true"]';

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    if (!document.getElementById('prefa-form')) return;
    if (document.querySelector(OUVERT_AVANT_LE_FORMULAIRE)) return;
    quitterFormulairePrefa();
});

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

// ===== Dépôt d'un document depuis la fiche d'une demande =====
// Déposer par « Modifier » renvoie la demande en attente de validation : c'est voulu pour
// une date ou un métrage, pas pour un certificat qui arrive après coup. Le dépôt a donc
// son action à lui, et il se fait sans quitter la fiche.
$(document).on('change', '.prefa-depot-champ', function () {
    const champ = this;
    const fichiers = [...champ.files];
    if (!fichiers.length) return;

    const zone = champ.closest('.prefa-document-group');
    const mot = zone.querySelector('.prefa-depot-mot');
    const bouton = zone.querySelector('.prefa-depot-bouton');
    champ.disabled = true;
    bouton.classList.add('est-occupe');
    mot.textContent = fichiers.length > 1 ? 'Dépôt de ' + fichiers.length + ' fichiers…' : 'Dépôt…';

    const donnees = new FormData();
    donnees.set('csrf', champ.dataset.csrf);
    donnees.set('demande', champ.dataset.demande);
    donnees.set('type', champ.dataset.type);
    fichiers.forEach(fichier => donnees.append('documents[]', fichier));

    fetch('actions/deposer_document.php', {method: 'POST', body: donnees})
        .then(reponse => reponse.json().then(corps => ({ok: reponse.ok, corps})))
        .then(({ok, corps}) => {
            if (!ok) { mot.textContent = corps.message || 'Dépôt impossible.'; return; }

            // La liste est toujours là, même vide : on l'affiche et on range le « Aucun
            // document » plutôt que de reconstruire le bloc.
            const liste = zone.querySelector('.prefa-document-list');
            liste.insertAdjacentHTML('beforeend', corps.html);
            liste.hidden = false;
            // Une famille repliée s'ouvre : déposer sans rien voir arriver laisse croire
            // que le dépôt a échoué.
            zone.open = true;
            zone.querySelector('.prefa-no-document').hidden = true;
            zone.querySelector('.prefa-document-compte').textContent =
                liste.querySelectorAll(':scope > li').length;
            mot.textContent = corps.message;

            // Un certificat déposé se lit tout seul, comme à l'ouverture de la fiche.
            if (typeof lireCcpuVisibles === 'function') lireCcpuVisibles();
        })
        .catch(() => { mot.textContent = 'Dépôt impossible. Réessayez.'; })
        .finally(() => {
            champ.value = '';
            champ.disabled = false;
            bouton.classList.remove('est-occupe');
        });
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

// ===================================================================== CHAMPS OBLIGATOIRES
//
// Le formulaire de demande tient sur deux écrans. Un champ oublié tout en haut ne se
// retrouvait pas : le message disait « remplissez les champs obligatoires » sans dire
// lequel, et le focus seul ne ramène pas l'œil quand la page a défilé. On nomme donc le
// champ, on y amène, et on le colore — rouge tant qu'il manque, vert dès qu'il est bon.
//
// La couleur n'apparaît qu'après un premier envoi refusé. Avant, un formulaire vierge
// s'allumerait en rouge avant qu'on ait tapé quoi que ce soit, et un formulaire modifié
// virerait au vert partout : dans les deux cas la couleur ne dirait plus rien.

/** Le nom du champ tel que son intitulé l'écrit, sans l'astérisque. */
function nomDuChamp(champ) {
    // Une zone de dépôt n'a pas d'intitulé à elle : c'est l'étiquette de son groupe qui
    // nomme ce qu'on attend — « Documents QMOS », « Plans BPE / ISO ». Elle portait un nom
    // écrit en dur, du temps où un seul dépôt était obligatoire.
    const groupe = champ.closest('.form-group, .liste-filtrable, .prefa-dropzone-groupe, .nd-complement');
    let intitule = groupe && groupe.querySelector('label:not(.prefa-dropzone)');
    // Une ligne de dépôt des documents complémentaires n'a pas d'étiquette du tout : son
    // titre est dans la ligne même. Sans ce regard, un QMOS oublié s'annonçait « un champ
    // obligatoire » alors que le groupe en compte quatre.
    if (!intitule && groupe) intitule = groupe.querySelector('.nd-ligne-titre');
    // Une liste filtrable n'a pas d'étiquette à elle : elle est posée dans le groupe qui
    // la porte. Sans ce second regard, « Affaire » s'annonçait « un champ obligatoire ».
    if (!intitule && groupe && groupe.parentElement) {
        const parent = groupe.parentElement.closest('.form-group');
        intitule = parent && parent.querySelector('label:not(.prefa-dropzone)');
    }
    const texte = intitule ? intitule.textContent.replace('*', '').trim() : '';
    return texte || champ.name || 'un champ obligatoire';
}

/** Pose la couleur sur un champ, selon qu'il est rempli ou non. */
function colorerChamp(champ) {
    const bon = champ.checkValidity();
    champ.classList.toggle('champ-invalide', !bon);
    champ.classList.toggle('champ-valide', bon);
}

/**
 * Colore tous les champs obligatoires visibles et rend ceux qui manquent.
 *
 * @return {HTMLElement[]}
 */
function marquerChampsObligatoires(form) {
    form.data('verifie', true);
    const manquants = [];
    form.find(':input[required]:visible').each(function () {
        colorerChamp(this);
        if (!this.checkValidity()) manquants.push(this);
    });
    return manquants;
}

/**
 * Colore les listes filtrables obligatoires et rend celles qu'on n'a pas renseignées.
 *
 * Leur valeur est dans un champ caché : « required » ne la couvre pas, et le champ qu'on
 * colore — celui où l'on tape — n'est pas celui qui porte la réponse.
 *
 * @return {HTMLElement[]}
 */
function marquerListesObligatoires(form) {
    const manquantes = [];
    form.find('.liste-filtrable[data-obligatoire="1"]').each(function () {
        const cache = this.querySelector('input[type="hidden"]');
        const saisie = this.querySelector('.liste-filtrable-champ');
        if (!saisie) return;
        const vide = !cache || cache.value.trim() === '';
        saisie.classList.toggle('champ-invalide', vide);
        saisie.classList.toggle('champ-valide', !vide);
        if (vide) manquantes.push(saisie);
    });
    return manquantes;
}

/** Du haut de la page vers le bas : c'est l'ordre dans lequel on remplit un formulaire. */
function ordreDePage(a, b) {
    return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
}

/**
 * Amène le champ sous les yeux, puis lui donne le focus.
 *
 * Le focus seul suffirait à faire défiler, mais d'un coup sec et en bord d'écran : on
 * place le champ au milieu, et on empêche le focus de refaire le voyage.
 */
function amenerAuChamp(champ) {
    champ.scrollIntoView({behavior: 'smooth', block: 'center'});
    try {
        champ.focus({preventScroll: true});
    } catch (erreur) {
        champ.focus();
    }
}

// Une fois le formulaire refusé, chaque frappe remet le champ à jour : c'est ce qui fait
// passer au vert sans attendre un second envoi.
$(document).on('input change blur', '#prefa-form :input[required]', function () {
    const form = $(this).closest('form');
    if (!form.data('verifie')) return;
    colorerChamp(this);
});

// Choisir dans une liste filtrable la fait changer d'avis : c'est son champ caché qui
// annonce le choix, pas celui où l'on tape.
$(document).on('change', '#prefa-form .liste-filtrable[data-obligatoire="1"] input[type="hidden"]', function () {
    const form = $(this).closest('form');
    if (!form.data('verifie')) return;
    marquerListesObligatoires(form);
});

// Un dépôt efface son propre reproche.
$(document).on('change', '#prefa-form #plan-documents, #prefa-form #qmos, #prefa-form #dmos', function () {
    if (this.files && this.files.length) {
        $(this).closest('form').find('label.prefa-dropzone[for="' + this.id + '"]').removeClass('champ-invalide');
    }
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
        // Tout ce qui manque est relevé d'un coup. En s'arrêtant au premier défaut, on
        // renvoyait la personne au formulaire autant de fois qu'il manquait de champs,
        // sans jamais lui dire combien il en restait.
        const manquants = [];

        // Les dépôts obligatoires. Le plan ne l'est qu'à la création — on ne le redépose
        // pas pour corriger une date —, tandis que le QMOS et le DMOS le sont toujours :
        // on ne soude pas sans le mode opératoire ni sa qualification, et les retirer
        // après coup reviendrait à s'en passer.
        //
        // Un document retiré ne compte plus : sa ligne reste à l'écran, barrée, le temps
        // qu'on se ravise, et c'est la croix qui dit s'il part ou non.
        const creation = !form.find('[name="id"]').length;
        [
            { champ: 'plan-documents', gardes: '#plan-saved-files', aLaCreation: true },
            { champ: 'qmos', gardes: '#qmos-saved-files' },
            { champ: 'dmos', gardes: '#dmos-saved-files' },
        ].forEach(depot => {
            if (depot.aLaCreation && !creation) return;
            const deposes = form.find('#' + depot.champ)[0]?.files?.length || 0;
            const restants = form.find(depot.gardes + ' .prefa-file-row').not('.is-removed').length;
            const zone = form.find('label.prefa-dropzone[for="' + depot.champ + '"]');
            const manque = deposes === 0 && restants === 0;
            zone.toggleClass('champ-invalide', manque);
            if (manque && zone.length) manquants.push(zone[0]);
        });
        marquerChampsObligatoires(form).forEach(champ => manquants.push(champ));
        marquerListesObligatoires(form).forEach(champ => manquants.push(champ));

        if (manquants.length) {
            manquants.sort(ordreDePage);
            const nom = nomDuChamp(manquants[0]);
            message.addClass('error').text(manquants.length === 1
                ? 'Il manque : ' + nom + '.'
                : 'Il manque ' + manquants.length + ' éléments, à commencer par : ' + nom + '.');
            amenerAuChamp(manquants[0]);
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
            // Validée depuis la ligne, le volet est resté fermé : sa réponse ne se verrait pas.
            if (review && form.closest('.prefa-detail-row').prop('hidden') && window.toast) {
                window.toast(response.message);
            }
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
            const texte = xhr.responseJSON?.message || 'Enregistrement impossible. Réessayez.';
            message.addClass('error').text(texte);
            if (review && form.closest('.prefa-detail-row').prop('hidden') && window.toast) {
                window.toast(texte, { erreur: true });
            }
        },
        complete: function () {
            form.data('submitting', false).find('button').prop('disabled', !!form.data('reviewed'));
            if (review && !form.data('reviewed')) {
                form.closest('.prefa-request').find('.prefa-decision button').prop('disabled', false);
            }
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




/* ---------- Le menu d'actions d'une ligne ----------
 *
 * Le TableActionCell de Propel : un déclencheur unique par ligne, qui ouvre les commandes.
 * Elles étaient posées à plat dans la cellule et se répétaient sur chaque ligne.
 *
 * Les boutons gardent leurs classes : leurs gestionnaires sont branchés par délégation sur
 * le document, plus haut dans ce fichier, et continuent donc de répondre sans rien savoir
 * du menu qui les contient.
 *
 * Le menu est en position fixe, posée ici : la cellule vit dans un tableau qui défile, et
 * un menu en position absolue y serait découpé. C'est le parti déjà pris par le menu des
 * comptes, dans dashboard-accounts.js.
 */
(function () {
    const ECART = 6;   // sideOffset de Propel
    const MARGE = 16;  // collisionPadding

    function placer(declencheur, menu) {
        const repere = declencheur.getBoundingClientRect();
        const largeur = menu.offsetWidth;
        const hauteur = menu.offsetHeight;

        // Propel aligne un menu sur le bord gauche de son déclencheur. Ici la colonne des
        // actions est la dernière du tableau : aligné à gauche, le menu sortirait par la
        // droite. On l'aligne donc sur le bord droit — c'est la résolution de collision que
        // « collisionPadding » demande, et le seul alignement qui tienne contre le bord.
        let gauche = Math.min(repere.right - largeur, window.innerWidth - MARGE - largeur);
        let haut = repere.bottom + ECART;

        // Pas la place dessous : le menu passe au-dessus du déclencheur.
        if (haut + hauteur > window.innerHeight - MARGE) haut = repere.top - ECART - hauteur;

        menu.style.left = Math.max(MARGE, gauche) + 'px';
        menu.style.top = Math.max(MARGE, haut) + 'px';
    }

    function fermerTout(sauf) {
        document.querySelectorAll('.prefa-actions-menu:not([hidden])').forEach(menu => {
            if (menu === sauf) return;
            menu.hidden = true;
            menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
        });
    }

    function entrees(menu) {
        return Array.from(menu.querySelectorAll('button'));
    }

    $(document).on('click', '.prefa-actions-declencheur', function (evenement) {
        evenement.preventDefault();
        evenement.stopPropagation();
        const menu = this.nextElementSibling;
        if (!menu) return;
        const ouvrir = menu.hidden;
        fermerTout(menu);
        menu.hidden = !ouvrir;
        this.setAttribute('aria-expanded', String(ouvrir));
        if (ouvrir) placer(this, menu);
    });

    // Une commande choisie referme son menu. Le gestionnaire de l'action, délégué lui aussi,
    // s'exécute dans le même événement : masquer le bouton ne l'empêche pas de répondre.
    $(document).on('click', '.prefa-actions-menu button', function () {
        const menu = this.closest('.prefa-actions-menu');
        menu.hidden = true;
        menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('click', function (evenement) {
        if (!evenement.target.closest('.prefa-actions')) fermerTout(null);
    });

    document.addEventListener('keydown', function (evenement) {
        const ouvert = document.querySelector('.prefa-actions-menu:not([hidden])');

        if (evenement.key === 'Escape' && ouvert) {
            const declencheur = ouvert.previousElementSibling;
            fermerTout(null);
            declencheur?.focus();
            return;
        }

        // Flèche bas sur le déclencheur : le menu s'ouvre et la première commande prend le
        // focus, comme le fait celui de Propel.
        const declencheur = evenement.target.closest?.('.prefa-actions-declencheur');
        if (declencheur && (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp')) {
            evenement.preventDefault();
            const menu = declencheur.nextElementSibling;
            if (menu.hidden) declencheur.click();
            const liste = entrees(menu);
            (evenement.key === 'ArrowDown' ? liste[0] : liste[liste.length - 1])?.focus();
            return;
        }

        if (!ouvert || !ouvert.contains(evenement.target)) return;
        if (evenement.key !== 'ArrowDown' && evenement.key !== 'ArrowUp') return;
        evenement.preventDefault();
        const liste = entrees(ouvert);
        const depart = liste.indexOf(document.activeElement);
        const pas = evenement.key === 'ArrowDown' ? 1 : -1;
        liste[(depart + pas + liste.length) % liste.length]?.focus();
    });

    // Le menu est en position fixe : si le tableau défile sous lui, il se retrouve détaché
    // de sa ligne. Il la suit donc, et ne se referme que si son déclencheur quitte l'écran.
    //
    // Il se refermait d'abord à tout défilement. C'était trop brutal : l'écouteur est en
    // phase de capture, donc n'importe quel élément qui défile ailleurs dans la page — une
    // liste, un volet — faisait disparaître le menu sans que l'on ait rien demandé.
    window.addEventListener('scroll', function () {
        document.querySelectorAll('.prefa-actions-menu:not([hidden])').forEach(menu => {
            const declencheur = menu.previousElementSibling;
            const repere = declencheur.getBoundingClientRect();
            const dehors = repere.bottom < 0 || repere.top > window.innerHeight
                || repere.right < 0 || repere.left > window.innerWidth;
            if (dehors) fermerTout(null);
            else placer(declencheur, menu);
        });
    }, true);

    window.addEventListener('resize', () => fermerTout(null));
}());


/* ---------- Le formulaire s'ouvre quand l'affaire est choisie ----------
 *
 * Une demande se rattache à une affaire : avant ce choix, les plans, les documents, le
 * planning et les contrôles n'ont rien à quoi se rapporter. Le serveur pose le marqueur
 * « nd-attend-affaire » au premier rendu ; on le retire dès qu'une affaire est retenue, et
 * on le remet si on l'efface.
 *
 * C'est le champ caché qui annonce le choix, pas celui où l'on tape : on peut écrire trois
 * lettres d'un nom d'affaire sans en avoir retenu aucune.
 */
$(document).on('change', '#prefa-form [data-liste-filtrable="affaire"] input[type="hidden"]',
    function () {
        const form = $(this).closest('#prefa-form');
        const choisie = String(this.value || '').trim() !== '';
        form.toggleClass('nd-attend-affaire', !choisie);
    });

// « Haut de page » : une fois en bas du formulaire, rien ne ramenait à l'affaire ni aux
// plans. La zone qui défile est « .page » — elle porte l'overflow — et non la fenêtre :
// window.scrollTo() n'aurait rien bougé.
//
// Le bouton se tient sur le côté, mais il ne paraît qu'une fois qu'on a défilé : en haut
// de page, il proposait de remonter là où l'on était déjà.
//
// L'écoute se fait en phase de capture, parce que « scroll » ne remonte pas : l'écran
// arrive par AJAX, et un écouteur posé au chargement n'aurait trouvé personne.
document.addEventListener('scroll', function (evenement) {
    const page = evenement.target;
    if (!(page instanceof HTMLElement) || !page.classList.contains('page')) return;
    const bouton = page.querySelector('.nd-haut');
    // 80px, et non 240 : à 240 il fallait descendre bien trop bas pour le voir arriver.
    // Assez haut tout de même pour qu'un micro-défilement ne le fasse pas papilloter.
    if (bouton) bouton.hidden = page.scrollTop < 80;
}, true);

$(document).on('click', '.nd-haut', function () {
    const page = this.closest('.page');
    if (page) page.scrollTo({top: 0, behavior: 'smooth'});
});

// La densité du tableau : deux façons de lire la même liste. Ce n'est pas un filtre — rien
// n'entre ni ne sort — donc rien ne part au serveur : c'est une préférence de lecture, elle
// reste dans le navigateur de la personne.
//
// Le rendu pose déjà « data-densite="confortable" ». Le script ne corrige que si l'autre a
// été retenue, pour que la page ne se resserre pas sous les yeux après coup.
const PREFA_DENSITES = ['confortable', 'compact'];

function prefaDensiteRetenue() {
    try {
        const retenue = localStorage.getItem('prefa-densite');
        return PREFA_DENSITES.includes(retenue) ? retenue : 'confortable';
    } catch (erreur) {
        // Navigation privée, stockage refusé : le confortable, comme au premier jour.
        return 'confortable';
    }
}

function prefaPoserDensite(section, densite) {
    section.dataset.densite = densite;
    section.querySelectorAll('.prefa-densite-choix').forEach(choix => {
        choix.setAttribute('aria-pressed', String(choix.dataset.densite === densite));
    });
}

// L'écran arrive par AJAX : un parcours unique au chargement ne trouverait rien.
function prefaAppliquerDensite(racine) {
    if (!racine || racine.nodeType !== 1) return;
    const densite = prefaDensiteRetenue();
    if (racine.matches('.prefa-list')) prefaPoserDensite(racine, densite);
    racine.querySelectorAll('.prefa-list').forEach(s => prefaPoserDensite(s, densite));
}

$(document).on('click', '.prefa-densite-choix', function () {
    const densite = this.dataset.densite;
    if (!PREFA_DENSITES.includes(densite)) return;
    try {
        localStorage.setItem('prefa-densite', densite);
    } catch (erreur) {
        // Le choix vaudra pour cette page : on ne refuse pas le geste parce qu'on ne sait
        // pas le retenir.
    }
    document.querySelectorAll('.prefa-list').forEach(s => prefaPoserDensite(s, densite));
});

(() => {
    const demarrer = () => {
        prefaAppliquerDensite(document.body);
        new MutationObserver(mutations => {
            mutations.forEach(m => m.addedNodes.forEach(prefaAppliquerDensite));
        }).observe(document.body, {childList: true, subtree: true});
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
