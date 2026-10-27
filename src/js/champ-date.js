// Le champ date : notre calendrier à la place de celui du système.
//
// Rien ne se déclare ici non plus. Tout « input type=date » de la page est habillé au
// passage ; un champ qui doit garder la fenêtre du navigateur porte data-date-natif.
//
// Le champ natif reste le porteur de la valeur : son nom, son « required » que le
// validateur lit avec checkValidity(), son format AAAA-MM-JJ que le serveur attend, et ses
// segments jj/mm/aaaa qu'on remplit au clavier. On ne remplace que la glyphe et la fenêtre.
// Un champ caché doublé d'un champ texte aurait coûté les trois : jQuery ignore les champs
// cachés dans « :input[required]:visible », et le serveur aurait reçu « 26/10/2026 ».
//
// Les écrans arrivent par AJAX dans #content, et des lignes s'ajoutent en cours de route —
// un bon de livraison, une soudure. L'habillage passe donc par un observateur, pas par un
// parcours unique au chargement.
(() => {
    'use strict';

    const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
                         'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    // La semaine commence lundi : c'est celle qu'on lit sur un planning d'atelier.
    const JOURS = [
        ['lu', 'lundi'], ['ma', 'mardi'], ['me', 'mercredi'], ['je', 'jeudi'],
        ['ve', 'vendredi'], ['sa', 'samedi'], ['di', 'dimanche'],
    ];

    const ICONE_CALENDRIER =
        '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor"' +
        ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="2.25" y="3.75" width="11.5" height="10" rx="2"/>' +
        '<path d="M2.25 7h11.5M5.5 2.25v2.5M10.5 2.25v2.5"/></svg>';
    const chevron = gauche =>
        '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor"' +
        ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="' + (gauche ? 'M10 3.5 5.5 8l4.5 4.5' : 'M6 3.5 10.5 8 6 12.5') + '"/></svg>';

    /* ---------- Les dates ---------- */

    const deuxChiffres = n => String(n).padStart(2, '0');
    const enIso = d => d.getFullYear() + '-' + deuxChiffres(d.getMonth() + 1) + '-' + deuxChiffres(d.getDate());

    // On lit la valeur à la main plutôt qu'avec new Date('2026-10-26') : ce constructeur
    // interprète la forme courte en UTC, et à l'ouest de Greenwich le premier du mois
    // revenait au dernier jour du précédent.
    const depuisIso = texte => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(texte || '').trim());
        if (!m) return null;
        const d = new Date(+m[1], +m[2] - 1, +m[3]);
        // Le 31 février n'existe pas : Date le reporte au 2 ou 3 mars sans rien dire.
        return d.getMonth() === +m[2] - 1 && d.getDate() === +m[3] ? d : null;
    };

    const memeJour = (a, b) => !!a && !!b
        && a.getFullYear() === b.getFullYear()
        && a.getMonth() === b.getMonth()
        && a.getDate() === b.getDate();

    const plusJours = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);

    // Le 31 mars moins un mois : février n'a pas de 31. On retombe sur son dernier jour,
    // plutôt que de déborder sur le 2 mars comme le fait setMonth().
    const plusMois = (d, n) => {
        const cible = new Date(d.getFullYear(), d.getMonth() + n, 1);
        const dernier = new Date(cible.getFullYear(), cible.getMonth() + 1, 0).getDate();
        cible.setDate(Math.min(d.getDate(), dernier));
        return cible;
    };

    const aujourdhui = () => {
        const d = new Date();
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    };

    /** Les bornes que le champ déclare. Le planning en pose, la nouvelle demande non. */
    const bornes = champ => ({min: depuisIso(champ.min), max: depuisIso(champ.max)});
    const horsBornes = (d, b) => (!!b.min && d < b.min) || (!!b.max && d > b.max);

    /** La date qu'on ramène dans les bornes, pour n'ouvrir jamais sur un mois interdit. */
    const ramener = (d, b) => (b.min && d < b.min) ? b.min : ((b.max && d > b.max) ? b.max : d);

    /* ---------- L'habillage du champ ---------- */

    const estOrnable = champ => champ instanceof HTMLInputElement
        && champ.type === 'date'
        && champ.dataset.dateOrnee !== '1'
        && !champ.hasAttribute('data-date-natif');

    /** Un champ vide porte une invite, pas une valeur : son enveloppe le dit au style. */
    const direSiVide = champ => {
        const enveloppe = champ.closest('.ctrl-date');
        if (enveloppe) enveloppe.classList.toggle('est-vide', champ.value.trim() === '');
    };

    function orner(champ) {
        if (!estOrnable(champ)) return;
        champ.dataset.dateOrnee = '1';

        const enveloppe = document.createElement('div');
        enveloppe.className = 'ctrl-date';
        champ.parentNode.insertBefore(enveloppe, champ);
        enveloppe.appendChild(champ);

        const bouton = document.createElement('button');
        bouton.type = 'button';
        // « bouton-discret » est le marqueur que prefa-form.css prévoit pour refuser la
        // forme du bouton principal : sans lui, le déclencheur sortait peint en plein,
        // mesuré à rgb(184, 84, 51) avec sa glyphe en blanc — un bouton d'envoi au bout
        // d'un champ de date.
        bouton.className = 'ctrl-date-ouvrir bouton-discret';
        bouton.innerHTML = ICONE_CALENDRIER;
        // Hors de l'ordre de tabulation, et masqué aux lecteurs d'écran : c'est une
        // commodité pour la souris. Le champ natif reste le contrôle annoncé, et le clavier
        // ouvre le calendrier par Alt+Flèche bas — comme une liste déroulante. Un second
        // arrêt pour la même donnée aurait fait deux pas par date dans un formulaire qui
        // en compte deux.
        bouton.tabIndex = -1;
        bouton.setAttribute('aria-hidden', 'true');
        bouton.title = 'Ouvrir le calendrier';
        enveloppe.appendChild(bouton);

        direSiVide(champ);
    }

    const ornerSous = racine => {
        if (!racine || racine.nodeType !== 1) return;
        if (estOrnable(racine)) orner(racine);
        racine.querySelectorAll('input[type="date"]').forEach(orner);
    };

    /* ---------- Le calendrier ---------- */

    /** Le calendrier ouvert, ou null. */
    let ouvert = null;

    function construire() {
        const panneau = document.createElement('div');
        panneau.className = 'ctrl-calendrier';
        panneau.setAttribute('role', 'dialog');
        panneau.setAttribute('aria-modal', 'false');
        panneau.setAttribute('aria-label', 'Choisir une date');
        panneau.innerHTML =
            '<div class="ctrl-cal-tete">' +
            '<button type="button" class="ctrl-cal-fleche" data-pas="-1" aria-label="Mois précédent">' + chevron(true) + '</button>' +
            '<button type="button" class="ctrl-cal-periode" aria-live="polite"></button>' +
            '<button type="button" class="ctrl-cal-fleche" data-pas="1" aria-label="Mois suivant">' + chevron(false) + '</button>' +
            '</div>' +
            '<div class="ctrl-cal-corps"></div>' +
            '<div class="ctrl-cal-pied">' +
            '<button type="button" class="ctrl-cal-action" data-action="aujourdhui">Aujourd’hui</button>' +
            '<button type="button" class="ctrl-cal-action est-discrete" data-action="effacer">Effacer</button>' +
            '</div>';
        return panneau;
    }

    /** La grille des six semaines du mois où se trouve le curseur. */
    function dessinerJours(corps, curseur, choisi, b) {
        const premier = new Date(curseur.getFullYear(), curseur.getMonth(), 1);
        // getDay() rend 0 pour dimanche : on décale pour que lundi ouvre la semaine.
        const debut = plusJours(premier, -((premier.getDay() + 6) % 7));
        const hui = aujourdhui();

        const morceaux = ['<div class="ctrl-cal-grille" role="grid">'];
        JOURS.forEach(([court, long]) => {
            morceaux.push('<div class="ctrl-cal-jour-nom" role="columnheader" aria-label="' + long + '">' + court + '</div>');
        });

        // Six semaines toujours, même quand cinq suffisent : sans quoi le calendrier change
        // de hauteur d'un mois à l'autre, et le pied se déplace sous le curseur.
        for (let i = 0; i < 42; i++) {
            const jour = plusJours(debut, i);
            const classes = ['ctrl-cal-case'];
            if (jour.getMonth() !== curseur.getMonth()) classes.push('est-autre-mois');
            if (memeJour(jour, hui)) classes.push('est-aujourdhui');
            if (memeJour(jour, choisi)) classes.push('est-choisi');
            const interdit = horsBornes(jour, b);
            morceaux.push(
                '<button type="button" class="' + classes.join(' ') + '" role="gridcell"' +
                ' data-jour="' + enIso(jour) + '"' +
                (interdit ? ' disabled' : '') +
                ' tabindex="-1"' +
                ' aria-selected="' + (memeJour(jour, choisi) ? 'true' : 'false') + '"' +
                ' aria-label="' + jour.getDate() + ' ' + MOIS[jour.getMonth()] + ' ' + jour.getFullYear() + '">' +
                jour.getDate() + '</button>');
        }
        morceaux.push('</div>');
        corps.innerHTML = morceaux.join('');
    }

    /** Les douze mois de l'année du curseur : aller d'octobre à mars sans cinq clics. */
    function dessinerMois(corps, curseur, b) {
        const morceaux = ['<div class="ctrl-cal-mois">'];
        for (let m = 0; m < 12; m++) {
            // Un mois est interdit quand aucun de ses jours ne tombe dans les bornes.
            const debut = new Date(curseur.getFullYear(), m, 1);
            const fin = new Date(curseur.getFullYear(), m + 1, 0);
            const interdit = (!!b.min && fin < b.min) || (!!b.max && debut > b.max);
            morceaux.push(
                '<button type="button" class="ctrl-cal-mois-case' +
                (m === curseur.getMonth() ? ' est-choisi' : '') + '"' +
                ' data-mois="' + m + '"' + (interdit ? ' disabled' : '') + '>' +
                MOIS_COURTS[m] + '</button>');
        }
        morceaux.push('</div>');
        corps.innerHTML = morceaux.join('');
    }

    function dessiner() {
        if (!ouvert) return;
        const {panneau, champ, curseur, vue} = ouvert;
        const b = bornes(champ);
        const choisi = depuisIso(champ.value);
        const corps = panneau.querySelector('.ctrl-cal-corps');
        const periode = panneau.querySelector('.ctrl-cal-periode');

        if (vue === 'mois') {
            periode.textContent = String(curseur.getFullYear());
            periode.setAttribute('aria-label', 'Revenir aux jours de ' + curseur.getFullYear());
            dessinerMois(corps, curseur, b);
        } else {
            periode.textContent = MOIS[curseur.getMonth()] + ' ' + curseur.getFullYear();
            periode.setAttribute('aria-label', 'Choisir le mois');
            dessinerJours(corps, curseur, choisi, b);
        }

        // Une flèche qui ne mène nulle part se montre éteinte plutôt que de ne rien faire.
        panneau.querySelectorAll('.ctrl-cal-fleche').forEach(fleche => {
            const pas = +fleche.dataset.pas;
            const vise = vue === 'mois'
                ? new Date(curseur.getFullYear() + pas, pas > 0 ? 0 : 11, 1)
                : plusMois(curseur, pas);
            const dernier = new Date(vise.getFullYear(), vise.getMonth() + 1, 0);
            const premier = new Date(vise.getFullYear(), vise.getMonth(), 1);
            fleche.disabled = (!!b.min && dernier < b.min) || (!!b.max && premier > b.max);
            fleche.setAttribute('aria-label', vue === 'mois'
                ? (pas < 0 ? 'Année précédente' : 'Année suivante')
                : (pas < 0 ? 'Mois précédent' : 'Mois suivant'));
        });

        const hui = panneau.querySelector('[data-action="aujourdhui"]');
        hui.disabled = horsBornes(aujourdhui(), b);
        panneau.querySelector('[data-action="effacer"]').disabled = champ.value.trim() === '';
    }

    /** Le panneau se pose sous le champ, et au-dessus quand le bas de l'écran manque. */
    function placer() {
        if (!ouvert) return;
        const {panneau, champ} = ouvert;
        const marge = 8;
        const cadre = champ.getBoundingClientRect();
        const taille = panneau.getBoundingClientRect();

        let haut = cadre.bottom + 6;
        if (haut + taille.height > window.innerHeight - marge) {
            const dessus = cadre.top - taille.height - 6;
            // Au-dessus seulement s'il y a vraiment la place : sinon on reste dessous et on
            // laisse le panneau toucher le bas, ce qui vaut mieux que de sortir par le haut.
            if (dessus >= marge) haut = dessus;
            else haut = Math.max(marge, window.innerHeight - taille.height - marge);
        }
        let gauche = cadre.left;
        if (gauche + taille.width > window.innerWidth - marge) {
            gauche = Math.max(marge, cadre.right - taille.width);
        }
        panneau.style.top = Math.round(haut) + 'px';
        panneau.style.left = Math.round(Math.max(marge, gauche)) + 'px';
    }

    function ouvrir(champ) {
        if (ouvert && ouvert.champ === champ) { fermer(); return; }
        fermer();
        if (champ.disabled || champ.readOnly) return;

        const b = bornes(champ);
        const panneau = construire();
        // Dans une fenêtre modale, le calendrier se pose dans la fenêtre : une <dialog>
        // ouverte par showModal() vit dans la couche supérieure, et tout ce qui est posé
        // sur body passe dessous, quel que soit son z-index. Il reste en « fixed », donc
        // placé sur l'écran comme ailleurs.
        (champ.closest('dialog[open]') || document.body).appendChild(panneau);
        ouvert = {
            champ,
            panneau,
            // On ouvre sur le mois de la date saisie, sinon sur aujourd'hui, ramené dans
            // les bornes : un champ borné au mois prochain n'ouvre pas sur celui-ci.
            curseur: depuisIso(champ.value) || ramener(aujourdhui(), b),
            vue: 'jours',
        };
        dessiner();
        placer();

        // Le panneau suit le champ quand la page défile, au lieu de se fermer : on perdait
        // le calendrier au moindre coup de molette dans un formulaire qui dépasse l'écran.
        window.addEventListener('scroll', placer, true);
        window.addEventListener('resize', placer);
        document.addEventListener('pointerdown', surClicDehors, true);
        document.addEventListener('focusin', surFocusDehors, true);
    }

    function fermer(rendreLeFocus) {
        if (!ouvert) return;
        const champ = ouvert.champ;
        ouvert.panneau.remove();
        ouvert = null;
        window.removeEventListener('scroll', placer, true);
        window.removeEventListener('resize', placer);
        document.removeEventListener('pointerdown', surClicDehors, true);
        document.removeEventListener('focusin', surFocusDehors, true);
        if (rendreLeFocus) {
            try { champ.focus({preventScroll: true}); } catch (e) { champ.focus(); }
        }
    }

    const dedans = cible => !!ouvert && (ouvert.panneau.contains(cible)
        || ouvert.champ.closest('.ctrl-date').contains(cible));

    function surClicDehors(evenement) {
        if (!dedans(evenement.target)) fermer();
    }

    // Partir au clavier ferme aussi : sans quoi le panneau restait ouvert derrière le champ
    // suivant, et deux calendriers se seraient superposés.
    function surFocusDehors(evenement) {
        if (!dedans(evenement.target)) fermer();
    }

    /** Écrire la date choisie dans le champ natif, et le dire à qui écoute. */
    function choisir(jour) {
        if (!ouvert) return;
        const champ = ouvert.champ;
        champ.value = enIso(jour);
        direSiVide(champ);
        // Les deux événements : « input » pour la recoloration du champ après un envoi
        // refusé, « change » pour les écrans qui rechargent sur la date — le planning.
        champ.dispatchEvent(new Event('input', {bubbles: true}));
        champ.dispatchEvent(new Event('change', {bubbles: true}));
        fermer(true);
    }

    function effacer() {
        if (!ouvert) return;
        const champ = ouvert.champ;
        champ.value = '';
        direSiVide(champ);
        champ.dispatchEvent(new Event('input', {bubbles: true}));
        champ.dispatchEvent(new Event('change', {bubbles: true}));
        fermer(true);
    }

    /** Déplacer le curseur, et garder le focus sur la case où il arrive. */
    function bouger(nouveau) {
        if (!ouvert) return;
        const b = bornes(ouvert.champ);
        ouvert.curseur = ramener(nouveau, b);
        dessiner();
        placer();
        const case_ = ouvert.panneau.querySelector('[data-jour="' + enIso(ouvert.curseur) + '"]');
        if (case_) case_.focus();
    }

    /* ---------- Les gestes ---------- */

    document.addEventListener('click', evenement => {
        const declencheur = evenement.target.closest('.ctrl-date-ouvrir');
        if (declencheur) {
            evenement.preventDefault();
            const champ = declencheur.closest('.ctrl-date').querySelector('input[type="date"]');
            if (champ) ouvrir(champ);
            return;
        }
        if (!ouvert || !ouvert.panneau.contains(evenement.target)) return;

        const fleche = evenement.target.closest('.ctrl-cal-fleche');
        if (fleche) {
            const pas = +fleche.dataset.pas;
            ouvert.curseur = ouvert.vue === 'mois'
                ? plusMois(ouvert.curseur, pas * 12)
                : plusMois(ouvert.curseur, pas);
            dessiner();
            placer();
            return;
        }
        if (evenement.target.closest('.ctrl-cal-periode')) {
            ouvert.vue = ouvert.vue === 'mois' ? 'jours' : 'mois';
            dessiner();
            placer();
            return;
        }
        const mois = evenement.target.closest('.ctrl-cal-mois-case');
        if (mois) {
            ouvert.curseur = new Date(ouvert.curseur.getFullYear(), +mois.dataset.mois,
                                      Math.min(ouvert.curseur.getDate(), 28));
            ouvert.vue = 'jours';
            dessiner();
            placer();
            return;
        }
        const jour = evenement.target.closest('.ctrl-cal-case');
        if (jour && !jour.disabled) {
            choisir(depuisIso(jour.dataset.jour));
            return;
        }
        const action = evenement.target.closest('.ctrl-cal-action');
        if (action) {
            if (action.dataset.action === 'effacer') effacer();
            else choisir(aujourdhui());
        }
    });

    // Alt+Flèche bas ouvre, comme une liste déroulante : c'est le chemin du clavier, le
    // déclencheur étant réservé à la souris.
    document.addEventListener('keydown', evenement => {
        const champ = evenement.target;
        if (champ instanceof HTMLInputElement && champ.type === 'date'
            && champ.dataset.dateOrnee === '1'
            && evenement.altKey && evenement.key === 'ArrowDown') {
            evenement.preventDefault();
            ouvrir(champ);
            const premier = ouvert && ouvert.panneau.querySelector('[data-jour="' + enIso(ouvert.curseur) + '"]');
            if (premier) premier.focus();
            return;
        }

        if (!ouvert || !ouvert.panneau.contains(document.activeElement)) {
            if (ouvert && evenement.key === 'Escape') {
                // Échap ferme le calendrier, et lui seul : sans cela, la fenêtre qui porte
                // le champ se refermait du même coup.
                evenement.preventDefault();
                fermer(true);
            }
            return;
        }

        if (evenement.key === 'Escape') {
            evenement.preventDefault();
            fermer(true);
            return;
        }
        if (ouvert.vue !== 'jours') return;

        const pas = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7}[evenement.key];
        if (pas !== undefined) {
            evenement.preventDefault();
            bouger(plusJours(ouvert.curseur, pas));
            return;
        }
        if (evenement.key === 'PageUp' || evenement.key === 'PageDown') {
            evenement.preventDefault();
            const sens = evenement.key === 'PageUp' ? -1 : 1;
            bouger(plusMois(ouvert.curseur, sens * (evenement.shiftKey ? 12 : 1)));
            return;
        }
        if (evenement.key === 'Home' || evenement.key === 'End') {
            evenement.preventDefault();
            const jour = ouvert.curseur.getDay();
            const versLundi = -((jour + 6) % 7);
            bouger(plusJours(ouvert.curseur, evenement.key === 'Home' ? versLundi : versLundi + 6));
        }
    });

    // Taper dans le champ change son état vide, et une date saisie à la main doit se
    // retrouver dans le calendrier qu'on ouvre ensuite.
    document.addEventListener('input', evenement => {
        const champ = evenement.target;
        if (champ instanceof HTMLInputElement && champ.type === 'date') direSiVide(champ);
    });

    /* ---------- L'arrivée des écrans ---------- */

    const demarrer = () => {
        ornerSous(document.body);
        new MutationObserver(mutations => {
            mutations.forEach(mutation => mutation.addedNodes.forEach(ornerSous));
        }).observe(document.body, {childList: true, subtree: true});
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
