// Les gestes du formulaire de demande et de la liste des demandes : ce qui dépend
// d'un champ, le filtrage de la liste, l'envoi et la relecture d'une demande.
//
// Les deux listes déroulantes filtrables — passivation et matière — vivaient ici en
// double, et une troisième et une quatrième copie dormaient dans create_prefa.php. Les
// quatre répondaient aux mêmes sélecteurs : chaque frappe était traitée deux fois, et
// deux des copies portaient la même faute de frappe, « index » au lieu de « idx », qui
// cassait la flèche du haut. Elles sont remplacées par liste-filtrable.js, monté une
// fois sur chaque préfixe.

// Ce que l'écran retient d'un formulaire — vérifié, en cours d'envoi, examiné —, hors du
// DOM : un formulaire rechargé repart de zéro.
const prefaEtatFormulaire = new WeakMap();
function prefaEtat(form) {
    let etat = prefaEtatFormulaire.get(form);
    if (!etat) prefaEtatFormulaire.set(form, etat = {});
    return etat;
}

// Du HTML reçu du serveur, en nœuds prêts à poser.
function prefaFragment(html) {
    const modele = document.createElement('template');
    modele.innerHTML = html;
    return modele.content;
}

// Une requête qui attend du JSON : réussie si le serveur répond 2xx et que le corps se lit.
// « corps » est lu dans les deux cas — un refus porte son motif.
function prefaRequeteJson(adresse, options) {
    return fetch(adresse, { credentials: 'same-origin', ...options })
        .then(async reponse => {
            const corps = await reponse.json().catch(() => null);
            return { reussie: reponse.ok && corps !== null, statut: reponse.status, corps };
        })
        .catch(() => ({ reussie: false, statut: 0, corps: null }));
}

// Une page HTML ; null si le serveur la refuse ou que le réseau manque.
function prefaPageHtml(adresse, signal) {
    return fetch(adresse, { credentials: 'same-origin', signal })
        .then(reponse => reponse.ok ? reponse.text() : null)
        .catch(() => null);
}

const prefaVisible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);

document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#prefa-form [name="CDN"]');
    if (!champ) return;
    const form = champ.closest('form');
    if (!form) return;
    form.querySelectorAll('#cdn-details').forEach(el => { el.hidden = !champ.checked; });
    form.querySelectorAll('[name="controles_cdn"], #controles-value').forEach(el => {
        el.disabled = !champ.checked;
        el.required = champ.checked;
    });
});

// Un revêtement demande lequel. Le formulaire appelait en plus une fonction globale
// définie dans sa propre page, qui faisait la même chose à un détail près — porter le
// curseur dans le champ de précision. Elle est reprise ici, et la page n'a plus de JS.
document.addEventListener('change', evenement => {
    const choix = evenement.target.closest('#prefa-form #revetement-type');
    if (!choix) return;
    const revetu = choix.value !== 'aucun';
    const details = document.getElementById('revetement-details');
    if (details) details.hidden = !revetu;
    const precision = details?.querySelector('#revetement-precision');
    if (!precision) return;
    if (!revetu) precision.value = '';
    // Le champ vient d'apparaître : on y porte le curseur, après que le navigateur l'a
    // réellement affiché — on ne peut pas donner le focus à ce qui est encore caché.
    else setTimeout(() => precision.focus(), 50);
});

