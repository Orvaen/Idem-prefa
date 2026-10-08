(() => {
    let loading;
    function root() { return document.querySelector('.planning-page'); }
    function requests() {
        const data = root()?.querySelector('#planning-data');
        return data ? JSON.parse(data.textContent) : [];
    }
    function date(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
        const parsed = new Date(value + 'T12:00:00Z');
        return Number.isNaN(parsed.getTime()) || iso(parsed) !== value ? null : parsed;
    }
    function iso(value) { return value.toISOString().slice(0, 10); }
    function addDays(value, count) { const copy = new Date(value); copy.setUTCDate(copy.getUTCDate() + count); return copy; }
    const frenchDate = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });
    function displayDate(value) { return date(value) ? frenchDate.format(date(value)) : 'Non renseignée'; }

    function adjustToWorkingDay(d) {
        let cur = new Date(d);
        while (cur.getUTCDay() === 0 || cur.getUTCDay() === 6) {
            cur = addDays(cur, 1);
        }
        return cur;
    }

    function getHoursForRequest(req) {
        if (!req) return 24;
        const currentRoot = root();
        const hoursPerInch = parseFloat(currentRoot?.dataset?.hoursPerInch || '24') || 24;
        const inches = parseInt(req.pouces_total_iso || 0, 10);
        if (inches > 0) {
            return inches * hoursPerInch;
        }
        const h = parseFloat(req.heures_chiffrees || 0);
        return h > 0 ? h : 24;
    }

    function computeEndDate(startDateStr, hours) {
        if (!startDateStr) return '';
        const h = Number(hours);
        const days = (!h || h <= 0) ? 1 : Math.max(1, Math.ceil(h / 24));
        let cur = date(startDateStr);
        if (!cur) return startDateStr;
        while (cur.getUTCDay() === 0 || cur.getUTCDay() === 6) {
            cur = addDays(cur, 1);
        }
        let remaining = days - 1;
        while (remaining > 0) {
            cur = addDays(cur, 1);
            if (cur.getUTCDay() !== 0 && cur.getUTCDay() !== 6) {
                remaining--;
            }
        }
        return iso(cur);
    }

    function countWorkingDays(first, last) {
        let count = 0;
        for (let cur = new Date(first); cur <= last; cur = addDays(cur, 1)) {
            if (cur.getUTCDay() !== 0 && cur.getUTCDay() !== 6) count++;
        }
        return count;
    }

    // =========================================================================
    // SÉLECTION D'UNE AFFAIRE ET AFFICHAGE DU PANNEAU INFÉRIEUR (4 BLOCS)
    // =========================================================================

    // Liste tenue par atelierSteps() côté serveur et embarquée dans la page : la recopier
    // ici ferait dériver la matrice dès qu'une étape est ajoutée.
    function standardSteps() {
        const data = root()?.querySelector('#planning-steps');
        return data ? Object.entries(JSON.parse(data.textContent)) : [];
    }

    function selectAffaire(affaireId) {
        const idNum = Number(affaireId);
        if (!idNum) return;
        const reqList = requests();
        const req = reqList.find(item => Number(item.id) === idNum);
        if (!req) return;

        // Mettre à jour l'attribut data-selected-id
        const rootEl = root();
        if (rootEl) rootEl.dataset.selectedId = idNum;

        // Mise à jour visuelle des lignes et barres de Gantt
        $('.gantt-row').removeClass('is-selected');
        $('.gantt-bar').removeClass('is-selected');
        const activeRow = $(`.gantt-row[data-affaire-id="${idNum}"]`);
        activeRow.addClass('is-selected');
        activeRow.find('.gantt-bar').addClass('is-selected');

        // BLOC 1: INFORMATION GÉNÉRALE
        $('#detail-date-debut').text(displayDate(req.date_debut_planifiee));
        $('#detail-date-fin').text(displayDate(req.date_fin_planifiee));
        $('#detail-personnel').text(req.personnel_atelier_nom || 'Non affecté');
        $('#detail-matiere').text(req.matiere_libelle || 'Non précisée');
        
        const planContainer = $('#detail-plan').empty();
        if (req.plans && req.plans.length > 0) {
            const firstPlan = req.plans[0];
            const isPdf = /\.pdf$/i.test(firstPlan.name);
            const planLink = $('<a>')
                .attr('href', `actions/download_prefa_attachment.php?ref=${encodeURIComponent(req.reference_demande)}&file=${encodeURIComponent(firstPlan.key)}`)
                .attr('data-name', firstPlan.name)
                .text(firstPlan.name)
                .appendTo(planContainer);
            if (isPdf) {
                planLink.addClass('prefa-pdf-link').attr('aria-haspopup', 'dialog');
            } else {
                planLink.attr('target', '_blank').attr('rel', 'noopener');
            }
            if (req.plans.length > 1) {
                $('<span>').text(` (+${req.plans.length - 1})`).appendTo(planContainer);
            }
        } else if (req.plan_bpe_iso) {
            $('<span>').text(req.plan_bpe_iso).appendTo(planContainer);
        } else {
            $('<span>').text('Aucun plan associé').appendTo(planContainer);
        }

        $('#btn-edit-general').attr('data-plan-id', req.id);

        // BLOC 2: DÉTAIL AVANCEMENT (MATRICE)
        renderAvancementMatrix(req);

        // BLOC 3: COMMENTAIRES
        renderCommentsList(req);

        // BLOC 4: LIVRAISON
        $('#detail-depart-livraison').text(displayDate(req.date_fin_prevue));
        $('#detail-reception-livraison').text(displayDate(req.date_livraison_prevue));
        $('#btn-edit-livraison').attr('data-plan-id', req.id);
        const panel = rootEl?.querySelector('#planning-details-panel');
        if (panel) panel.hidden = false;
    }

    function stepStatus(element, key) {
        const value = element.statuts?.[key] ?? (key === 'autre' ? 'RAS' : '');
        return ['OK', 'Non OK', 'RAS'].includes(value) ? value : 'Non renseigné';
    }

    function renderAvancementMatrix(req) {
        const headTr = $('#matrix-head tr').empty();
        $('<th>').text('Étape').appendTo(headTr);
        const elements = req.elements || [];
        elements.forEach(elem => {
            $('<th>').text(elem.reference || elem.libelle || 'Repère').appendTo(headTr);
        });
        const tbody = $('#matrix-body').empty();
        if (!elements.length) {
            $('<tr>').append($('<td>').text('Aucun élément / ISO associé à cette demande.')).appendTo(tbody);
            return;
        }
        standardSteps().forEach(([key, label]) => {
            const tr = $('<tr>').appendTo(tbody);
            $('<td class="step-label">').text(label).appendTo(tr);
            elements.forEach(elem => {
                const status = stepStatus(elem, key);
                const statusClass = { OK: 'status-ok', 'Non OK': 'status-non-ok', RAS: 'status-ras' }[status] || 'status-unset';
                $('<td>').addClass(statusClass).text(status)
                    .attr('title', `${label} - ${elem.reference || 'Repère'}`).appendTo(tr);
            });
        });
    }
    function renderCommentsList(req) {
        const container = $('#comments-list').empty();
        const comments = req.commentaires || [];

        if (comments.length === 0) {
            $('<p class="planning-empty-text">').text('Aucun commentaire pour cette affaire.').appendTo(container);
            return;
        }

        comments.forEach(c => {
            const item = $('<div class="comment-item">').appendTo(container);
            $('<div class="comment-meta">').text(`Le ${c.date} par ${c.auteur}`).appendTo(item);
            $('<p class="comment-text">').text(c.texte).appendTo(item);
        });
    }

    // =========================================================================
    // EXPORTS CSV (AVANCEMENT & COMMENTAIRES)
    // =========================================================================

    function downloadCSV(filename, rows) {
        const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + rows.map(e => e.join(';')).join('\n');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function exportAvancement() {
        const currentId = Number(root()?.dataset?.selectedId || 0);
        const req = requests().find(item => Number(item.id) === currentId);
        if (!req) return;
        const elements = req.elements || [];
        const rows = [['Étape', ...elements.map(e => e.reference || e.libelle || 'Repère')]];
        if (!elements.length) rows.push(['Aucun élément / ISO associé à cette demande.']);
        else standardSteps().forEach(([key, label]) => {
            rows.push([label, ...elements.map(elem => stepStatus(elem, key))]);
        });
        downloadCSV(`avancement_affaire_${req.reference_demande}_${req.nom_affaire || 'prefa'}.csv`, rows);
    }
    function exportCommentaires() {
        const currentId = Number(root()?.dataset?.selectedId || 0);
        const req = requests().find(item => Number(item.id) === currentId);
        if (!req) return;

        const comments = req.commentaires || [];
        const rows = [['Date', 'Auteur', 'Commentaire']];

        comments.forEach(c => {
            rows.push([`"${c.date}"`, `"${c.auteur.replace(/"/g, '""')}"`, `"${c.texte.replace(/"/g, '""')}"`]);
        });

        downloadCSV(`commentaires_affaire_${req.reference_demande}_${req.nom_affaire || 'prefa'}.csv`, rows);
    }

    // =========================================================================
    // RECHARGEMENT & SYNCHRONISATION
    // =========================================================================

    function reload(message = '', focusId = null, removed = false) {
        const current = root();
        const form = current?.querySelector('#planning-filters');
        if (!form) return;
        const params = new URLSearchParams(new FormData(form));
        if (loading) loading.abort();
        current.setAttribute('aria-busy', 'true');
        loading = $.get('pages/planning.php?' + params.toString()).done(html => {
            if (root() !== current) return;
            $('#content').html(html);
            const next = root();
            window.PlanningGantt?.recentrer();
            
            const targetId = Number(focusId || (!removed && current.dataset.selectedId) || 0);
            if (targetId && next?.querySelector(`[data-select-affaire="${targetId}"], [data-plan-id="${targetId}"], [data-view-plan-id="${targetId}"]`)) {
                selectAffaire(targetId);
                const block = next?.querySelector(`.gantt-bar[data-plan-id="${Number(targetId)}"]`);
                if (block) {
                    block.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            }
            if (message) $(next).find('#planning-page-message').removeClass('is-error is-success is-removed').addClass(removed ? 'is-removed' : 'is-success').text(message);
        }).fail((xhr, status) => {
            if (status !== 'abort' && root() === current) {
                $(current).find('#planning-page-message').addClass('is-error').text('Impossible de charger le planning. Réessayez.');
            }
        }).always(() => current.removeAttribute('aria-busy'));
    }

    function preview() {
        const form = document.getElementById('planning-edit-form');
        if (!form) return;
        const first = date(form.elements.start.value);
        const last = date(form.elements.end.value);
        form.elements.end.min = form.elements.start.value;
        form.elements.end.setCustomValidity(first && last && last < first ? 'La fin doit suivre le début du planning.' : '');
        const box = $('#planning-preview').empty();
        if (!first || !last) {
            box.html('<span class="planning-preview-placeholder"><svg class="icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Sélectionnez les dates de début et de fin.</span>');
            return;
        }
        if (last < first) {
            box.html('<span class="planning-preview-error"><svg class="icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> La date de fin doit être postérieure au début.</span>');
            return;
        }
        const workingDays = countWorkingDays(first, last);
        const $content = $('<div class="planning-preview-content"></div>');
        const $dates = $('<div class="planning-preview-dates"></div>');
        $dates.append($('<span class="preview-date-item"></span>').text(displayDate(form.elements.start.value)));
        $dates.append($('<span class="preview-arrow">➔</span>'));
        $dates.append($('<span class="preview-date-item"></span>').text(displayDate(form.elements.end.value)));
        $content.append($dates);
        $content.append($('<div class="planning-preview-badge"></div>').text(workingDays + ' j ouvré' + (workingDays > 1 ? 's' : '')));
        box.append($content);
    }

    // Initialiser au chargement la première affaire sélectionnée
    $(() => {
        const rootEl = root();
        if (rootEl && rootEl.dataset.selectedId) {
            selectAffaire(rootEl.dataset.selectedId);
        }
    });

    // Clic pour sélectionner une affaire
    // Le calendrier ne se fait plus glisser a la souris depuis le retrait de la
    // timeline : Maj + molette au-dessus du Gantt le fait defiler horizontalement,
    // comme le faisait l'ancienne vue. Les fleches marchent deja, le conteneur
    // prenant le focus clavier.
    $(document).on('wheel', '.gantt-scroll-wrapper', function (event) {
        const brut = event.originalEvent;
        if (!brut.shiftKey && Math.abs(brut.deltaX) <= Math.abs(brut.deltaY)) return;
        const pas = brut.shiftKey ? brut.deltaY || brut.deltaX : brut.deltaX;
        if (!pas) return;
        const avant = this.scrollLeft;
        this.scrollLeft += pas;
        // On ne confisque l'evenement que si le calendrier a reellement defile,
        // sinon la page ne peut plus defiler une fois le Gantt au bout.
        if (this.scrollLeft !== avant) event.preventDefault();
    });

    // Glisser-deplacer du calendrier. Le seuil separe un glissement voulu d'un clic :
    // une souris bouge de quelques pixels pendant un clic normal, 4 px classaient donc
    // tous les clics en glissements et les faisaient avaler. 10 px demande un geste
    // franc.
    const SEUIL = 10;
    let pan = null;
    let ignorerProchainClic = false;

    // Traces de mise au point. Mettre window.GANTT_DEBUG = false dans la console
    // pour les couper, true pour les rallumer.
    window.GANTT_DEBUG = window.GANTT_DEBUG !== false;
    const nomCourt = el => {
        if (!el || !el.tagName) return String(el);
        const classes = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '';
        return el.tagName.toLowerCase() + classes;
    };
    const trace = (quoi, detail) => {
        if (!window.GANTT_DEBUG) return;
        const etat = 'pan=' + (pan ? (pan.glisse ? 'glissement' : 'appui') : 'aucun')
            + ' avalerClic=' + ignorerProchainClic;
        const extra = Object.entries(detail || {}).map(([k, v]) => k + '=' + v).join(' ');
        console.log('[gantt] ' + quoi + ' | ' + etat + (extra ? ' | ' + extra : ''));
    };

    const zoneGantt = () => document.querySelector('.gantt-scroll-wrapper');
    const largeurJour = () => document.querySelector('.gantt-day-number')?.getBoundingClientRect().width || 0;
    const margeJours = () => Number(document.querySelector('.gantt-layout')?.dataset.ganttMarge || 0);

    // Le calendrier est dessine avec une periode de marge de chaque cote : au repos, on
    // se place au debut de la periode demandee, c'est-a-dire apres la marge de gauche.
    function recentrerCalendrier() {
        const zone = zoneGantt();
        if (!zone) return;
        zone.scrollLeft = margeJours() * largeurJour();
    }
    window.PlanningGantt = { recentrer: recentrerCalendrier };

    // Avaleur de clic installe une seule fois, en phase de capture. Un drapeau vaut
    // mieux qu'un ecouteur pose puis retire a chaque geste : si le clic attendu
    // n'arrive jamais, un ecouteur temporaire reste en embuscade et mange le clic
    // suivant, ce qui donne des boutons qui ne repondent plus par intermittence.
    document.addEventListener('click', event => {
        if (!ignorerProchainClic) return;
        ignorerProchainClic = false;
        trace('clic AVALE', { cible: nomCourt(event.target) });
        event.stopPropagation();
        event.preventDefault();
    }, true);

    // Tout appui, ou qu'il soit, annule un avalement en attente. Sans ce garde-fou,
    // un glissement termine sans clic laisse le drapeau arme, et c'est un clic
    // legitime ailleurs dans la page qui se fait manger.
    document.addEventListener('pointerdown', () => { ignorerProchainClic = false; }, true);

    function finirPan(raison) {
        if (!pan) return;
        const { glisse, depart, dx = 0, pointer } = pan;
        const zone = zoneGantt();
        // On libere l'etat avant tout appel susceptible d'echouer : sinon une
        // exception laisse « pan » en place et le calendrier croit qu'un glissement
        // est toujours en cours, ce qui bloque les clics suivants.
        pan = null;
        document.body.classList.remove('is-panning-gantt');
        try { zone && zone.releasePointerCapture && zone.releasePointerCapture(pointer); }
        catch (e) { trace('releasePointerCapture a echoue (sans consequence)', { message: e.message }); }
        const aBouge = zone && zone.scrollLeft !== depart;
        // Un glissement ne doit pas ouvrir la demande sur laquelle il s'est termine,
        // mais seulement s'il a vraiment deplace quelque chose.
        if (glisse && raison === 'relachement' && aBouge) ignorerProchainClic = true;
        trace('fin du geste', { raison, glissement: glisse, dx: Math.round(dx) });
        if (glisse && raison === 'relachement') recentrerSurLaVue();
    }

    // En fin de geste, la periode affichee rattrape la position atteinte : on note la
    // date passee sous le bord gauche et on la demande au serveur. Le rendu suivant
    // replace cette date au meme endroit, donc rien ne saute a l'ecran.
    function recentrerSurLaVue() {
        const zone = zoneGantt();
        const largeur = largeurJour();
        if (!zone || !largeur) return;
        const jours = Math.round(zone.scrollLeft / largeur) - margeJours();
        if (!jours) return;
        const champ = document.getElementById('planning-start');
        const courant = date(champ && champ.value);
        if (!courant) return;
        champ.value = iso(addDays(courant, jours));
        trace('periode rattrapee apres le geste', { jours, nouveauDebut: champ.value });
        reload();
    }

    $(document).on('pointerdown', '.gantt-scroll-wrapper', function (event) {
        const brut = event.originalEvent;
        if (brut.button !== 0) { trace('appui ignore (bouton non principal)', { bouton: brut.button }); return; }
        finirPan('nouvel appui');  // un geste precedent mal termine ne doit rien bloquer
        pan = { x: brut.clientX, depart: this.scrollLeft, glisse: false, pointer: brut.pointerId };
        trace('appui sur le calendrier', { x: Math.round(brut.clientX), scrollLeft: this.scrollLeft, cible: nomCourt(brut.target) });
    });

    // Pendant le geste, rien d'autre que du defilement : c'est ce qui le rend fluide.
    $(document).on('pointermove', function (event) {
        if (!pan) return;
        const brut = event.originalEvent;
        // Le bouton a ete relache hors de la fenetre : on referme le geste.
        if (brut.buttons === 0) { finirPan('bouton relache hors de la page'); return; }
        const zone = zoneGantt();
        if (!zone) return;
        const dx = brut.clientX - pan.x;
        if (!pan.glisse) {
            if (Math.abs(dx) < SEUIL) return;
            pan.glisse = true;
            document.body.classList.add('is-panning-gantt');
            trace('glissement amorce', { dx: Math.round(dx), seuil: SEUIL });
            try { zone.setPointerCapture && zone.setPointerCapture(pan.pointer); }
            catch (e) { trace('setPointerCapture a echoue (sans consequence)', { message: e.message }); }
        }
        pan.dx = dx;
        zone.scrollLeft = pan.depart - dx;
        event.preventDefault();
    });

    $(document).on('pointerup', () => finirPan('relachement'));
    $(document).on('pointercancel', () => finirPan('pointeur annule'));
    $(document).on('lostpointercapture', () => finirPan('capture perdue'));
    $(window).on('blur', () => finirPan('fenetre sans focus'));

    // Les actions du planning passent par ces clics : on trace leur arrivee pour
    // savoir si un clic s'est perdu en route.
    $(document).on('click', '[data-plan-id], [data-view-plan-id], [data-select-affaire]', function () {
        trace('clic recu par une action du planning', { cible: nomCourt(this) });
    });

    $(document).on('click', '[data-select-affaire]', function () {
        selectAffaire(this.dataset.selectAffaire);
    });
    $(document).on('click', '[data-view-plan-id]', function () {
        selectAffaire(this.dataset.viewPlanId);
        const panel = document.getElementById('planning-details-panel');
        const page = root();
        if (!panel || !page) return;
        // Ne défiler que si le panneau n’est pas déjà visible, sinon la page saute à chaque clic.
        const cible = panel.getBoundingClientRect();
        const vue = page.getBoundingClientRect();
        if (cible.top < vue.top || cible.top > vue.bottom - 80) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    document.addEventListener('planning:select', event => selectAffaire(event.detail.id));
    document.addEventListener('planning:edit', event => {
        const button = root()?.querySelector(`.gantt-bar[data-plan-id="${Number(event.detail.id)}"]`);
        if (button) button.click();
    });

    // Boutons d'export
    $(document).on('click', '#btn-export-avancement', exportAvancement);
    $(document).on('click', '#btn-export-commentaires', exportCommentaires);

    // Clic pour modifier une planification (ouvre le modal)
    $(document).on('click', '[data-plan-id]', function (e) {
        const planId = Number(this.dataset.planId);
        selectAffaire(planId);

        // Si ce clic vient spécifiquement de l'étiquette ou sélection, ne pas ouvrir directement le modal
        if ($(this).hasClass('gantt-affaire-badge')) return;

        const request = requests().find(item => Number(item.id) === planId);
        const dialog = document.getElementById('planning-dialog');
        const form = document.getElementById('planning-edit-form');
        if (!request || !dialog || !form) return;
        form.reset();
        form.elements.id.value = request.id;
        form.elements.revision.value = request.planning_revision;

        let startDateStr = request.date_debut_planifiee;
        if (!startDateStr) {
            const rawValidation = request.date_validation ? request.date_validation.slice(0, 10) : '';
            const candidate = date(rawValidation) || date(document.getElementById('planning-start')?.value) || new Date();
            startDateStr = iso(adjustToWorkingDay(candidate));
        }

        const hours = getHoursForRequest(request);
        let endDateStr = request.date_fin_planifiee;
        if (!endDateStr) {
            endDateStr = computeEndDate(startDateStr, hours);
        }

        form.elements.start.value = startDateStr;
        form.elements.end.value = endDateStr;
        $('#planning-dialog-title').text('Demande ' + (request.reference_complete || request.reference_demande));
        const durationDays = Math.ceil(hours / 24);
        const inches = parseInt(request.pouces_total_iso || 0, 10);
        
        const $desc = $('#planning-request-description').empty();
        const $chipDemandeur = $('<div class="planning-meta-chip"><span class="meta-label">Demandeur</span><strong class="meta-val"></strong></div>');
        $chipDemandeur.find('.meta-val').text(request.demandeur || '—');
        $desc.append($chipDemandeur);
        if (inches > 0) {
            // « 100 pouces ISO (100 j) » laisse croire a une coincidence : on montre les
            // heures, qui font le lien entre les deux (100 pouces x 24 h = 2 400 h = 100 j).
            const $chipChiffrage = $('<div class="planning-meta-chip"><span class="meta-label">Charge estimée</span><strong class="meta-val"></strong></div>');
            $chipChiffrage.find('.meta-val').text(
                inches + ' pouce' + (inches > 1 ? 's' : '') + ' ISO'
                + ' · ' + Math.round(hours).toLocaleString('fr-FR') + ' h'
                + ' (' + durationDays + ' j)');
            $desc.append($chipChiffrage);
        }
        const $chipLivraison = $('<div class="planning-meta-chip"><span class="meta-label">Livraison max</span><strong class="meta-val"></strong></div>');
        $chipLivraison.find('.meta-val').text(displayDate(request.date_livraison_prevue));
        $desc.append($chipLivraison);

        $('#planning-save-message').removeClass('is-error is-success').text('');
        document.getElementById('planning-remove').hidden = !request.date_debut_planifiee;
        preview();
        dialog.showModal();
    });

    $(document).on('click', '[data-planning-close]', () => document.getElementById('planning-dialog')?.close());
    $(document).on('change input', '#planning-task-start', function () {
        const currentId = document.getElementById('planning-edit-form')?.elements.id?.value;
        const request = requests().find(item => Number(item.id) === Number(currentId));
        if (request && this.value) {
            const hours = getHoursForRequest(request);
            const calculatedEnd = computeEndDate(this.value, hours);
            if (calculatedEnd) {
                document.getElementById('planning-task-end').value = calculatedEnd;
            }
        }
        preview();
    });
    $(document).on('change input', '#planning-task-end', preview);

    function save(operation) {
        const form = document.getElementById('planning-edit-form');
        if (!form || (operation === 'save' && !form.reportValidity())) return;
        const current = root();
        const params = new URLSearchParams(new FormData(form));
        params.set('operation', operation);
        const buttons = $(form).find('button');
        buttons.prop('disabled', true);
        $('#planning-save-message').removeClass('is-error').text('Enregistrement…');
        $.ajax({ url: 'actions/save_planning.php', method: 'POST', data: params.toString(), dataType: 'json' }).done(result => {
            if (root() !== current) return;
            document.getElementById('planning-dialog')?.close();
            if (result.planning) {
                const first = date(result.planning.start);
                document.getElementById('planning-start').value = iso(addDays(first, -((first.getUTCDay() + 6) % 7)));
                const creatorFilter = document.getElementById('planning-creator-filter');
                if (creatorFilter.value && Number(creatorFilter.value) !== Number(result.planning.creator)) creatorFilter.value = result.planning.creator;
            }
            reload(result.message, result.planning?.id, operation === 'remove');
        }).fail(xhr => {
            if (root() === current) $('#planning-save-message').addClass('is-error').text(xhr.responseJSON?.message || 'Impossible d’enregistrer le planning.');
        }).always(() => buttons.prop('disabled', false));
    }

    $(document).on('submit', '#planning-edit-form', function (event) { event.preventDefault(); save('save'); });
    $(document).on('click', '#planning-remove', () => save('remove'));
    $(document).on('submit', '#planning-filters', function (event) { event.preventDefault(); reload(); });
    $(document).on('change', '#planning-filters input, #planning-filters select', () => reload());
    $(document).on('click', '#planning-refresh', () => reload());
    $(document).on('click', '[data-planning-go-to]', function () {
        const first = date(this.dataset.planningGoTo);
        if (!first) return;
        document.getElementById('planning-start').value = iso(addDays(first, -((first.getUTCDay() + 6) % 7)));
        reload('', Number(this.dataset.planningTarget));
    });
    $(document).on('click', '#planning-create-user', () => $('#btn-create-user').trigger('click'));
    $(document).on('click', '[data-planning-shift]', function () {
        const field = document.getElementById('planning-start');
        const current = date(field.value);
        if (!current) return;
        field.value = iso(addDays(current, Number(this.dataset.planningShift) * Number(document.getElementById('planning-period').value)));
        reload();
    });
    $(document).on('click', '[data-planning-today]', () => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(item => [item.type, item.value]));
        const today = date(values.year + '-' + values.month + '-' + values.day);
        document.getElementById('planning-start').value = iso(addDays(today, -((today.getUTCDay() + 6) % 7)));
        reload();
    });
    // La page planning est inseree par AJAX depuis le menu : il n'y a pas d'evenement
    // de chargement a ecouter. On guette son apparition pour placer le calendrier au
    // debut de la periode demandee, la marge de gauche etant deja dessinee.
    function placerSiNecessaire() {
        const layout = document.querySelector('.gantt-layout');
        if (!layout || layout.dataset.ganttPlace === '1') return;
        const largeur = largeurJour();
        if (!largeur) return;          // pas encore mis en page, on repassera
        layout.dataset.ganttPlace = '1';
        recentrerCalendrier();
    }
    const contenu = document.getElementById('content');
    if (contenu) new MutationObserver(placerSiNecessaire).observe(contenu, { childList: true, subtree: true });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placerSiNecessaire);
    else placerSiNecessaire();
})();
