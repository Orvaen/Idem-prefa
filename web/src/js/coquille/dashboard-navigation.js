/** Au DOM prêt — ou tout de suite, s'il l'est déjà. */
function quandPret(fonction) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fonction);
    else fonction();
}

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
quandPret(function () {
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
//
// Une tuile qui porte un filtre ouvre l'écran déjà resserré sur ce qu'elle compte —
// « Urgentes » ouvre la liste des demandes sur les urgentes en attente. C'est toujours
// l'entrée du menu qui s'allume.
document.addEventListener('click', evenement => {
    const tuile = evenement.target.closest('.accueil-tuile[data-ouvre], .accueil-affaire-action[data-ouvre]');
    if (!tuile) return;
    const bouton = document.getElementById(tuile.dataset.ouvre);
    if (!bouton) return;
    if (!tuile.dataset.filtre) {
        bouton.click();
        return;
    }
    const page = bouton.dataset.page;
    ouvrirVue(bouton, { page: page + (page.includes('?') ? '&' : '?') + tuile.dataset.filtre });
});

// L'écran ouvert est inscrit dans l'adresse, sous ?vue=<identifiant de l'entrée>.
//
// Sans cela, la page ne changeait jamais d'adresse : un rafraîchissement — ou un lien qu'on
// envoie à un collègue — ramenait toujours à l'accueil. Les boutons Précédent et Suivant du
// navigateur ne servaient à rien non plus, ou pire : ils quittaient l'application.
//
// C'est l'identifiant de l'entrée du menu qu'on écrit, pas l'adresse de la page : un même
// écran s'ouvre parfois sur un filtre — une affaire cliquée, une tuile de l'accueil —, et
// c'est l'entrée qui dit où l'on est.
function vueDeLAdresse() {
    return new URLSearchParams(window.location.search).get('vue');
}

const adresseDeLaVue = vue => window.location.pathname + '?vue=' + encodeURIComponent(vue);

/**
 * L'écran affiché : son entrée de menu, et la page exacte qu'on lui a demandée — un filtre
 * compris. C'est aussi ce que porte chaque entrée de l'historique : Précédent rouvre la
 * liste des demandes d'une affaire telle qu'on l'avait, et non la liste entière.
 *
 * @type {{vue: string, page: (string|null)}|null}
 */
let ecranCourant = null;

/**
 * Ouvre l'écran d'une entrée du menu.
 *
 * « page » permet d'ouvrir cet écran avec un filtre que le menu ne porte pas — une
 * affaire qu'on clique ouvre la liste des demandes sur son nom. C'est bien l'entrée du
 * menu qu'on passe quand même : c'est elle qui se marque active, et c'est son identifiant
 * qui part dans l'adresse.
 *
 * @returns {Promise<boolean>} une fois l'écran posé (true), ou son refus constaté (false) :
 *          qui ouvre un écran pour y faire quelque chose — déplier une fiche — attend ici.
 *          Un chargement remplacé par un plus récent ne répond jamais.
 */
function ouvrirVue(bouton, { historique = 'pousser', page = bouton.dataset.page } = {}) {
    if (!page) return Promise.resolve(false);

    const contenu = document.getElementById('content');
    return window.chargerDans(contenu, page).then(({ ok, statut }) => {
        if (!ok) {
            contenu.textContent = statut === 401
                ? 'Votre session a expiré. Reconnectez-vous puis réessayez.'
                : 'Impossible de charger la page. Rechargez le tableau de bord puis réessayez.';
            return false;
        }

        document.querySelectorAll('.nav-header .nav-btn').forEach(autre => {
            autre.classList.remove('is-active');
            autre.removeAttribute('aria-current');
        });
        bouton.classList.add('is-active');
        bouton.setAttribute('aria-current', 'page');

        // #content porte le nom de ce qu'il montre, posé par le serveur au premier
        // affichage. On le tient à jour, sinon il désignerait l'écran d'avant.
        const vue = bouton.id.replace(/^btn-/, '');
        contenu.dataset.vue = vue;
        const etat = { vue, page };

        if (historique !== 'aucun') {
            // « remplacer » au premier affichage : restaurer la vue d'une adresse ne doit pas
            // ajouter une entrée, sinon Précédent ne fait que revenir sur place.
            //
            // Rouvrir l'écran où l'on est déjà remplace aussi : chaque clic sur l'entrée du
            // menu ouverte empilait une page identique, et après quelques allers-retours
            // Précédent semblait ne plus rien faire — il revenait sur le même écran.
            const surPlace = historique === 'remplacer' || ecranCourant?.vue === vue;
            history[surPlace ? 'replaceState' : 'pushState'](etat, '', adresseDeLaVue(vue));
        }
        ecranCourant = etat;

        const urgentTotal = document.getElementById('prefa-urgent-total');
        if (urgentTotal) updateUrgentBadge(Number(urgentTotal.dataset.count));
        if (window.matchMedia('(max-width: 900px)').matches) {
            setSidebar(false);
        }
        htmx.process(contenu);
        document.querySelector('#create-user-form [name="name"]')?.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    });
}

document.addEventListener('click', evenement => {
    const bouton = evenement.target.closest('.nav-header .nav-btn[data-page]');
    if (bouton) ouvrirVue(bouton);
});

/**
 * Ferme ce qui est posé par-dessus l'écran — une fenêtre, un volet, le tiroir du menu.
 *
 * Une fenêtre passe par le même chemin qu'Échap : « cancel » d'abord, qu'elle peut retenir.
 * Celle d'une demande demande alors confirmation avant de jeter une saisie.
 *
 * @returns {boolean} vrai s'il y avait quelque chose à fermer
 */
function fermerCeQuiEstPoseDessus() {
    const fenetres = document.querySelectorAll('dialog[open]');
    const dessus = fenetres[fenetres.length - 1];
    if (dessus) {
        if (dessus.dispatchEvent(new Event('cancel', { cancelable: true }))) dessus.close();
        return true;
    }
    if (typeof fermerVoletOuvert === 'function' && fermerVoletOuvert()) return true;
    const tiroir = document.getElementById('btn-hide');
    if (window.matchMedia('(max-width: 1080px)').matches && tiroir?.getAttribute('aria-expanded') === 'true'
        && typeof setSidebar === 'function') {
        setSidebar(false);
        return true;
    }
    return false;
}

// Précédent et Suivant rejouent l'écran inscrit dans l'historique, sans rien y réécrire.
window.addEventListener('popstate', function (evenement) {
    // Une fenêtre ou un volet ouvert : Précédent le ferme, et l'on reste sur l'écran. C'est
    // le geste du bouton retour d'Android — l'application y tourne aussi. Il changeait
    // l'écran sous la fenêtre, et une fenêtre posée sur la page restait ouverte par-dessus
    // un écran qui n'était plus le sien. L'entrée qu'on vient de quitter est remise.
    if (fermerCeQuiEstPoseDessus()) {
        if (ecranCourant) history.pushState(ecranCourant, '', adresseDeLaVue(ecranCourant.vue));
        return;
    }

    // Un écran que le menu n'a pas — ancienne adresse, rôle changé — rouvre l'accueil :
    // l'adresse changeait sans que l'écran suive, et l'on se croyait bloqué.
    const vue = vueDeLAdresse();
    const bouton = (vue && document.getElementById('btn-' + vue)) || document.querySelector('.nav-btn-avant');
    if (!bouton) return;
    const etat = evenement.state;
    const page = etat?.vue === vue && etat.page ? etat.page : bouton.dataset.page;
    ouvrirVue(bouton, { historique: 'aucun', page });
});

// À l'ouverture : le serveur a déjà rendu l'écran que l'adresse nomme, et il écrit lequel
// sur #content. Il n'y a donc rien à recharger — y compris quand l'adresse nomme un écran
// auquel ce rôle n'a pas droit : le serveur a mis l'accueil, et data-vue le dit.
//
// Ce bloc rechargeait l'écran par-dessus l'accueil : on voyait l'accueil, puis l'écran,
// et la page était construite deux fois pour une seule ouverture.
quandPret(function () {
    // L'écran rendu par le serveur devient l'écran courant, et l'entrée de l'historique
    // sur laquelle on arrive le porte : y revenir par Précédent le rouvre.
    const affichee = document.getElementById('content')?.dataset.vue;
    if (affichee) {
        ecranCourant = { vue: affichee, page: null };
        history.replaceState(ecranCourant, '', window.location.href);
    }

    const vue = vueDeLAdresse();
    if (!vue || vue === affichee) return;
    const bouton = document.getElementById('btn-' + vue);
    if (bouton) ouvrirVue(bouton, { historique: 'remplacer' });
});
