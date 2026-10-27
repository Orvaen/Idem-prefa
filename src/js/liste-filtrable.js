// Le champ « liste filtrable » : on tape pour chercher, on choisit à la souris ou au
// clavier, et l'identifiant retenu part dans un champ caché. Une option « Autre » peut
// ouvrir un champ de précision.
//
// Rien ne se déclare ici. Le balisage, rendu par includes/liste_filtrable.php, se reconnaît
// à son attribut data-liste-filtrable, et les gestionnaires retrouvent leur champ en
// remontant depuis l'élément touché. Un quatrième champ filtrable n'aura donc pas une ligne
// à ajouter dans ce fichier — c'était le cas avant, et c'est ce qui faisait qu'on recopiait
// quatre-vingt-dix lignes pour en obtenir un de plus.
//
// Délégation : le formulaire arrive par AJAX dans #content, ses champs n'existent pas
// encore quand ce script se charge.
(() => {
    'use strict';

    /** Ce que certains champs commandent en plus, par préfixe. Le reste n'en sait rien. */
    const APRES_CHOIX = {
        // Demander une matière, c'est devoir dire pour quand on la veut.
        matiere: valeur => disponibiliteMatiere(valeur !== null && valeur !== 'autre'),
    };

    const champFiltrable = cible => (cible && cible.closest ? cible.closest('[data-liste-filtrable]') : null);

    // L'attribut hidden ne suffit pas : des règles de style posent un display qui le
    // court-circuite. On écrit donc les deux, comme le faisait le code d'origine.
    const montrer = (element, visible, affichage) => {
        if (!element) return;
        element.hidden = !visible;
        element.style.display = visible ? (affichage || 'block') : 'none';
    };

    /** Les morceaux d'un champ. Le bloc « Autre » vit dehors : il prend toute la largeur. */
    function morceaux(filtrable) {
        const prefixe = filtrable.dataset.listeFiltrable;
        return {
            prefixe,
            saisie: filtrable.querySelector('.liste-filtrable-champ'),
            liste: filtrable.querySelector('.liste-filtrable-options'),
            identifiant: filtrable.querySelector('input[type="hidden"]'),
            autre: filtrable.querySelector('.liste-filtrable-autre'),
            aucun: filtrable.querySelector('.liste-filtrable-vide'),
            blocPrecision: document.getElementById(prefixe + '-autre-field'),
            precision: document.getElementById(prefixe + '-autre'),
        };
    }

    const prevenir = (prefixe, valeur) => (APRES_CHOIX[prefixe] || (() => {}))(valeur);

    /**
     * Pose le volet d'un champ de tableau.
     *
     * Posé en absolu, il se faisait couper par le conteneur du tableau, qui défile : un
     * élément en position absolue reste dans la zone découpée de son ancêtre. En position
     * fixe il en sort — mais il faut alors lui donner ses coordonnées, et les refaire
     * quand la page bouge.
     */
    function placerVolet(filtrable) {
        if (!filtrable.classList.contains('liste-filtrable-tableau')) return;
        const { saisie, liste } = morceaux(filtrable);
        if (!saisie || !liste || liste.hidden) return;

        const repere = saisie.getBoundingClientRect();
        const hauteur = Math.min(liste.scrollHeight, 220);
        const dessous = window.innerHeight - repere.bottom;

        liste.style.position = 'fixed';
        liste.style.right = 'auto';
        liste.style.left = repere.left + 'px';
        liste.style.width = Math.max(repere.width, 200) + 'px';
        // Le volet s'ouvre vers le bas, sauf s'il n'y a pas la place et qu'il y en a
        // au-dessus : sur la dernière ligne d'un tableau, il sortait de l'écran.
        liste.style.top = (dessous < hauteur + 8 && repere.top > dessous
            ? repere.top - hauteur - 4
            : repere.bottom + 4) + 'px';
    }

    function ouvrir(filtrable) {
        const { saisie, liste, identifiant } = morceaux(filtrable);
        if (!saisie || !liste) return;
        montrer(liste, true);
        saisie.setAttribute('aria-expanded', 'true');
        // Un champ déjà renseigné s'ouvrait filtré sur sa propre valeur : il ne proposait
        // donc que ce qui était déjà choisi, et il fallait le vider pour pouvoir en
        // changer. Rouvrir, c'est revoir tout le choix.
        filtrer(filtrable, identifiant && identifiant.value !== '' ? '' : saisie.value);
        placerVolet(filtrable);
    }

    // La page défile sous le volet : il le suit. En capture, parce que le défilement d'un
    // conteneur ne remonte pas jusqu'à window.
    const replacerTout = () => document.querySelectorAll('.liste-filtrable-tableau')
        .forEach(placerVolet);
    document.addEventListener('scroll', replacerTout, true);
    window.addEventListener('resize', replacerTout);

    function fermer(filtrable) {
        const { saisie, liste } = morceaux(filtrable);
        montrer(liste, false);
        if (saisie) saisie.setAttribute('aria-expanded', 'false');
    }

    function filtrer(filtrable, valeur) {
        const { liste, autre, aucun } = morceaux(filtrable);
        if (!liste) return;

        let requete = (valeur || '').trim().toLowerCase();
        // « Autre » n'est pas une recherche : c'est le choix qu'on vient de faire, et il ne
        // doit pas vider la liste de tout le reste.
        if (requete === 'autre') requete = '';

        let visibles = 0;
        liste.querySelectorAll('[role="option"]').forEach(option => {
            if (option === autre) return;
            const texte = (option.textContent || '').trim().toLowerCase();
            const correspond = requete === '' || texte.indexOf(requete) !== -1;
            montrer(option, correspond);
            if (correspond) visibles++;
        });

        montrer(aucun, visibles === 0);
        // « Autre » ne se filtre jamais : c'est justement quand rien ne correspond qu'il
        // faut pouvoir le choisir.
        montrer(autre, true);
    }

    function choisir(filtrable, valeur, texte) {
        const { prefixe, saisie, identifiant, blocPrecision, precision, liste } = morceaux(filtrable);

        if (valeur === 'autre') {
            if (saisie) saisie.value = 'Autre';
            if (identifiant) identifiant.value = 'autre';
            montrer(blocPrecision, true, 'flex');
            if (precision) {
                precision.required = true;
                // Ce qui venait d'être tapé devient la précision : on ne le fait pas retaper.
                const derniere = saisie ? saisie.dataset.lastQuery : '';
                if (derniere && derniere.toLowerCase() !== 'autre') precision.value = derniere;
                setTimeout(() => precision.focus(), 50);
            }
        } else {
            if (saisie) saisie.value = valeur ? texte : '';
            if (identifiant) identifiant.value = valeur || '';
            montrer(blocPrecision, false);
            if (precision) {
                precision.required = false;
                precision.value = '';
            }
        }

        if (liste) {
            liste.querySelectorAll('[role="option"]').forEach(option => {
                option.setAttribute('aria-selected', option.dataset.value === valeur ? 'true' : 'false');
            });
        }

        fermer(filtrable);
        prevenir(prefixe, valeur);

        // Le champ caché est écrit à la main : personne hors du composant ne saurait qu'il
        // a changé. L'écran des affaires s'en sert pour marquer la ligne comme modifiée.
        if (identifiant) identifiant.dispatchEvent(new Event('change', { bubbles: true }));
    }

    $(document)
        .on('focus click', '.liste-filtrable-champ', function () {
            ouvrir(champFiltrable(this));
        })
        .on('input', '.liste-filtrable-champ', function () {
            const filtrable = champFiltrable(this);
            const { prefixe, identifiant, blocPrecision, precision } = morceaux(filtrable);
            const tape = this.value.trim();
            if (tape && tape.toLowerCase() !== 'autre') this.dataset.lastQuery = tape;

            // Retaper annule le choix : l'identifiant ne doit pas survivre au texte qui le
            // désignait, sinon on enverrait une valeur que personne ne lit plus.
            if (!identifiant || identifiant.value !== 'autre' || tape.toLowerCase() !== 'autre') {
                if (identifiant) identifiant.value = '';
                montrer(blocPrecision, false);
                if (precision) precision.required = false;
                prevenir(prefixe, null);
            }
            ouvrir(filtrable);
        })
        .on('click', '.liste-filtrable-options [role="option"]', function (evenement) {
            evenement.stopPropagation();
            choisir(champFiltrable(this), this.dataset.value, $(this).text().trim());
        })
        .on('click', '.liste-filtrable-effacer', function (evenement) {
            evenement.stopPropagation();
            const filtrable = champFiltrable(this);
            choisir(filtrable, '', '');
            const { saisie } = morceaux(filtrable);
            if (saisie) {
                delete saisie.dataset.lastQuery;
                saisie.focus();
            }
        })
        .on('keydown', '.liste-filtrable-champ', function (evenement) {
            const filtrable = champFiltrable(this);
            const { liste, autre } = morceaux(filtrable);
            const options = $(liste).find('[role="option"]:visible');
            const active = options.filter('[aria-selected="true"]');

            if (evenement.key === 'Escape' || evenement.key === 'Tab') {
                // Une liste ouverte se ferme seule sur Échap : la fenêtre qui la porte ne
                // doit pas se refermer avec elle.
                if (evenement.key === 'Escape' && this.getAttribute('aria-expanded') === 'true') evenement.preventDefault();
                fermer(filtrable);
                return;
            }
            if (evenement.key === 'Enter') {
                evenement.preventDefault();
                // Rien de surligné et une seule option : c'est forcément celle-là. Rien de
                // surligné et plusieurs : on ne devine pas, on propose « Autre » s'il existe.
                if (active.length) active.trigger('click');
                else if (options.length === 1) options.trigger('click');
                else if (autre) $(autre).trigger('click');
            }
            if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
                evenement.preventDefault();
                ouvrir(filtrable);
                if (!options.length) return;
                let rang = options.index(active);
                rang = evenement.key === 'ArrowDown'
                    ? (rang + 1) % options.length
                    : (rang <= 0 ? options.length - 1 : rang - 1);
                options.attr('aria-selected', 'false');
                const option = options.eq(rang).attr('aria-selected', 'true');
                if (option[0]) option[0].scrollIntoView({ block: 'nearest' });
            }
        })
        // Un clic ailleurs referme les champs ouverts — mais pas celui qu'on vient de toucher.
        .on('click', function (evenement) {
            const touche = champFiltrable(evenement.target);
            document.querySelectorAll('[data-liste-filtrable]').forEach(filtrable => {
                if (filtrable !== touche) fermer(filtrable);
            });
        });

    /**
     * Demander une matière, c'est devoir dire pour quand on la veut : le champ de
     * disponibilité n'apparaît et n'est exigé que s'il y a une matière.
     */
    function disponibiliteMatiere(focus) {
        const matiere = document.querySelector('[name="id_matiere"]');
        const disponibilite = document.getElementById('matiere-disponibilite');
        const marque = document.getElementById('matiere-disponibilite-required');
        const bloc = document.getElementById('matiere-disponibilite-field');
        const exigee = Boolean(matiere && matiere.value);

        if (bloc) bloc.hidden = !exigee;
        if (marque) marque.hidden = !exigee;
        if (!disponibilite) return;
        disponibilite.required = exigee;
        if (!exigee) disponibilite.value = '';
        if (focus && exigee) disponibilite.focus();
    }

    // Le choix « Autre » ne donne pas encore de matière : rien à demander tant que sa
    // précision n'est pas saisie.
    $(document).on('change', '#matiere-autre', function () {
        if (this.value.trim()) disponibiliteMatiere(true);
    });
})();
