// La palette : Ctrl+K ouvre une recherche dans le menu, on tape, on entre.
//
// Elle ne connaît aucun écran. Les entrées sont lues dans la barre latérale au moment où
// l'on ouvre : la barre ne contient déjà que ce que le rôle a le droit de voir, et c'est
// le serveur qui l'a décidé. Dresser une liste ici, c'est écrire une seconde fois des
// droits qui existent — et se tromper le jour où l'un des deux change.
//
// Choisir une entrée ne navigue pas non plus : la palette clique le bouton de la barre, et
// laisse partir le chargement par le chemin habituel. Elle ne sait rien de htmx.
(() => {
    'use strict';

    const LOUPE =
        '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor"' +
        ' stroke-width="1.5" stroke-linecap="round" aria-hidden="true">' +
        '<circle cx="7.2" cy="7.2" r="4.2"/><path d="m10.4 10.4 3 3"/></svg>';

    /** La palette ouverte, ou null. */
    let palette = null;
    /** Ce qui avait le focus avant l'ouverture, pour le lui rendre. */
    let avant = null;
    /** Les entrées affichées, dans l'ordre de la liste. */
    let visibles = [];
    let curseur = 0;

    /* ---------- Les entrées ---------- */

    // « Préfa » doit trouver « préfabrication », et « demandes » doit se taper sans accent
    // comme avec : on compare sur une forme sans diacritiques, en minuscules.
    const aplatir = texte => String(texte || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();

    function entrees() {
        const barre = document.getElementById('left-panel');
        if (!barre) return [];
        return Array.from(barre.querySelectorAll('.nav-btn')).map(bouton => {
            // Le libellé est dans un span ; le compteur d'urgentes en est un aussi, et il
            // n'a rien à faire dans le nom de l'écran.
            const etiquette = bouton.querySelector('span:not(.nav-urgent-badge)');
            const rayon = bouton.closest('.nav-rayon');
            const titre = rayon && rayon.querySelector('.nav-rayon-titre');
            const icone = bouton.querySelector('svg');
            const libelle = (etiquette ? etiquette.textContent : bouton.textContent).trim();
            const famille = titre ? titre.textContent.trim() : '';
            return {
                bouton,
                libelle,
                famille,
                icone: icone ? icone.outerHTML : '',
                actuelle: bouton.getAttribute('aria-current') === 'page',
                cherchable: aplatir(libelle + ' ' + famille),
            };
        }).filter(e => e.libelle !== '');
    }

    /** Tous les mots tapés doivent se retrouver, dans n'importe quel ordre. */
    const correspond = (entree, mots) => mots.every(mot => entree.cherchable.includes(mot));

    /* ---------- Le panneau ---------- */

    function construire() {
        const boite = document.createElement('dialog');
        boite.className = 'palette';
        boite.setAttribute('aria-label', 'Recherche dans le menu');
        boite.innerHTML =
            '<div class="palette-champ">' +
            '<span class="palette-loupe">' + LOUPE + '</span>' +
            '<input type="text" class="palette-saisie" autocomplete="off" spellcheck="false"' +
            ' placeholder="Aller à…" aria-label="Chercher un écran"' +
            ' role="combobox" aria-expanded="true" aria-controls="palette-liste"' +
            ' aria-autocomplete="list">' +
            '<kbd class="palette-touche">Échap</kbd>' +
            '</div>' +
            '<ul class="palette-liste" id="palette-liste" role="listbox" aria-label="Écrans"></ul>' +
            '<p class="palette-vide" hidden>Aucun écran ne porte ce nom.</p>';
        return boite;
    }

    function dessiner(filtre) {
        const mots = aplatir(filtre).split(/\s+/).filter(Boolean);
        const toutes = entrees();
        visibles = mots.length ? toutes.filter(e => correspond(e, mots)) : toutes;
        if (curseur >= visibles.length) curseur = Math.max(0, visibles.length - 1);

        const liste = palette.querySelector('.palette-liste');
        liste.innerHTML = visibles.map((e, i) =>
            '<li class="palette-entree' + (i === curseur ? ' est-visee' : '') +
            (e.actuelle ? ' est-courante' : '') + '"' +
            ' role="option" id="palette-entree-' + i + '"' +
            ' aria-selected="' + (i === curseur ? 'true' : 'false') + '">' +
            '<span class="palette-icone">' + e.icone + '</span>' +
            '<span class="palette-libelle">' + e.libelle + '</span>' +
            (e.famille ? '<span class="palette-famille">' + e.famille + '</span>' : '') +
            '</li>').join('');

        palette.querySelector('.palette-vide').hidden = visibles.length > 0;
        const saisie = palette.querySelector('.palette-saisie');
        saisie.setAttribute('aria-activedescendant',
            visibles.length ? 'palette-entree-' + curseur : '');
        amener();
    }

    /** L'entrée visée reste dans la fenêtre quand on descend aux flèches. */
    function amener() {
        const vise = palette && palette.querySelector('.est-visee');
        if (vise && vise.scrollIntoView) vise.scrollIntoView({block: 'nearest'});
    }

    function viser(i) {
        if (!visibles.length) return;
        // La liste boucle : arrivé en bas, la flèche suivante repart en haut. Sur sept
        // entrées, buter contre le bord n'apporte rien.
        curseur = (i + visibles.length) % visibles.length;
        palette.querySelectorAll('.palette-entree').forEach((li, k) => {
            li.classList.toggle('est-visee', k === curseur);
            li.setAttribute('aria-selected', k === curseur ? 'true' : 'false');
        });
        palette.querySelector('.palette-saisie')
            .setAttribute('aria-activedescendant', 'palette-entree-' + curseur);
        amener();
    }

    function valider() {
        const choix = visibles[curseur];
        if (!choix) return;
        // On ferme avant de cliquer : le bouton de la barre prend le focus, et une boîte
        // modale encore ouverte le lui aurait repris.
        const bouton = choix.bouton;
        fermer();
        bouton.click();
    }

    /* ---------- Ouvrir, fermer ---------- */

    function ouvrir() {
        if (palette) { fermer(); return; }
        if (!document.getElementById('left-panel')) return;

        avant = document.activeElement;
        palette = construire();
        document.body.appendChild(palette);
        curseur = 0;
        dessiner('');

        // « showModal » plutôt qu'un calque à nous : le piège à focus, le fond et la touche
        // Échap viennent avec, et la boîte passe au-dessus de tout sans course au z-index.
        if (typeof palette.showModal === 'function') palette.showModal();
        else palette.setAttribute('open', '');

        palette.querySelector('.palette-saisie').focus();
        palette.addEventListener('close', rendreLeFocus);
    }

    function rendreLeFocus() {
        if (avant && avant.isConnected && avant.focus) {
            try { avant.focus({preventScroll: true}); } catch (e) { avant.focus(); }
        }
        avant = null;
    }

    function fermer() {
        if (!palette) return;
        const boite = palette;
        palette = null;
        visibles = [];
        boite.removeEventListener('close', rendreLeFocus);
        if (typeof boite.close === 'function' && boite.open) boite.close();
        boite.remove();
        rendreLeFocus();
    }

    /* ---------- Les gestes ---------- */

    // La loupe de la barre du haut ouvre la même palette. Délégué, parce que la barre
    // est reconstruite à chaque rechargement de la coquille.
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('#barre-recherche')) ouvrir();
    });

    document.addEventListener('keydown', evenement => {
        const k = evenement.key;

        // Ctrl+K, et Cmd+K sur un clavier Mac. On empêche le raccourci du navigateur, qui
        // amène la barre d'adresse.
        if ((evenement.ctrlKey || evenement.metaKey) && !evenement.altKey
            && (k === 'k' || k === 'K')) {
            evenement.preventDefault();
            ouvrir();
            return;
        }

        if (!palette) return;

        if (k === 'ArrowDown') {
            evenement.preventDefault();
            viser(curseur + 1);
        } else if (k === 'ArrowUp') {
            evenement.preventDefault();
            viser(curseur - 1);
        } else if (k === 'Home') {
            evenement.preventDefault();
            viser(0);
        } else if (k === 'End') {
            evenement.preventDefault();
            viser(visibles.length - 1);
        } else if (k === 'Enter') {
            evenement.preventDefault();
            valider();
        }
        // Échap : la boîte modale s'en charge, et l'événement « close » rend le focus.
    });

    document.addEventListener('input', evenement => {
        if (palette && evenement.target.classList.contains('palette-saisie')) {
            curseur = 0;
            dessiner(evenement.target.value);
        }
    });

    document.addEventListener('click', evenement => {
        if (!palette) return;
        const entree = evenement.target.closest('.palette-entree');
        if (entree) {
            curseur = Array.prototype.indexOf.call(
                palette.querySelectorAll('.palette-entree'), entree);
            valider();
            return;
        }
        // Un clic sur le fond : la cible est la boîte elle-même, le contenu étant dans ses
        // enfants. C'est la façon de distinguer le fond d'une boîte modale.
        if (evenement.target === palette) fermer();
    });

    document.addEventListener('mousemove', evenement => {
        if (!palette) return;
        const entree = evenement.target.closest && evenement.target.closest('.palette-entree');
        if (!entree) return;
        const i = Array.prototype.indexOf.call(
            palette.querySelectorAll('.palette-entree'), entree);
        if (i !== curseur) viser(i);
    });
})();
