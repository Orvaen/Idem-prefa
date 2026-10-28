// La poignée entre le menu et la carte : on tire, le menu suit.
//
// La largeur part de celle qu'avait le menu quand on a pris la poignée, plus le chemin
// parcouru. Elle se lisait comme « pointeur moins bord du conteneur », qui oubliait les huit
// pixels de marge du conteneur et les huit de la gouttière : la carte sautait de seize
// pixels au premier mouvement, et son filet courait ensuite devant le pointeur.
//
// Le pointeur est capturé : le geste suit jusqu'au relâchement, même au-dessus d'un
// document intégré. Écoutés sur le document, les mouvements s'y perdaient — et le
// relâchement avec eux, si bien que la poignée restait accrochée au pointeur, bouton levé.
$(function () {
    const menu = document.getElementById('left-panel');
    const poignee = document.getElementById('drag');
    if (!menu || !poignee) return;

    let depart = null;

    poignee.addEventListener('pointerdown', evenement => {
        if (evenement.button !== 0) return;
        depart = { x: evenement.clientX, largeur: menu.getBoundingClientRect().width };
        poignee.setPointerCapture(evenement.pointerId);
        poignee.classList.add('is-resizing');
        document.body.classList.add('est-redimensionne');
        evenement.preventDefault();
    });

    poignee.addEventListener('pointermove', evenement => {
        if (!depart) return;
        // Bornée plutôt qu'ignorée : un geste rapide au-delà de 600 s'arrêtait à la
        // dernière largeur admise, quelque part avant la butée.
        const largeur = Math.min(600, Math.max(150, depart.largeur + evenement.clientX - depart.x));
        menu.style.width = largeur + 'px';
    });

    // La capture se perd au relâchement comme à l'interruption du geste : une seule fin.
    poignee.addEventListener('lostpointercapture', () => {
        depart = null;
        poignee.classList.remove('is-resizing');
        document.body.classList.remove('est-redimensionne');
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
$(document).on('click', '.accueil-tuile[data-ouvre], .accueil-affaire-action[data-ouvre]', function () {
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

/**
 * Ouvre l'écran d'une entrée du menu.
 *
 * « page » permet d'ouvrir cet écran avec un filtre que le menu ne porte pas — une
 * affaire qu'on clique ouvre la liste des demandes sur son nom. C'est bien l'entrée du
 * menu qu'on passe quand même : c'est elle qui se marque active, et c'est son identifiant
 * qui part dans l'adresse.
 */
function ouvrirVue(bouton, { historique = 'pousser', page = bouton.dataset.page } = {}) {
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

        // #content porte le nom de ce qu'il montre, posé par le serveur au premier
        // affichage. On le tient à jour, sinon il désignerait l'écran d'avant.
        const vue = bouton.id.replace(/^btn-/, '');
        $('#content').attr('data-vue', vue);

        if (historique !== 'aucun') {
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

// À l'ouverture : le serveur a déjà rendu l'écran que l'adresse nomme, et il écrit lequel
// sur #content. Il n'y a donc rien à recharger — y compris quand l'adresse nomme un écran
// auquel ce rôle n'a pas droit : le serveur a mis l'accueil, et data-vue le dit.
//
// Ce bloc rechargeait l'écran par-dessus l'accueil : on voyait l'accueil, puis l'écran,
// et la page était construite deux fois pour une seule ouverture.
$(function () {
    const vue = vueDeLAdresse();
    if (!vue || vue === document.getElementById('content')?.dataset.vue) return;
    const bouton = document.getElementById('btn-' + vue);
    if (bouton) ouvrirVue(bouton, { historique: 'remplacer' });
});


