$(function () {

    let isResizing = false;

    const container = $('#contenaire');
    const left = $('#left-panel');
    const drag = $('#drag'); // <-- il manquait celui-ci

    drag.on('mousedown', function (e) {

        isResizing = true;
        drag.addClass('is-resizing');

        e.preventDefault();
    });

    $(document).on('mousemove', function (e) {

        if (!isResizing) {
            return;
        }

        const containerLeft = container.offset().left;
        const newWidth = e.clientX - containerLeft;

        const minWidth = 150;
        const maxWidth = 600;

        if (newWidth >= minWidth && newWidth <= maxWidth) {
            left.css('width', newWidth + 'px');
        }
    });

    $(document).on('mouseup', function () {

        isResizing = false;
        drag.removeClass('is-resizing');

    });

});

// Chaque bouton porte l'adresse de sa page, posée par le serveur depuis la déclaration du
// menu. Ce fichier tenait sa propre table d'identifiants : il fallait l'ouvrir pour savoir
// ce qu'un bouton ouvrait, et la compléter en ajoutant une entrée — ce qu'on oubliait.
//
// Délégation sur la barre : les entrées dépendent du rôle, et il n'y a plus de liste de
// sélecteurs à tenir à jour.
// Les tuiles de l'accueil ouvrent l'écran où l'on agit sur le chiffre qu'elles montrent.
// Elles passent par le bouton du menu plutôt que de charger la page elles-mêmes : la
// rubrique se marque ainsi comme active, exactement comme si on l'avait cliquée.
$(document).on('click', '.accueil-tuile[data-ouvre]', function () {
    document.getElementById(this.dataset.ouvre)?.click();
});

// L'écran ouvert est inscrit dans l'adresse, sous ?vue=<identifiant de l'entrée>.
//
// Sans cela, la page ne changeait jamais d'adresse : un rafraîchissement — ou un lien qu'on
// envoie à un collègue — ramenait toujours à l'accueil. Les boutons Précédent et Suivant du
// navigateur ne servaient à rien non plus, ou pire : ils quittaient l'application.
//
// C'est l'identifiant de l'entrée du menu qu'on écrit, pas l'adresse de la page : la liste
// des demandes et les demandes urgentes ouvrent le même fichier avec un filtre, et il faut
// pouvoir les distinguer.
function vueDeLAdresse() {
    return new URLSearchParams(window.location.search).get('vue');
}

function ouvrirVue(bouton, { historique = 'pousser' } = {}) {
    const page = bouton.dataset.page;
    if (!page) return;

    $('#content').load(page, function (response, status, xhr) {
        if (status === 'error') {
            const message = xhr?.status === 401
                ? 'Votre session a expiré. Reconnectez-vous puis réessayez.'
                : 'Impossible de charger la page. Rechargez le tableau de bord puis réessayez.';
            $('#content').text(message);
            return;
        }

        $('.nav-header .nav-btn').removeClass('is-active').removeAttr('aria-current');
        $(bouton).addClass('is-active').attr('aria-current', 'page');

        if (historique !== 'aucun') {
            const vue = bouton.id.replace(/^btn-/, '');
            const adresse = window.location.pathname + '?vue=' + encodeURIComponent(vue);
            // « remplacer » au premier affichage : restaurer la vue d'une adresse ne doit pas
            // ajouter une entrée, sinon Précédent ne fait que revenir sur place.
            history[historique === 'remplacer' ? 'replaceState' : 'pushState']({ vue }, '', adresse);
        }

        const urgentTotal = document.getElementById('prefa-urgent-total');
        if (urgentTotal) updateUrgentBadge(Number(urgentTotal.dataset.count));
        if (window.matchMedia('(max-width: 900px)').matches) {
            setSidebar(false);
        }
        htmx.process(document.getElementById('content'));
        $('#create-user-form [name="name"]').trigger('input');
    });
}

$(document).on('click', '.nav-header .nav-btn[data-page]', function () {
    ouvrirVue(this);
});

// Précédent et Suivant rejouent la vue inscrite dans l'adresse, sans rien y réécrire.
window.addEventListener('popstate', function () {
    const vue = vueDeLAdresse();
    const bouton = vue ? document.getElementById('btn-' + vue) : document.querySelector('.nav-btn-avant');
    if (bouton) ouvrirVue(bouton, { historique: 'aucun' });
});

// À l'ouverture : on rouvre l'écran que l'adresse nomme. L'accueil est déjà rendu par le
// serveur, on ne le recharge donc pas pour rien.
$(function () {
    const vue = vueDeLAdresse();
    if (!vue || vue === 'accueil') return;
    const bouton = document.getElementById('btn-' + vue);
    // Une adresse qui nomme un écran auquel ce rôle n'a pas droit — ou qui n'existe plus —
    // laisse simplement l'accueil en place.
    if (bouton) ouvrirVue(bouton, { historique: 'remplacer' });
});


