// Tableaux de saisie d'un plan ISO : nomenclature de matériel et liste des soudures.
// Même geste pour les deux — on remplit le tableau d'un bloc, puis on enregistre —
// donc un seul code, piloté par les attributs du bloc : data-action, data-plan, data-csrf.
(() => {
    function lignes(bloc) {
        return Array.from(bloc.querySelectorAll('tbody tr.tableau-ligne'));
    }

    function message(bloc, texte, erreur) {
        const zone = bloc.querySelector('.nomenclature-message');
        if (!zone) return;
        zone.textContent = texte;
        zone.classList.toggle('is-error', !!erreur);
    }

    // Les rangs affichés suivent la position dans le tableau, pas l'ordre de création.
    function renumeroter(bloc) {
        lignes(bloc).forEach((tr, index) => {
            const rang = tr.querySelector('.nomenclature-rang');
            if (rang) rang.textContent = index + 1;
        });
    }

    function vider(tr) {
        tr.querySelectorAll('input').forEach(champ => { champ.value = ''; });
        tr.querySelectorAll('select').forEach(liste => {
            // Une liste peut désigner son choix par défaut : le soudeur affecté au plan
            // s'y retrouve présélectionné, plutôt que de laisser la case vide.
            const defaut = liste.querySelector('option[data-defaut="1"]');
            liste.selectedIndex = defaut ? defaut.index : 0;
        });
        const libre = tr.querySelector('input[name="coulee"]');
        if (libre) libre.hidden = true;
    }

    /**
     * Une ligne vierge, prête à être ajoutée au tableau.
     *
     * Le moule du bloc s'il en a un — la liste des soudures en fournit un, parce que sa
     * première ligne vient souvent d'un pointage et qu'en la copiant on emporterait son
     * soudeur et son rattachement. Sinon, la première ligne vidée fait l'affaire.
     */
    function ligneNeuve(bloc) {
        const moule = bloc.querySelector('template.soudure-modele');
        if (moule) return moule.content.firstElementChild.cloneNode(true);
        const modele = lignes(bloc)[0];
        if (!modele) return null;
        const copie = modele.cloneNode(true);
        vider(copie);
        return copie;
    }

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.tableau-ajouter');
        if (!bouton) return;
        const bloc = bouton.closest('.tableau-editable');
        const corps = (lignes(bloc)[0] || {}).parentNode || bloc.querySelector('tbody');
        const copie = ligneNeuve(bloc);
        if (!corps || !copie) return;
        corps.appendChild(copie);
        renumeroter(bloc);
        const premier = copie.querySelector('input, select');
        if (premier) premier.focus();
    });

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.tableau-retirer');
        if (!bouton) return;
        const bloc = bouton.closest('.tableau-editable');
        const tr = bouton.closest('tr');
        // On garde toujours une ligne : elle sert de modèle pour les suivantes.
        if (lignes(bloc).length === 1) vider(tr); else tr.remove();
        renumeroter(bloc);
    });

    // Nomenclature : « Saisir un autre numéro » découvre le champ libre de coulée.
    document.addEventListener('change', evenement => {
        const liste = evenement.target.closest('select[name="coulee_liste"]');
        if (!liste) return;
        const champ = liste.closest('td').querySelector('input[name="coulee"]');
        if (liste.value === '__autre') {
            champ.hidden = false;
            champ.value = '';
            champ.focus();
        } else {
            champ.hidden = true;
            champ.value = liste.value;
        }
    });

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.tableau-enregistrer');
        if (!bouton) return;
        const bloc = bouton.closest('.tableau-editable');
        if (bouton.disabled) return;
        bouton.disabled = true;
        message(bloc, 'Enregistrement…', false);

        const donnees = new URLSearchParams();
        donnees.set('csrf', bloc.dataset.csrf || '');
        donnees.set('plan', bloc.dataset.plan);
        // La feuille, et la page dans la feuille : un élément porte plusieurs ISO, et un
        // cahier déposé d'un bloc porte une liste soudure par page. Sans les dire,
        // l'enregistrement retombait sur la première feuille et écrasait sa liste.
        if (bloc.dataset.document) donnees.set('document', bloc.dataset.document);
        if (bloc.dataset.page) donnees.set('page', bloc.dataset.page);
        // L'empreinte dit sur quel état ce formulaire a été bâti : le serveur refuse
        // d'écrire si la liste a changé entre-temps.
        donnees.set('empreinte', bloc.dataset.empreinte || '');
        lignes(bloc).forEach((tr, index) => {
            tr.querySelectorAll('[name]').forEach(champ => {
                // Les listes d'aide ne partent pas : c'est le champ qu'elles remplissent
                // qui porte la valeur, saisie libre comprise.
                if (champ.name.endsWith('_liste')) return;
                // Une case à cocher porte sa valeur même décochée : c'est son état qu'il
                // faut lire, pas son contenu.
                const valeur = champ.type === 'checkbox'
                    ? (champ.checked ? champ.value : '')
                    : champ.value.trim();
                donnees.set(`lignes[${index}][${champ.name}]`, valeur);
            });
        });

        fetch(bloc.dataset.action, { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                // Après un enregistrement réussi la page devient la référence : on adopte
                // la nouvelle empreinte pour pouvoir enchaîner les saisies.
                if (ok && corps.empreinte) bloc.dataset.empreinte = corps.empreinte;
                message(bloc, corps.message || (ok ? 'Enregistré.' : 'Enregistrement impossible.'), !ok);
            })
            .catch(() => message(bloc, 'Enregistrement impossible. Réessayez.', true))
            .finally(() => { bouton.disabled = false; });
    });
})();