let prefaFilterTimer;
let prefaListRequest;
function refreshPrefaList() {
    const form = document.getElementById('prefa-filters');
    if (!form) return;
    const params = new URLSearchParams(new FormData(form));
    params.set('results', '1');
    if (prefaListRequest) prefaListRequest.abort();
    const request = new AbortController();
    prefaListRequest = request;
    prefaPageHtml('pages/lists_prefa.php?' + params.toString(), request.signal)
        .then(html => {
            if (html === null || request.signal.aborted) return;
            document.getElementById('prefa-results')?.replaceWith(prefaFragment(html));
            const total = document.getElementById('prefa-urgent-total');
            if (total) updateUrgentBadge(Number(total.dataset.count));
        })
        .finally(() => {
            if (prefaListRequest === request) prefaListRequest = null;
            lireCcpuVisibles();
        });
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

document.addEventListener('input', evenement => {
    if (evenement.target.closest('#prefa-search')) differerRafraichissement();
});
// Le formulaire émet « change » quand la barre de filtres bouge — c'est elle qui prévient,
// elle n'appelle pas le rechargement : c'est l'écran qui sait comment se recharger.
document.addEventListener('change', evenement => {
    if (evenement.target.closest('#prefa-filters')) differerRafraichissement();
});
document.addEventListener('click', evenement => {
    if (evenement.target.closest('#refresh-prefa')) refreshPrefaList();
});

// ===== Les onglets de statut =====
// L'onglet n'est qu'une autre manière de remplir le champ « status » du formulaire : le
// rafraîchissement sérialise le formulaire, il n'a rien d'autre à savoir. Un ancien lien
// pouvait retenir plusieurs statuts ; choisir un onglet n'en garde qu'un.
document.addEventListener('click', evenement => {
    const choisi = evenement.target.closest('.prefa-onglet');
    if (!choisi) return;
    const form = document.getElementById('prefa-filters');
    if (!form) return;
    const champs = form.querySelectorAll('.prefa-statut-choisi');
    champs.forEach((champ, rang) => { if (rang > 0) champ.remove(); });
    if (champs[0]) champs[0].value = choisi.dataset.statut;
    document.querySelectorAll('.prefa-onglet').forEach(onglet => {
        onglet.setAttribute('aria-pressed', String(onglet === choisi));
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
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-ligne-valider');
    if (!bouton) return;
    const form = formulaireExamen(bouton);
    const valider = form?.querySelector('button[value="2"]');
    if (!valider) return;
    bouton.closest('.prefa-decision').querySelectorAll('button').forEach(b => { b.disabled = true; });
    form.requestSubmit(valider);
});

// Refuser demande un motif : on ouvre le volet sur son champ plutôt que de refuser à l'aveugle.
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-ligne-refuser');
    if (!bouton) return;
    const form = formulaireExamen(bouton);
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
// Le nombre de contrôles se règle par un curseur et par un champ, qui se recopient l'un
// l'autre. Le même couple sert aux contrôles CDN, RT et PT.
function prefaLierControles(idCurseur, idValeur) {
    document.addEventListener('input', evenement => {
        if (!evenement.target.closest('#' + idCurseur)) return;
        const valeur = document.getElementById(idValeur);
        if (!valeur) return;
        valeur.value = evenement.target.closest('#' + idCurseur).value;
        valeur.required = true;
        valeur.setCustomValidity('');
    });
    const verifier = evenement => {
        const champ = evenement.target.closest('#' + idValeur);
        if (!champ) return;
        const rawValue = champ.value.trim();
        const normalizedValue = rawValue.replace(',', '.');
        const validFormat = /^\d*(?:\.\d*)?$/.test(normalizedValue);
        const value = Number(normalizedValue);
        const validValue = validFormat && normalizedValue !== '' && Number.isFinite(value) && value >= 1 && value <= 100;
        if (evenement.type === 'change' && rawValue !== normalizedValue) champ.value = normalizedValue;
        champ.setCustomValidity(validValue || rawValue === '' ? '' : 'Saisissez une valeur entre 1 et 100.');
        const curseur = document.getElementById(idCurseur);
        if (validValue && curseur) curseur.value = normalizedValue;
    };
    document.addEventListener('input', verifier);
    document.addEventListener('change', verifier);
}
prefaLierControles('controles', 'controles-value');

// Une ligne par fichier choisi, avec de quoi le retirer.
function prefaLigneFichier(nom, bouton) {
    const ligne = document.createElement('div');
    ligne.className = 'prefa-file-row';
    const intitule = document.createElement('span');
    intitule.textContent = nom;
    bouton.type = 'button';
    bouton.textContent = 'Retirer';
    ligne.append(intitule, bouton);
    return ligne;
}

document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#plan-documents');
    if (!champ) return;
    const files = Array.from(champ.files);
    const summary = files.length ? files.length + ' document(s) à envoyer.' : 'Nombre de documents illimité · 50 Mo maximum par fichier.';
    const resume = document.getElementById('plan-documents-selected');
    if (resume) resume.textContent = summary;
    const list = document.getElementById('plan-selected-files');
    if (!list) return;
    const lignes = document.createDocumentFragment();
    files.forEach((file, index) => {
        const bouton = document.createElement('button');
        bouton.className = 'prefa-remove-selected';
        bouton.dataset.index = index;
        lignes.append(prefaLigneFichier(file.name, bouton));
    });
    list.replaceChildren(lignes);
});
document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#qmos, #dmos, #materiel, #ccpu');
    if (!champ) return;
    const kind = champ.id;
    const files = Array.from(champ.files);
    let summary;
    if (kind === 'qmos') {
        const saved = document.querySelectorAll('#qmos-saved-files .prefa-file-row:not(.is-removed)').length;
        const total = saved + files.length;
        summary = total ? total + ' PDF QMOS à envoyer.' : 'Aucun QMOS sélectionné.';
    } else if (kind === 'dmos') {
        summary = files.length ? files.length + ' PDF DMOS à envoyer.' : 'Aucun nouveau fichier sélectionné.';
    } else if (kind === 'materiel') {
        summary = files.length ? files.length + ' fiche(s) matériel à envoyer.' : 'Aucun nouveau fichier sélectionné.';
    } else {
        summary = files.length ? files.length + ' certificat(s) à envoyer.' : 'Aucun nouveau fichier sélectionné.';
    }
    const aide = document.getElementById(kind + '-help');
    if (aide) aide.textContent = summary;
    const list = document.getElementById(kind + '-selected-files');
    if (!list) return;
    const lignes = document.createDocumentFragment();
    files.forEach((file, index) => {
        const bouton = document.createElement('button');
        bouton.className = 'prefa-remove-pdf-selected';
        bouton.dataset.index = index;
        bouton.dataset.input = kind;
        lignes.append(prefaLigneFichier(file.name, bouton));
    });
    list.replaceChildren(lignes);
});

// Retire un fichier choisi : le champ fichier ne se modifie pas, on lui en rend un autre.
function prefaRetirerFichier(input, rang) {
    const remaining = new DataTransfer();
    Array.from(input.files).forEach((file, index) => {
        if (index !== rang) remaining.items.add(file);
    });
    input.files = remaining.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-remove-pdf-selected');
    if (bouton) prefaRetirerFichier(document.getElementById(bouton.dataset.input), Number(bouton.dataset.index));
});
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-remove-selected');
    if (bouton) prefaRetirerFichier(document.getElementById('plan-documents'), Number(bouton.dataset.index));
});

