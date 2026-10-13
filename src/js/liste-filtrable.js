// Une liste déroulante filtrable adossée à un identifiant.
//
// On tape pour chercher, on choisit à la souris ou au clavier, et l'identifiant retenu
// part dans un champ caché que le formulaire enverra. Une option « Autre » reste toujours
// proposée : c'est l'issue quand le catalogue ne contient pas ce qu'on cherche, et elle
// ouvre un champ de précision.
//
// Le formulaire de demande en avait deux exemplaires, la passivation et la matière, écrits
// mot pour mot pareils — à une faute de frappe près dans celui de la passivation, « index »
// au lieu de « idx », qui cassait sa flèche du haut pendant que l'autre marchait.
//
// Le composant se monte sur un préfixe et en déduit toute sa quincaillerie : le champ de
// saisie est #<préfixe>-search, la liste #<préfixe>-options, l'identifiant choisi
// [name="id_<préfixe>"]. C'est la convention que le balisage suivait déjà, elle n'a donc
// rien à changer.
//
// Tout passe par la délégation : le formulaire arrive par AJAX dans #content, et ses
// champs n'existent pas encore quand ce script se charge.
(() => {
    'use strict';

    /**
     * Monte le composant sur un préfixe.
     *
     * @param {string} prefixe             « passivation », « matiere »…
     * @param {{apresChoix?: (valeur: ?string) => void}} [reglages]
     *        apresChoix est appelé à chaque fois que l'identifiant bouge : avec la valeur
     *        choisie, ou avec null quand une nouvelle frappe annule le choix précédent.
     */
    function listeFiltrable(prefixe, reglages) {
        const apresChoix = (reglages || {}).apresChoix || (() => {});

        // Relus à chaque fois plutôt que retenus : le formulaire est réinjecté à chaque
        // ouverture de la page, et les éléments d'hier n'existent plus.
        const champ = () => document.getElementById(prefixe + '-search');
        const liste = () => document.getElementById(prefixe + '-options');
        const identifiant = () => document.querySelector('[name="id_' + prefixe + '"]');
        const blocAutre = () => document.getElementById(prefixe + '-autre-field');
        const champAutre = () => document.getElementById(prefixe + '-autre');

        // L'attribut hidden ne suffit pas : des règles de style posent un display qui le
        // court-circuite. On écrit donc les deux, comme le faisait le code d'origine.
        const montrer = (element, visible, affichage) => {
            if (!element) return;
            element.hidden = !visible;
            element.style.display = visible ? (affichage || 'block') : 'none';
        };

        function ouvrir() {
            const boite = liste();
            const saisie = champ();
            if (!boite || !saisie) return;
            montrer(boite, true);
            saisie.setAttribute('aria-expanded', 'true');
            filtrer(saisie.value);
        }

        function fermer() {
            const boite = liste();
            if (!boite) return;
            montrer(boite, false);
            const saisie = champ();
            if (saisie) saisie.setAttribute('aria-expanded', 'false');
        }

        function filtrer(valeur) {
            const boite = liste();
            if (!boite) return;
            let requete = (valeur || '').trim().toLowerCase();
            // « Autre » n'est pas une recherche : c'est le choix qu'on vient de faire, et
            // il ne doit pas vider la liste de tout le reste.
            if (requete === 'autre') requete = '';

            let visibles = 0;
            boite.querySelectorAll('[role="option"]:not(#' + prefixe + '-option-autre)').forEach(option => {
                const texte = (option.textContent || '').trim().toLowerCase();
                const correspond = requete === '' || texte.indexOf(requete) !== -1;
                montrer(option, correspond);
                if (correspond) visibles++;
            });

            montrer(boite.querySelector('.' + prefixe + '-no-results'), visibles === 0);
            // « Autre » ne se filtre jamais : c'est justement quand rien ne correspond
            // qu'il faut pouvoir le choisir.
            montrer(document.getElementById(prefixe + '-option-autre'), true);
        }

        function choisir(valeur, texte) {
            const saisie = champ();
            const id = identifiant();
            const bloc = blocAutre();
            const autre = champAutre();

            if (valeur === 'autre') {
                if (saisie) saisie.value = 'Autre';
                if (id) id.value = 'autre';
                montrer(bloc, true, 'flex');
                if (autre) {
                    autre.required = true;
                    // Ce qui venait d'être tapé devient la précision : on ne le fait pas
                    // retaper à l'identique.
                    const derniere = saisie ? saisie.dataset.lastQuery : '';
                    if (derniere && derniere.toLowerCase() !== 'autre') autre.value = derniere;
                    setTimeout(() => autre.focus(), 50);
                }
            } else {
                if (saisie) saisie.value = valeur ? texte : '';
                if (id) id.value = valeur || '';
                montrer(bloc, false);
                if (autre) {
                    autre.required = false;
                    autre.value = '';
                }
            }

            document.querySelectorAll('#' + prefixe + '-options [role="option"]').forEach(option => {
                option.setAttribute('aria-selected', option.dataset.value === valeur ? 'true' : 'false');
            });

            fermer();
            apresChoix(valeur);
        }

        const saisie = '#' + prefixe + '-search';
        $(document)
            .on('focus click', saisie, ouvrir)
            .on('input', saisie, function () {
                const tape = this.value.trim();
                if (tape && tape.toLowerCase() !== 'autre') this.dataset.lastQuery = tape;

                // Retaper annule le choix : l'identifiant ne doit pas survivre au texte qui
                // le désignait, sinon on enverrait une valeur que personne ne lit plus.
                const id = identifiant();
                if (!id || id.value !== 'autre' || tape.toLowerCase() !== 'autre') {
                    if (id) id.value = '';
                    montrer(blocAutre(), false);
                    const autre = champAutre();
                    if (autre) autre.required = false;
                    apresChoix(null);
                }
                ouvrir();
            })
            .on('click', '#' + prefixe + '-options [role="option"]', function (evenement) {
                evenement.stopPropagation();
                choisir(this.dataset.value, $(this).text().trim());
            })
            .on('click', '#' + prefixe + '-clear', function (evenement) {
                evenement.stopPropagation();
                choisir('', '');
                const saisie = champ();
                if (saisie) {
                    delete saisie.dataset.lastQuery;
                    saisie.focus();
                }
            })
            .on('keydown', saisie, function (evenement) {
                const options = $('#' + prefixe + '-options [role="option"]:visible');
                const active = options.filter('[aria-selected="true"]');

                if (evenement.key === 'Escape' || evenement.key === 'Tab') {
                    fermer();
                    return;
                }
                if (evenement.key === 'Enter') {
                    evenement.preventDefault();
                    // Rien de surligné et une seule option : c'est forcément celle-là.
                    // Rien de surligné et plusieurs : on ne devine pas, on propose « Autre ».
                    if (active.length) active.trigger('click');
                    else if (options.length === 1) options.trigger('click');
                    else $('#' + prefixe + '-option-autre').trigger('click');
                }
                if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
                    evenement.preventDefault();
                    ouvrir();
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
            .on('click', function (evenement) {
                if (!$(evenement.target).closest('.' + prefixe + '-select').length) fermer();
            });
    }

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

    listeFiltrable('passivation');

    // La matière commande ce second champ ; le composant, lui, n'a pas à connaître cette
    // règle. Le choix « Autre » ne donne pas encore de matière : rien à demander tant que
    // sa précision n'est pas saisie, d'où le second gestionnaire.
    listeFiltrable('matiere', {
        apresChoix: valeur => disponibiliteMatiere(valeur !== null && valeur !== 'autre'),
    });
    $(document).on('change', '#matiere-autre', function () {
        if (this.value.trim()) disponibiliteMatiere(true);
    });
})();
