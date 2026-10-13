// Le choix du rôle, sur la page de création de compte : on tape pour chercher, on choisit
// à la souris ou au clavier, et l'identifiant du rôle part dans un champ caché.
//
// C'est le même besoin que la passivation et la matière du formulaire de demande, mais
// avec un autre balisage : ici la liste s'ouvre par une classe « active » et les options
// se cachent par une classe « hidden », là-bas par l'attribut hidden et un display. Les
// faire converger sur liste-filtrable.js demande de reprendre ce balisage et ses règles de
// style ; tant que ce n'est pas fait, cette page garde son propre code — mais il ne vit
// plus dans le PHP.
//
// Délégation : la page arrive par AJAX dans #content.
(() => {
    'use strict';

    const liste = () => $('#role-list');

    function ouvrir(champ) {
        liste().addClass('active');
        $(champ).attr('aria-expanded', 'true');
    }

    function fermer() {
        liste().removeClass('active');
        $('#role-search').attr('aria-expanded', 'false');
    }

    function choisir(option) {
        $('#role-search').val(option.text().trim()).attr('aria-expanded', 'false');
        $('#role').val(option.data('value'));
        liste().removeClass('active');
        $('.role-option').removeClass('selected');
        option.addClass('selected');
    }

    /** Surligne l'option parcourue au clavier et la ramène dans la liste visible. */
    function surligner(options, rang) {
        $('.role-option').removeClass('selected');
        const option = options.eq(rang);
        if (!option.length) return;
        option.addClass('selected');
        option[0].scrollIntoView({ block: 'nearest' });
    }

    $(document)
        .on('focus', '#role-search', function () {
            ouvrir(this);
        })
        .on('input', '#role-search', function () {
            const recherche = $(this).val().toLowerCase().trim();
            $('.role-option').each(function () {
                $(this).toggleClass('hidden', !$(this).text().toLowerCase().includes(recherche));
            });
            ouvrir(this);
            // Le parcours clavier repart de zéro : les options d'avant ne sont plus les
            // mêmes, et garder leur rang ferait sauter la sélection n'importe où.
            $(this).data('selected-index', -1);
        })
        .on('click', '.role-option', function () {
            choisir($(this));
        })
        .on('keydown', '#role-search', function (evenement) {
            const champ = $(this);
            const options = $('.role-option:not(.hidden)');
            let rang = champ.data('selected-index') ?? -1;

            if (evenement.key === 'ArrowDown') {
                evenement.preventDefault();
                rang = rang + 1 >= options.length ? 0 : rang + 1;
                surligner(options, rang);
            }
            if (evenement.key === 'ArrowUp') {
                evenement.preventDefault();
                rang = rang - 1 < 0 ? options.length - 1 : rang - 1;
                surligner(options, rang);
            }
            if (evenement.key === 'Enter' && rang >= 0 && options.eq(rang).length) {
                evenement.preventDefault();
                choisir(options.eq(rang));
            }
            if (evenement.key === 'Escape') fermer();

            champ.data('selected-index', rang);
        })
        .on('click', function (evenement) {
            if (!$(evenement.target).closest('.role-select').length) fermer();
        });
})();