// Un document déjà enregistré se barre, ou revient : c'est le champ caché qui dit s'il part.
function prefaBasculerRetrait(bouton, name, valeur) {
    const row = bouton.closest('.prefa-file-row');
    const form = bouton.closest('form');
    if (row?.classList.contains('is-removed')) {
        row.classList.remove('is-removed');
        form?.querySelectorAll('input').forEach(input => {
            if (input.name === name && input.value === valeur) input.remove();
        });
        bouton.textContent = 'Retirer';
    } else {
        row?.classList.add('is-removed');
        if (form) {
            const cache = document.createElement('input');
            cache.type = 'hidden';
            cache.name = name;
            cache.value = valeur ?? '';
            form.append(cache);
        }
        bouton.textContent = 'Annuler';
    }
}
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-remove-saved');
    if (bouton) prefaBasculerRetrait(bouton, 'remove_documents[]', bouton.dataset.key);
});
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-remove-qmos-saved, .prefa-remove-dmos-saved, .prefa-remove-materiel-saved, .prefa-remove-ccpu-saved');
    if (!bouton) return;
    const name = bouton.classList.contains('prefa-remove-qmos-saved') ? 'remove_qmos[]'
        : bouton.classList.contains('prefa-remove-dmos-saved') ? 'remove_dmos[]'
        : bouton.classList.contains('prefa-remove-materiel-saved') ? 'remove_materiel[]'
        : 'remove_ccpu[]';
    prefaBasculerRetrait(bouton, name, bouton.dataset.id);
    if (name === 'remove_qmos[]') document.getElementById('qmos')?.dispatchEvent(new Event('change', { bubbles: true }));
});
document.addEventListener('keydown', evenement => {
    const zone = evenement.target.closest?.('.prefa-dropzone');
    if (!zone) return;
    if (evenement.key === 'Enter' || evenement.key === ' ') {
        evenement.preventDefault();
        document.getElementById(zone.htmlFor).click();
    }
});
document.addEventListener('dragover', evenement => {
    const zone = evenement.target.closest?.('.prefa-dropzone');
    if (!zone) return;
    evenement.preventDefault();
    zone.classList.add('is-dragging');
});
const prefaQuitterDepot = evenement => {
    const zone = evenement.target.closest?.('.prefa-dropzone');
    if (!zone) return;
    evenement.preventDefault();
    zone.classList.remove('is-dragging');
    if (evenement.type === 'drop') {
        const input = document.getElementById(zone.htmlFor);
        const dropped = evenement.dataTransfer.files;
        if (input.multiple) {
            const combined = new DataTransfer();
            Array.from(input.files).concat(Array.from(dropped)).forEach(file => combined.items.add(file));
            input.files = combined.files;
        } else {
            input.files = dropped;
        }
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
};
document.addEventListener('dragleave', prefaQuitterDepot);
document.addEventListener('drop', prefaQuitterDepot);

document.addEventListener('htmx:afterSwap', function () {
    const total = document.getElementById('prefa-urgent-total');
    if (total) updateUrgentBadge(Number(total.dataset.count));
});

// ===== Modifier une demande : dans une fenêtre, au-dessus de la liste =====
//
// Le formulaire est celui de la nouvelle demande, avec son design ; il remplaçait l'écran
// entier, et l'on perdait la liste — sa recherche, son onglet, sa position — pour corriger
// une date. Il s'ouvre maintenant dans une fenêtre centrée, la liste reste derrière.
//
// La fenêtre est créée une fois et vidée à la fermeture : le formulaire porte l'id
// #prefa-form, et il ne doit jamais en exister deux dans la page.
function fenetreEdition() {
    let fenetre = document.getElementById('prefa-edition');
    if (fenetre) return fenetre;
    fenetre = document.createElement('dialog');
    fenetre.id = 'prefa-edition';
    fenetre.className = 'prefa-edition';
    fenetre.setAttribute('aria-label', 'Modifier la demande');
    fenetre.innerHTML = '<button type="button" class="prefa-edition-fermer" aria-label="Fermer" title="Fermer">'
        + '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>'
        + '</button><div class="prefa-edition-contenu"></div>';
    document.body.appendChild(fenetre);

    // Échap, ou le geste « fermer » du navigateur : on passe par la même porte que
    // « Annuler », pour que la confirmation d'une saisie en cours ne soit pas contournée.
    fenetre.addEventListener('cancel', evenement => {
        evenement.preventDefault();
        quitterFormulairePrefa();
    });
    // Un clic sur le voile, hors de la fenêtre, vaut « Annuler ».
    fenetre.addEventListener('click', evenement => {
        if (evenement.target === fenetre) quitterFormulairePrefa();
    });
    fenetre.querySelector('.prefa-edition-fermer').addEventListener('click', quitterFormulairePrefa);
    fenetre.addEventListener('close', () => {
        fenetre.querySelector('.prefa-edition-contenu').replaceChildren();
        if (fenetre.declencheur?.isConnected) fenetre.declencheur.focus();
    });
    return fenetre;
}

// « Nouvelle demande » : la même fenêtre, le formulaire vide. Le bouton vit dans l'en-tête
// de « Mes demandes » (et sur l'accueil, sous une affaire qu'on vient de recevoir) ;
// enregistrer referme la fenêtre et rafraîchit la liste derrière, comme pour « Modifier ».
//
// Sur une carte de « Mes affaires », il porte data-affaire : le formulaire s'ouvre sur ce
// chantier-là.
document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('[data-nouvelle-demande]');
    if (!bouton) return;
    const fenetre = fenetreEdition();
    const contenu = fenetre.querySelector('.prefa-edition-contenu');
    fenetre.declencheur = bouton;
    fenetre.setAttribute('aria-label', 'Nouvelle demande');
    contenu.innerHTML = '<p class="prefa-edition-attente">Chargement du formulaire…</p>';
    if (!fenetre.open) fenetre.showModal();
    const affaire = bouton.dataset.affaire;
    window.chargerDans(contenu, 'pages/create_prefa.php' + (affaire ? '?affaire=' + encodeURIComponent(affaire) : ''))
        .then(({ ok }) => {
            if (!ok) {
                contenu.innerHTML = '<p class="prefa-edition-attente">Impossible de charger le formulaire. Fermez la fenêtre puis réessayez.</p>';
            }
            lireCcpuVisibles();
        });
});

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.edit-prefa');
    if (!bouton) return;
    const fenetre = fenetreEdition();
    fenetre.setAttribute('aria-label', 'Modifier la demande');
    const contenu = fenetre.querySelector('.prefa-edition-contenu');
    // Le focus revient à la ligne d'où l'on est parti ; le menu qui portait « Modifier »
    // se referme, son déclencheur reste.
    fenetre.declencheur = bouton.closest('.prefa-actions')?.querySelector('.prefa-actions-declencheur') || bouton;
    contenu.innerHTML = '<p class="prefa-edition-attente">Chargement de la demande…</p>';
    if (!fenetre.open) fenetre.showModal();
    window.chargerDans(contenu, 'pages/create_prefa.php?id=' + encodeURIComponent(bouton.dataset.id))
        .then(({ ok }) => {
            if (!ok) {
                contenu.innerHTML = '<p class="prefa-edition-attente">Impossible de charger cette demande. Fermez la fenêtre puis réessayez.</p>';
            }
            // Pas de focus sur le premier champ : c'est la liste des affaires, qui s'ouvre au
            // focus. Il reste sur la croix, où showModal() l'a posé.
            lireCcpuVisibles();
        });
});

/**
 * Quitter le formulaire de demande : par le bouton du haut, par « Annuler », ou par Échap.
 *
 * Une saisie en cours ne se jette pas sans un mot : le formulaire porte une marque dès
 * qu'un champ bouge, et c'est elle qui décide s'il faut demander confirmation.
 *
 * Dans la fenêtre de modification, quitter la referme ; sur l'écran « Nouvelle demande »,
 * on revient à la liste.
 */
async function quitterFormulairePrefa() {
    const formulaire = document.getElementById('prefa-form');
    const fenetre = document.getElementById('prefa-edition');
    // Lu avant de poser la question : la fenêtre peut se refermer pendant qu'on y répond —
    // le navigateur ne laisse retenir Échap qu'une fois par geste —, et ce n'est pas une
    // raison pour partir vers la liste.
    const dansLaFenetre = !!fenetre?.open;
    // « Annuler » ne peut pas nommer l'issue sûre : c'est le bouton qu'on vient de presser
    // pour sortir. Elle dit donc ce qu'elle fait.
    if (formulaire?.dataset.modifie === '1' && !await window.confirmer('Quitter sans enregistrer ?', {
        texte: 'Les modifications seront perdues.',
        action: 'Quitter',
        annuler: 'Continuer la saisie',
        ton: 'danger',
    })) return;
    if (dansLaFenetre) {
        fenetre.close();
        return;
    }
    document.getElementById('btn-list-prefa')?.click();
}

document.addEventListener('click', evenement => {
    if (evenement.target.closest('.cancel-prefa-edit, .prefa-retour')) quitterFormulairePrefa();
});
const prefaMarquerModifie = evenement => {
    const form = evenement.target.closest?.('#prefa-form');
    if (form) form.dataset.modifie = '1';
};
document.addEventListener('input', prefaMarquerModifie);
document.addEventListener('change', prefaMarquerModifie);

