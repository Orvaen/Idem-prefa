// Suggestions sous un champ libre : la liste s'ouvre au focus, montre tout, se resserre
// à la frappe, et se choisit à la souris comme au clavier.
//
// Elle remplace la liste native d'un <datalist>, qui ne s'ouvre pas au focus, ne propose
// plus rien dès que le champ contient une valeur — elle filtre sur elle, donc un champ qui
// vaut « OK » ne montre plus que « OK » — et ne se met pas aux couleurs du tableau de bord.
//
// Le <datalist> reste la source des suggestions. Le champ le désigne par
// data-suggestions="id" et non par list= : avec list=, le navigateur ouvrirait sa propre
// liste par-dessus celle-ci.
//
// Tout passe par la délégation : les volets arrivent par AJAX, leurs champs n'existent pas
// encore quand ce script se charge.
(() => {
    const CHAMP = 'input[data-suggestions]';
    let ouverte = null;

    const champDe = cible => (cible && cible.closest ? cible.closest(CHAMP) : null);

    function suggestions(champ) {
        const source = document.getElementById(champ.dataset.suggestions);
        if (!source) return [];
        return Array.from(source.querySelectorAll('option'))
            .map(option => option.value)
            .filter(valeur => valeur !== '');
    }

    function fermer() {
        if (!ouverte) return;
        ouverte.champ.setAttribute('aria-expanded', 'false');
        ouverte.champ.removeAttribute('aria-activedescendant');
        ouverte.liste.remove();
        ouverte = null;
    }

    function placer() {
        if (!ouverte) return;
        const { champ, liste } = ouverte;
        // Le volet a pu être remplacé entre-temps : une liste sans son champ s'en va.
        if (!champ.isConnected) { fermer(); return; }
        const cadre = champ.getBoundingClientRect();
        if (cadre.bottom < 0 || cadre.top > window.innerHeight) { fermer(); return; }

        liste.style.minWidth = Math.max(cadre.width, 112) + 'px';
        liste.style.left = Math.min(cadre.left, window.innerWidth - liste.offsetWidth - 8) + 'px';
        // Sous le champ s'il y a la place, au-dessus sinon : au bas d'un long volet, une
        // liste ouverte vers le bas sortirait de l'écran.
        const hauteur = liste.offsetHeight;
        const dessous = window.innerHeight - cadre.bottom;
        liste.style.top = (dessous >= hauteur + 8 || dessous >= cadre.top
            ? cadre.bottom + 4
            : cadre.top - hauteur - 4) + 'px';
    }

    /** Ouvre la liste du champ, ou la met à jour : tout, ou ce qui contient le filtre. */
    function afficher(champ, filtre) {
        const toutes = suggestions(champ);
        const cherche = filtre.trim().toLowerCase();
        const retenues = cherche === ''
            ? toutes
            : toutes.filter(valeur => valeur.toLowerCase().includes(cherche));

        // Rien ne correspond : la liste s'efface. La saisie libre reste permise, elle n'a
        // pas à être commentée à chaque lettre tapée.
        if (!retenues.length) { fermer(); return; }

        if (!ouverte || ouverte.champ !== champ) {
            fermer();
            const liste = document.createElement('ul');
            liste.className = 'suggestions-liste';
            liste.setAttribute('role', 'listbox');
            liste.id = 'suggestions-' + Math.random().toString(36).slice(2, 10);
            // Dans une fenêtre modale, la liste doit vivre dans la fenêtre : posée sur la
            // page, elle passerait sous elle.
            (champ.closest('dialog[open]') || document.body).appendChild(liste);
            champ.setAttribute('aria-controls', liste.id);
            ouverte = { champ, liste };
        }

        const liste = ouverte.liste;
        liste.innerHTML = '';
        retenues.forEach((valeur, rang) => {
            const option = document.createElement('li');
            option.setAttribute('role', 'option');
            option.id = liste.id + '-' + rang;
            option.dataset.valeur = valeur;
            option.textContent = valeur;
            liste.appendChild(option);
        });
        champ.setAttribute('aria-expanded', 'true');

        // La valeur que porte déjà le champ est désignée d'emblée.
        const actuelle = retenues.findIndex(valeur => valeur.toLowerCase() === champ.value.trim().toLowerCase());
        marquer(actuelle >= 0 ? liste.children[actuelle] : null);
        placer();
    }

    function marquer(option) {
        if (!ouverte) return;
        const { champ, liste } = ouverte;
        liste.querySelectorAll('.est-active').forEach(autre => autre.classList.remove('est-active'));
        if (!option) {
            champ.removeAttribute('aria-activedescendant');
            return;
        }
        option.classList.add('est-active');
        champ.setAttribute('aria-activedescendant', option.id);
        const haut = option.offsetTop;
        const bas = haut + option.offsetHeight;
        if (haut < liste.scrollTop) liste.scrollTop = haut;
        else if (bas > liste.scrollTop + liste.clientHeight) liste.scrollTop = bas - liste.clientHeight;
    }

    function choisir(valeur) {
        if (!ouverte) return;
        const champ = ouverte.champ;
        champ.value = valeur;
        fermer();
        // Les autres scripts apprennent le changement comme si on l'avait tapé.
        champ.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.addEventListener('focusin', evenement => {
        const champ = champDe(evenement.target);
        if (champ) afficher(champ, '');
    });

    document.addEventListener('input', evenement => {
        const champ = champDe(evenement.target);
        if (champ) afficher(champ, champ.value);
    });

    document.addEventListener('focusout', evenement => {
        if (ouverte && evenement.target === ouverte.champ) fermer();
    });

    // Le champ garde la main quand on pointe la liste : la perdre fermerait la liste avant
    // que le clic n'arrive sur la suggestion.
    document.addEventListener('mousedown', evenement => {
        if (ouverte && ouverte.liste.contains(evenement.target)) evenement.preventDefault();
    });

    document.addEventListener('click', evenement => {
        const option = evenement.target.closest && evenement.target.closest('.suggestions-liste [role="option"]');
        if (option && ouverte && ouverte.liste.contains(option)) {
            choisir(option.dataset.valeur);
            return;
        }
        // Un champ déjà focalisé ne repasse pas par focusin : un clic après un choix, ou
        // après Échap, doit pourtant rouvrir la liste.
        const champ = champDe(evenement.target);
        if (champ && (!ouverte || ouverte.champ !== champ)) afficher(champ, '');
    });

    document.addEventListener('keydown', evenement => {
        const champ = champDe(evenement.target);
        if (!champ) return;

        if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
            evenement.preventDefault();
            if (!ouverte || ouverte.champ !== champ) {
                afficher(champ, '');
                return;
            }
            const options = Array.from(ouverte.liste.children);
            const rang = options.findIndex(option => option.classList.contains('est-active'));
            const suivant = evenement.key === 'ArrowDown'
                ? (rang + 1) % options.length
                : (rang <= 0 ? options.length - 1 : rang - 1);
            marquer(options[suivant]);
            return;
        }

        if (!ouverte || ouverte.champ !== champ) return;
        if (evenement.key === 'Enter') {
            // Une suggestion désignée l'emporte ; sinon ce qui est tapé reste tel quel.
            evenement.preventDefault();
            const active = ouverte.liste.querySelector('.est-active');
            if (active) choisir(active.dataset.valeur); else fermer();
            return;
        }
        if (evenement.key === 'Escape') {
            // Échap ferme la liste et rien d'autre : ni le volet latéral ni une fenêtre
            // ouverte, qui écoutent eux aussi cette touche.
            evenement.preventDefault();
            evenement.stopImmediatePropagation();
            fermer();
        }
    });

    // Un défilement ou un redimensionnement déplace le champ : la liste le suit.
    window.addEventListener('scroll', placer, true);
    window.addEventListener('resize', placer);
})();