// Échap quitte le formulaire — mais seulement s'il n'y a rien d'autre à refermer d'abord.
// Les fenêtres, les volets de filtres et les listes déroulantes se ferment eux aussi sur
// Échap, sans arrêter l'événement : c'est donc à nous de leur laisser la main.
const OUVERT_AVANT_LE_FORMULAIRE =
    'dialog[open], .filtre-volet:not([hidden]), .liste-filtrable-champ[aria-expanded="true"]';

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    if (!document.getElementById('prefa-form')) return;
    if (document.querySelector(OUVERT_AVANT_LE_FORMULAIRE)) return;
    // La touche est à nous : sans cela, le navigateur la traite à son tour une fois
    // l'événement passé, et referme la confirmation qui vient tout juste de s'ouvrir.
    event.preventDefault();
    quitterFormulairePrefa();
});

document.addEventListener('click', async evenement => {
    const button = evenement.target.closest('.delete-prefa');
    if (!button) return;
    const id = button.dataset.id;
    if (!await window.confirmer('Supprimer cette demande ?', {
        texte: 'Cette action est irréversible.',
        action: 'Supprimer',
        ton: 'danger',
    })) return;
    button.disabled = true;
    const { reussie, corps } = await prefaRequeteJson('actions/delete_prefa.php', {
        method: 'POST',
        body: new URLSearchParams({ id: id ?? '', csrf: button.dataset.csrf ?? '' }),
    });
    if (reussie) {
        refreshPrefaList();
    } else {
        window.alerte(corps?.message || 'Suppression impossible. Réessayez.', { ton: 'danger' });
        button.disabled = false;
    }
    lireCcpuVisibles();
});

// ===== Le visualiseur PDF =====
// Plein écran : le document à gauche, son sommaire à droite — une vignette par page,
// qu'on clique pour s'y rendre. Le sommaire n'existe que pour les documents dont
// l'adresse sait rendre ses pages (data-sommaire) ; les autres s'ouvrent sans.
const PDF_ICONES = {
    fichier: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/>',
    telecharger: '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
    sommaire: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M15 4v16"/>',
    fermer: '<path d="m6 6 12 12M18 6 6 18"/>',
};
const pdfIcone = nom => `<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${PDF_ICONES[nom]}</svg>`;

function pdfViewer() {
    let viewer = document.getElementById('prefa-pdf-viewer');
    if (viewer) return viewer;
    viewer = document.createElement('dialog');
    viewer.id = 'prefa-pdf-viewer';
    viewer.className = 'prefa-pdf-viewer';
    viewer.setAttribute('aria-labelledby', 'prefa-pdf-title');
    viewer.innerHTML = `
        <header class="prefa-pdf-toolbar">
            <span class="prefa-pdf-icone">${pdfIcone('fichier')}</span>
            <div class="prefa-pdf-titre"><h2 id="prefa-pdf-title"></h2><span class="prefa-pdf-pages"></span></div>
            <button type="button" class="prefa-pdf-bouton prefa-pdf-bouton-sommaire" aria-pressed="true" title="Afficher ou masquer le sommaire">${pdfIcone('sommaire')}Sommaire</button>
            <a class="prefa-pdf-bouton prefa-pdf-download" download>${pdfIcone('telecharger')}Télécharger</a>
            <button type="button" class="prefa-pdf-bouton prefa-pdf-close" aria-label="Fermer" title="Fermer (Échap)" autofocus>${pdfIcone('fermer')}</button>
        </header>
        <div class="prefa-pdf-corps">
            <iframe title="Aperçu PDF"></iframe>
            <aside class="prefa-pdf-sommaire" aria-label="Sommaire du document">
                <h3 class="prefa-pdf-sommaire-titre">Pages</h3>
                <ol class="prefa-pdf-sommaire-corps"></ol>
            </aside>
        </div>`;
    document.body.appendChild(viewer);

    viewer.querySelector('.prefa-pdf-close').addEventListener('click', () => viewer.close());
    viewer.addEventListener('close', () => {
        viewer.querySelector('iframe').src = 'about:blank';
        document.body.classList.remove('prefa-viewer-open');
    });
    // Le sommaire se replie pour rendre la largeur au document ; le choix tient d'un
    // document à l'autre, le temps de la page.
    viewer.querySelector('.prefa-pdf-bouton-sommaire').addEventListener('click', function () {
        const montre = this.getAttribute('aria-pressed') !== 'true';
        this.setAttribute('aria-pressed', String(montre));
        viewer.dataset.sommaireMasque = montre ? '' : '1';
        viewer.querySelector('.prefa-pdf-sommaire').hidden = !montre || !viewer.dataset.pages;
    });
    viewer.querySelector('.prefa-pdf-sommaire-corps').addEventListener('click', event => {
        const page = event.target.closest('.prefa-pdf-page');
        if (!page) return;
        // Le lecteur PDF du navigateur suit l'ancre #page= : on ne recharge rien.
        const iframe = viewer.querySelector('iframe');
        iframe.src = viewer.dataset.apercu + '#page=' + page.dataset.page;
        viewer.querySelectorAll('.prefa-pdf-page').forEach(p => p.classList.toggle('est-vue', p === page));
    });
    return viewer;
}

function pdfSommaire(viewer, adresse) {
    const corps = viewer.querySelector('.prefa-pdf-sommaire-corps');
    const sommaire = viewer.querySelector('.prefa-pdf-sommaire');
    const compte = viewer.querySelector('.prefa-pdf-pages');
    const bascule = viewer.querySelector('.prefa-pdf-bouton-sommaire');
    corps.innerHTML = '';
    compte.textContent = '';
    viewer.dataset.pages = '';
    sommaire.hidden = true;
    bascule.hidden = !adresse;
    if (!adresse) return;

    const url = new URL(adresse, window.location.href);
    url.searchParams.set('pages', '1');
    const demande = viewer.dataset.ouverture = String(Date.now());
    fetch(url.href)
        .then(reponse => reponse.ok ? reponse.json() : Promise.reject())
        .then(({pages}) => {
            // Un autre document a été ouvert entre-temps : ce sommaire n'est plus le sien.
            if (viewer.dataset.ouverture !== demande || !pages) return;
            viewer.dataset.pages = String(pages);
            compte.textContent = pages + (pages > 1 ? ' pages' : ' page');
            const vignette = new URL(adresse, window.location.href);
            corps.innerHTML = Array.from({length: Math.min(pages, 500)}, (_, i) => {
                vignette.searchParams.set('vignette', String(i + 1));
                return `<li><button type="button" class="prefa-pdf-page${i === 0 ? ' est-vue' : ''}" data-page="${i + 1}" aria-label="Aller à la page ${i + 1}">
                    <img loading="lazy" alt="" src="${vignette.href}"><span>${i + 1}</span></button></li>`;
            }).join('');
            sommaire.hidden = viewer.dataset.sommaireMasque === '1';
        })
        .catch(() => { bascule.hidden = true; });
}

document.addEventListener('click', event => {
    const lien = event.target.closest('.prefa-pdf-link');
    if (!lien) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const viewer = pdfViewer();
    viewer.querySelector('h2').textContent = lien.dataset.name;
    viewer.querySelector('.prefa-pdf-download').href = lien.href;
    const preview = new URL(lien.href, window.location.href);
    preview.searchParams.set('preview', '1');
    viewer.dataset.apercu = preview.href;
    viewer.querySelector('iframe').src = preview.href;
    pdfSommaire(viewer, lien.dataset.sommaire ? lien.href : '');
    viewer.showModal();
    document.body.classList.add('prefa-viewer-open');
});

/**
 * Le plan final en PDF, depuis la barre de la fenêtre du plan.
 *
 * Il est composé au moment où on le demande, et le serveur peut répondre qu'il n'a rien à
 * écrire, ou qu'il ne sait pas annoter ce PDF. Un lien « download » enregistrerait alors ce
 * refus dans un fichier nommé .pdf, illisible : on le demande soi-même, et un refus se lit
 * dans un toast, avec les mots du serveur.
 */
async function telechargerPlanFinal(adresse) {
    // Un cahier de quinze feuilles se compose en quelques secondes : on dit qu'on s'en occupe.
    const attente = window.toast?.('Préparation du PDF…', { duree: 60000 });
    let refus = 'Le plan final n’a pas pu être préparé.';
    try {
        const reponse = await fetch(adresse, { credentials: 'same-origin' });
        if (!reponse.ok) {
            refus = (await reponse.json().catch(() => null))?.message || refus;
            throw new Error(refus);
        }
        const disposition = reponse.headers.get('Content-Disposition') || '';
        const nom = /filename="([^"]+)"/.exec(disposition)?.[1] || 'Plan-final.pdf';
        const fichier = URL.createObjectURL(await reponse.blob());
        const lien = document.createElement('a');
        lien.href = fichier;
        lien.download = nom;
        document.body.appendChild(lien);
        lien.click();
        lien.remove();
        setTimeout(() => URL.revokeObjectURL(fichier), 1000);
    } catch {
        window.toast?.(refus, { erreur: true });
    } finally {
        // Retiré sans clic : un clic remonterait jusqu'au document, où d'autres l'écoutent.
        attente?.remove();
    }
}

// Le lien garde son adresse : Ctrl + clic, ou le clic du milieu, l'ouvrent encore à part.
document.addEventListener('click', event => {
    const lien = event.target.closest('.plan-dialog-telecharger');
    if (!lien) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    telechargerPlanFinal(lien.href);
});

/**
 * Une feuille et une ressource de la fenêtre du plan, chargées si la page ne les a pas.
 *
 * Le tableau de bord n'envoie la fenêtre qu'aux rôles qui ont un écran du plan ISO. Le
 * chargé d'affaire n'en a pas, et ne l'ouvre que pour regarder un plan final : il la reçoit
 * à ce moment-là, une fois. La feuille se glisse avant le thème clair, qui doit rester la
 * dernière à parler.
 */
function chargerRessource(adresse, feuille) {
    const chemin = adresse.split('?')[0];
    const deja = feuille
        ? document.querySelector(`link[rel="stylesheet"][href*="${chemin.replace('../', '')}"]`)
        : document.querySelector(`script[src*="${chemin.replace('../', '')}"]`);
    if (deja) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const element = document.createElement(feuille ? 'link' : 'script');
        if (feuille) {
            element.rel = 'stylesheet';
            element.href = adresse;
        } else {
            element.src = adresse;
        }
        element.onload = resolve;
        element.onerror = reject;
        const theme = feuille && document.querySelector('link[href*="light-theme.css"]');
        if (theme) theme.before(element);
        else document.head.appendChild(element);
    });
}

/**
 * Le plan final d'une feuille, dans la fenêtre du plan, en consultation : le dessin, et
 * par-dessus les coulées, la liste soudure validée, ses croix et ses visas — ce que le PDF
 * y écrit. Il s'ouvrait dans le lecteur PDF du navigateur, étranger à l'application.
 *
 * La fenêtre est rendue à la demande (plan_final_lecteur.php) et posée dans un bloc à part,
 * hors du contenu : elle survit au rafraîchissement de la liste qui l'a ouverte.
 */
async function ouvrirLecteurPlanFinal(element, documentId) {
    try {
        const adresse = `actions/plan_final_lecteur.php?plan=${encodeURIComponent(element)}&document=${encodeURIComponent(documentId)}`;
        const reponse = await fetch(adresse, { credentials: 'same-origin' });
        const corps = await reponse.json().catch(() => null);
        if (!reponse.ok || !corps?.html) throw new Error(corps?.message || '');

        await Promise.all([
            ...(corps.styles || []).map(feuille => chargerRessource(feuille, true)),
            ...(corps.scripts || []).map(script => chargerRessource(script, false)),
        ]);

        let bloc = document.getElementById('plan-final-lecteur');
        if (!bloc) {
            bloc = document.createElement('div');
            bloc.id = 'plan-final-lecteur';
            document.body.appendChild(bloc);
        }
        bloc.innerHTML = corps.html;
        bloc.querySelector('.plan-viewer-ouvrir')?.click();
    } catch (erreur) {
        window.toast?.(erreur.message || 'Le plan n’a pas pu s’ouvrir. Réessayez.', { erreur: true });
    }
}

document.addEventListener('click', evenement => {
    const bascule = evenement.target.closest('.prefa-toggle[aria-controls]:not(.plans-iso-toggle)');
    if (!bascule) return;
    const details = document.getElementById(bascule.getAttribute('aria-controls'));
    if (!details) return;
    const expanded = bascule.getAttribute('aria-expanded') === 'true';

    // Fermer les autres panneaux de détails ouverts pour n'en afficher qu'un à droite
    if (!expanded) {
        document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(row => {
            row.hidden = true;
        });
        document.querySelectorAll('.prefa-toggle[aria-expanded="true"]').forEach(btn => {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    bascule.setAttribute('aria-expanded', String(!expanded));
    details.hidden = expanded;
});

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.prefa-detail-close');
    if (!bouton) return;
    const targetId = bouton.dataset.closeTarget;
    const details = targetId ? document.getElementById(targetId) : bouton.closest('.prefa-detail-row');
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
document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('.prefa-depot-champ');
    if (!champ) return;
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
    prefaRequeteJson('actions/lire_ccpu.php', { method: 'POST', body: donnees })
        .then(({ reussie, corps }) => {
            if (reussie) {
                if (corps.html) bloc.replaceWith(prefaFragment(corps.html));
            } else {
                // Le bloc reste en place avec le motif et de quoi relancer : une lecture
                // ratée ne doit pas tourner en boucle à chaque rafraîchissement de la liste.
                const relance = document.createElement('button');
                relance.type = 'button';
                relance.className = 'ccpu-relire';
                relance.textContent = 'Réessayer';
                if (bloc.dataset.ccpuLire !== undefined) relance.dataset.ccpuDocument = bloc.dataset.ccpuLire;
                if (csrf !== undefined) relance.dataset.csrf = csrf;
                const motif = document.createElement('p');
                motif.textContent = corps?.message || 'Lecture impossible. Réessayez.';
                bloc.classList.remove('is-en-attente');
                bloc.classList.add('is-echec');
                bloc.removeAttribute('data-ccpu-lire');
                bloc.replaceChildren(motif, relance);
            }
            lireCcpuVisibles();
        });
}

// Les fiches s'ouvrent par clic, mais sont aussi rouvertes après un rechargement de la
// liste : on regarde donc dans les deux cas quels certificats visibles restent à lire —
// après chaque requête de cet écran, qui l'appelle en fin de course.
function lireCcpuVisibles() {
    document.querySelectorAll('.prefa-detail-row:not([hidden]) [data-ccpu-lire]').forEach(bloc => lireCcpu(bloc, bloc.dataset.csrf));
}
document.addEventListener('click', evenement => {
    if (evenement.target.closest('.prefa-toggle[aria-controls]')) setTimeout(lireCcpuVisibles, 0);
});

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.ccpu-relire');
    if (!bouton) return;
    const bloc = document.createElement('div');
    bloc.className = 'ccpu-lecture is-en-attente';
    bloc.setAttribute('role', 'status');
    bloc.textContent = 'Lecture des coulées…';
    if (bouton.dataset.ccpuDocument !== undefined) bloc.dataset.ccpuLire = bouton.dataset.ccpuDocument;
    bouton.closest('.ccpu-lecture')?.replaceWith(bloc);
    lireCcpu(bloc, bouton.dataset.csrf);
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

document.addEventListener('keydown', function (event) {
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
document.addEventListener('click', function (event) {
    if (event.target === document.body) fermerVoletOuvert();
});

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.btn-goto-plans-iso');
    if (!bouton) return;
    // Un identifiant numérique se lit en nombre : « 0 » ne désigne aucune demande.
    const brut = bouton.dataset.requestId;
    const reqId = brut !== undefined && String(Number(brut)) === brut ? Number(brut) : brut;
    document.getElementById('btn-plans-iso')?.click();
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

// Les champs de saisie obligatoires — boutons compris, comme le faisait « :input ».
const PREFA_CHAMPS_OBLIGATOIRES = 'input[required], select[required], textarea[required], button[required]';

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
    prefaEtat(form).verifie = true;
    const manquants = [];
    form.querySelectorAll(PREFA_CHAMPS_OBLIGATOIRES).forEach(champ => {
        if (!prefaVisible(champ)) return;
        colorerChamp(champ);
        if (!champ.checkValidity()) manquants.push(champ);
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
    form.querySelectorAll('.liste-filtrable[data-obligatoire="1"]').forEach(liste => {
        const cache = liste.querySelector('input[type="hidden"]');
        const saisie = liste.querySelector('.liste-filtrable-champ');
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
const prefaRecolorer = evenement => {
    const champ = evenement.target.closest?.('#prefa-form input[required], #prefa-form select[required], '
        + '#prefa-form textarea[required], #prefa-form button[required]');
    if (!champ) return;
    const form = champ.closest('form');
    if (!form || !prefaEtat(form).verifie) return;
    colorerChamp(champ);
};
document.addEventListener('input', prefaRecolorer);
document.addEventListener('change', prefaRecolorer);
document.addEventListener('focusout', prefaRecolorer);

// Choisir dans une liste filtrable la fait changer d'avis : c'est son champ caché qui
// annonce le choix, pas celui où l'on tape.
document.addEventListener('change', evenement => {
    const cache = evenement.target.closest('#prefa-form .liste-filtrable[data-obligatoire="1"] input[type="hidden"]');
    if (!cache) return;
    const form = cache.closest('form');
    if (!form || !prefaEtat(form).verifie) return;
    marquerListesObligatoires(form);
});

// Un dépôt efface son propre reproche.
document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#prefa-form #plan-documents, #prefa-form #qmos, #prefa-form #dmos');
    if (!champ) return;
    if (champ.files && champ.files.length) {
        champ.closest('form')?.querySelectorAll('label.prefa-dropzone[for="' + champ.id + '"]')
            .forEach(zone => zone.classList.remove('champ-invalide'));
    }
});

document.addEventListener('submit', event => {
    const form = event.target.closest('#prefa-form, .prefa-review');
    if (!form) return;
    event.preventDefault();
    const etat = prefaEtat(form);
    if (etat.submitting) return;
    const review = form.classList.contains('prefa-review');
    const status = event.submitter?.value;
    const messages = form.querySelectorAll('.prefa-message');
    const annoncer = (classe, texte) => messages.forEach(message => {
        if (classe) message.classList.add(classe);
        message.textContent = texte;
    });
    messages.forEach(message => message.classList.remove('success', 'error'));
    if (review && !['2', '3'].includes(status)) {
        annoncer('error', 'Choisissez Valider ou Refuser.');
        return;
    }
    const commentaire = form.querySelector('[name="commentaire"]');
    if (review && status === '3' && !commentaire.value.trim()) {
        annoncer('error', 'Indiquez le motif du refus.');
        commentaire.focus();
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
        const creation = !form.querySelector('[name="id"]');
        [
            { champ: 'plan-documents', gardes: '#plan-saved-files', aLaCreation: true },
            { champ: 'qmos', gardes: '#qmos-saved-files' },
            { champ: 'dmos', gardes: '#dmos-saved-files' },
        ].forEach(depot => {
            if (depot.aLaCreation && !creation) return;
            const deposes = form.querySelector('#' + depot.champ)?.files?.length || 0;
            const restants = form.querySelectorAll(depot.gardes + ' .prefa-file-row:not(.is-removed)').length;
            const zones = form.querySelectorAll('label.prefa-dropzone[for="' + depot.champ + '"]');
            const manque = deposes === 0 && restants === 0;
            zones.forEach(zone => zone.classList.toggle('champ-invalide', manque));
            if (manque && zones.length) manquants.push(zones[0]);
        });
        marquerChampsObligatoires(form).forEach(champ => manquants.push(champ));
        marquerListesObligatoires(form).forEach(champ => manquants.push(champ));

        if (manquants.length) {
            manquants.sort(ordreDePage);
            const nom = nomDuChamp(manquants[0]);
            annoncer('error', manquants.length === 1
                ? 'Il manque : ' + nom + '.'
                : 'Il manque ' + manquants.length + ' éléments, à commencer par : ' + nom + '.');
            amenerAuChamp(manquants[0]);
            return;
        }
    }
    const data = new FormData(form);
    if (review) data.append('statut', status);
    etat.submitting = true;
    form.querySelectorAll('button').forEach(b => { b.disabled = true; });
    annoncer(null, 'Enregistrement…');
    const voletFerme = () => !!form.closest('.prefa-detail-row')?.hidden;
    prefaRequeteJson(review ? 'actions/review_prefa.php' : 'actions/create_prefa.php', { method: 'POST', body: data })
        .then(({ reussie, corps: response }) => {
            if (!reussie) {
                const texte = response?.message || 'Enregistrement impossible. Réessayez.';
                annoncer('error', texte);
                if (review && voletFerme() && window.toast) {
                    window.toast(texte, { erreur: true });
                }
                return;
            }
            annoncer('success', response.message);
            // Validée depuis la ligne, le volet est resté fermé : sa réponse ne se verrait pas.
            if (review && voletFerme() && window.toast) {
                window.toast(response.message);
            }
            if (review) {
                const request = form.closest('.prefa-request');
                if (request?.classList.contains('is-urgent-pending')) {
                    request.classList.remove('is-urgent-pending');
                    const counter = document.getElementById('urgent-pending-count');
                    updateUrgentBadge(Math.max(0, Number(counter ? counter.textContent : '') - 1));
                }
                request?.querySelectorAll('.prefa-status').forEach(statut => {
                    statut.classList.remove('status-1');
                    statut.classList.add('status-' + status);
                    statut.textContent = status === '2' ? 'Validée' : 'Refusée';
                });
                etat.reviewed = true;
                form.querySelectorAll('textarea').forEach(zone => { zone.disabled = true; });
                setTimeout(refreshPrefaList, 700);
            } else if (form.closest('#prefa-edition')) {
                // Enregistrée depuis la fenêtre : on la referme et la liste se met à jour
                // derrière. La marque de saisie tombe d'abord, sinon la fermeture
                // demanderait de confirmer l'abandon de ce qu'on vient d'enregistrer.
                delete form.dataset.modifie;
                const fenetre = document.getElementById('prefa-edition');
                fenetre.close();
                if (window.toast) window.toast(response.message);
                refreshPrefaList();
                // Les autres écrans qui comptent des demandes l'apprennent ici : « Mes
                // affaires » remet sa carte à jour. Le déclencheur dit d'où l'on venait.
                document.dispatchEvent(new CustomEvent('demande:enregistree',
                    { detail: { declencheur: fenetre.declencheur } }));
            } else if (form.querySelector('[name="id"]')) {
                // Garder les valeurs enregistrées et actualiser le compteur administrateur.
                prefaPageHtml('pages/lists_prefa.php').then(html => {
                    if (html !== null) {
                        const total = prefaFragment(html).querySelector('#prefa-urgent-total');
                        if (total) updateUrgentBadge(Number(total.getAttribute('data-count')));
                    }
                    lireCcpuVisibles();
                });
                const id = form.querySelector('[name="id"]').value;
                prefaPageHtml('pages/create_prefa.php?id=' + encodeURIComponent(id)).then(html => {
                    if (html !== null && document.contains(form)) {
                        const refreshed = prefaFragment(html).querySelector('#prefa-form');
                        if (refreshed) {
                            form.replaceWith(refreshed);
                            refreshed.querySelectorAll('.prefa-message').forEach(message => {
                                message.classList.add('success');
                                message.textContent = response.message;
                            });
                        }
                    }
                    lireCcpuVisibles();
                });
            } else {
                form.reset();
                form.querySelectorAll('.prefa-file-list').forEach(liste => liste.replaceChildren());
                const emettre = (selecteur, nom) => form.querySelectorAll(selecteur)
                    .forEach(champ => champ.dispatchEvent(new Event(nom, { bubbles: true })));
                emettre('[name="CDN"], [name="RT"], [name="PT"]', 'change');
                emettre('#revetement-type', 'change');
                emettre('#controles, #controles-rt, #controles-pt', 'input');
                emettre('#plan-documents', 'change');
                emettre('#qmos', 'change');
                emettre('#dmos', 'change');
                emettre('#materiel', 'change');
                emettre('#ccpu', 'change');
                if (typeof selectPassivation === 'function') {
                    selectPassivation('', '');
                }
                setTimeout(function () {
                    document.getElementById('btn-list-prefa')?.click();
                }, 1200);
            }
        })
        .finally(() => {
            etat.submitting = false;
            form.querySelectorAll('button').forEach(b => { b.disabled = !!etat.reviewed; });
            if (review && !etat.reviewed) {
                form.closest('.prefa-request')?.querySelectorAll('.prefa-decision button')
                    .forEach(b => { b.disabled = false; });
            }
            lireCcpuVisibles();
        });
});


document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#prefa-form [name="RT"]');
    if (!champ) return;
    const form = champ.closest('form');
    if (!form) return;
    form.querySelectorAll('#rt-details').forEach(el => { el.hidden = !champ.checked; });
    form.querySelectorAll('[name="controles_rt"], #controles-rt-value').forEach(el => {
        el.disabled = !champ.checked;
        el.required = champ.checked;
    });
});

prefaLierControles('controles-rt', 'controles-rt-value');

document.addEventListener('change', evenement => {
    const champ = evenement.target.closest('#prefa-form [name="PT"]');
    if (!champ) return;
    const form = champ.closest('form');
    if (!form) return;
    form.querySelectorAll('#pt-details').forEach(el => { el.hidden = !champ.checked; });
    form.querySelectorAll('[name="controles_pt"], #controles-pt-value').forEach(el => {
        el.disabled = !champ.checked;
        el.required = champ.checked;
    });
});

prefaLierControles('controles-pt', 'controles-pt-value');




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

    // Un sous-menu s'ouvre contre son menu, à hauteur de son entrée. Sur sa droite si la place
    // le permet, sinon sur sa gauche — le cas ordinaire, puisque la colonne des actions est
    // la dernière du tableau et que le menu y est déjà aligné contre le bord.
    function placerSousMenu(declencheur, sousMenu) {
        const entree = declencheur.getBoundingClientRect();
        const menu = declencheur.closest('.prefa-actions-menu').getBoundingClientRect();
        const largeur = sousMenu.offsetWidth;
        const hauteur = sousMenu.offsetHeight;

        let gauche = menu.right + 2;
        if (gauche + largeur > window.innerWidth - MARGE) gauche = menu.left - 2 - largeur;
        // Le padding du sous-menu remonté : sa première entrée tombe en face de la sienne.
        const haut = Math.min(entree.top - 4, window.innerHeight - MARGE - hauteur);

        sousMenu.style.left = Math.max(MARGE, gauche) + 'px';
        sousMenu.style.top = Math.max(MARGE, haut) + 'px';
    }

    function fermerSousMenus(menu) {
        menu.querySelectorAll('.prefa-sous-menu:not([hidden])').forEach(sousMenu => {
            sousMenu.hidden = true;
            sousMenu.previousElementSibling?.setAttribute('aria-expanded', 'false');
        });
    }

    function fermerTout(sauf) {
        document.querySelectorAll('.prefa-actions-menu:not([hidden])').forEach(menu => {
            if (menu === sauf) return;
            fermerSousMenus(menu);
            menu.hidden = true;
            menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
        });
    }

    // Les entrées du menu lui-même, sans celles de son sous-menu : les flèches parcourent un
    // niveau à la fois.
    function entrees(menu) {
        return Array.from(menu.querySelectorAll(menu.classList.contains('prefa-sous-menu') ? 'button' : ':scope > button'));
    }

    function ouvrirSousMenu(declencheur) {
        const sousMenu = declencheur.nextElementSibling;
        sousMenu.hidden = false;
        declencheur.setAttribute('aria-expanded', 'true');
        placerSousMenu(declencheur, sousMenu);
        return sousMenu;
    }

    document.addEventListener('click', function (evenement) {
        const declencheur = evenement.target.closest('.prefa-actions-declencheur');
        if (!declencheur) return;
        evenement.preventDefault();
        evenement.stopPropagation();
        const menu = declencheur.nextElementSibling;
        if (!menu) return;
        const ouvrir = menu.hidden;
        fermerTout(menu);
        menu.hidden = !ouvrir;
        declencheur.setAttribute('aria-expanded', String(ouvrir));
        if (ouvrir) placer(declencheur, menu);
    });

    // Une commande choisie referme son menu. Le gestionnaire de l'action, délégué lui aussi,
    // s'exécute dans le même événement : masquer le bouton ne l'empêche pas de répondre.
    //
    // Sauf l'entrée d'un sous-menu, qui ouvre plutôt qu'elle n'agit.
    document.addEventListener('click', function (evenement) {
        const commande = evenement.target.closest('.prefa-actions-menu button:not(.prefa-sous-menu-declencheur)');
        if (!commande) return;
        const menu = commande.closest('.prefa-actions-menu');
        fermerSousMenus(menu);
        menu.hidden = true;
        menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
    });

    // L'entrée d'un sous-menu l'ouvre, et ne le referme pas : le survol l'a souvent ouvert
    // juste avant le clic, qui le fermerait sous la souris. Ouvert au clavier — Entrée ou
    // Espace, qui produisent un clic sans position —, il donne le focus à sa première entrée.
    document.addEventListener('click', function (evenement) {
        const entree = evenement.target.closest('.prefa-sous-menu-declencheur');
        if (!entree) return;
        const sousMenu = entree.nextElementSibling.hidden ? ouvrirSousMenu(entree) : entree.nextElementSibling;
        if (evenement.detail === 0) entrees(sousMenu)[0]?.focus();
    });

    // À la souris, il s'ouvre au survol de son entrée et se referme au survol d'une autre.
    document.addEventListener('mouseover', function (evenement) {
        const entree = evenement.target.closest?.('.prefa-actions-menu > button');
        if (!entree) return;
        if (entree.classList.contains('prefa-sous-menu-declencheur')) {
            if (entree.nextElementSibling.hidden) ouvrirSousMenu(entree);
        } else {
            fermerSousMenus(entree.closest('.prefa-actions-menu'));
        }
    });

    document.addEventListener('click', function (evenement) {
        if (!evenement.target.closest('.prefa-actions')) fermerTout(null);
    });

    document.addEventListener('keydown', function (evenement) {
        const ouvert = document.querySelector('.prefa-actions-menu:not([hidden])');

        // Dans un sous-menu, les flèches le parcourent ; Échap et la flèche gauche le
        // referment seul, et rendent le focus à son entrée.
        const sousMenu = evenement.target.closest?.('.prefa-sous-menu');
        if (sousMenu) {
            if (evenement.key === 'Escape' || evenement.key === 'ArrowLeft') {
                evenement.preventDefault();
                const declencheur = sousMenu.previousElementSibling;
                fermerSousMenus(sousMenu.closest('.prefa-actions-menu'));
                declencheur.focus();
            } else if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
                evenement.preventDefault();
                const liste = entrees(sousMenu);
                const pas = evenement.key === 'ArrowDown' ? 1 : -1;
                liste[(liste.indexOf(document.activeElement) + pas + liste.length) % liste.length]?.focus();
            }
            return;
        }

        if (evenement.key === 'ArrowRight' && evenement.target.matches?.('.prefa-sous-menu-declencheur')) {
            evenement.preventDefault();
            entrees(ouvrirSousMenu(evenement.target))[0]?.focus();
            return;
        }

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
            else {
                placer(declencheur, menu);
                menu.querySelectorAll('.prefa-sous-menu:not([hidden])').forEach(sousMenu =>
                    placerSousMenu(sousMenu.previousElementSibling, sousMenu));
            }
        });
    }, true);

    window.addEventListener('resize', () => fermerTout(null));
}());


/* ---------- Le plan final, depuis le menu d'une ligne ----------
 *
 * Une feuille choisie s'ouvre dans la fenêtre du plan (ouvrirLecteurPlanFinal).
 */
document.addEventListener('click', evenement => {
    const entree = evenement.target.closest('.prefa-plan-final');
    if (entree) ouvrirLecteurPlanFinal(entree.dataset.plan, entree.dataset.document);
});


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
document.addEventListener('change', evenement => {
    const cache = evenement.target.closest('#prefa-form [data-liste-filtrable="affaire"] input[type="hidden"]');
    if (!cache) return;
    const choisie = String(cache.value || '').trim() !== '';
    cache.closest('#prefa-form')?.classList.toggle('nd-attend-affaire', !choisie);
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

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.nd-haut');
    if (!bouton) return;
    const page = bouton.closest('.page');
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

document.addEventListener('click', evenement => {
    const choix = evenement.target.closest('.prefa-densite-choix');
    if (!choix) return;
    const densite = choix.dataset.densite;
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
