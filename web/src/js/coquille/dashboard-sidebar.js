// Les rayons de la barre se replient, et se souviennent. Un chef d'atelier qui ne touche
// jamais à « Administration » le referme une fois pour toutes ; le choix tient par
// navigateur, puisque c'est un confort de poste et non une donnée du compte.
const RAYONS_REPLIES = 'rayons_replies';

function rayonsReplies() {
    try {
        const garde = JSON.parse(localStorage.getItem(RAYONS_REPLIES) || '[]');
        return Array.isArray(garde) ? garde : [];
    } catch (erreur) {
        // Stockage refusé (navigation privée) : les rayons s'ouvrent, simplement.
        return [];
    }
}

function replierRayon(titre, replie) {
    titre.setAttribute('aria-expanded', String(!replie));
    const cle = titre.closest('.nav-rayon').dataset.rayon;
    const replies = new Set(rayonsReplies());
    replie ? replies.add(cle) : replies.delete(cle);
    try {
        localStorage.setItem(RAYONS_REPLIES, JSON.stringify([...replies]));
    } catch (erreur) { /* le choix ne sera pas mémorisé */ }
}

document.querySelectorAll('.nav-rayon-titre').forEach(titre => {
    if (rayonsReplies().includes(titre.closest('.nav-rayon').dataset.rayon)) {
        titre.setAttribute('aria-expanded', 'false');
    }
    titre.addEventListener('click', () => {
        replierRayon(titre, titre.getAttribute('aria-expanded') === 'true');
    });
});

const btn_hide_sidebar = document.getElementById('btn-hide');
// Le même seuil que les feuilles — barre-laterale.css et prefa-list.css. Les deux
// avaient divergé : la feuille donnait au panneau sa forme de tiroir à 1080px pendant
// que le script le croyait encore accroché, et sur une tablette en paysage il restait
// ouvert par-dessus le contenu. Une requête média ne lit pas une variable CSS : si le
// seuil bouge, il bouge ici aussi.
const sidebarLayout = window.matchMedia('(max-width: 1080px)');

// Sur grand écran, le menu replié n'est plus caché : c'est un rail d'icônes, le libellé en
// infobulle. Demandé le 6 novembre 2026 — un menu « minimal ». Le bouton du haut le déplie,
// et le choix tient par navigateur, comme les rayons.
const MENU_DEPLIE = 'menu_deplie';

function menuDeplieMemorise() {
    try {
        return localStorage.getItem(MENU_DEPLIE) === '1';
    } catch (erreur) {
        return false;
    }
}

function setSidebar(open) {
    const contenaire = document.getElementById('contenaire');
    // Petit écran : le tiroir, ouvert par-dessus la page ou caché. Grand écran : le menu
    // complet ou le rail.
    contenaire.classList.toggle('sidebar-closed', !open && sidebarLayout.matches);
    contenaire.classList.toggle('menu-rail', !open && !sidebarLayout.matches);
    btn_hide_sidebar.setAttribute('aria-expanded', String(open));
    const overlay = open && sidebarLayout.matches;
    document.getElementById('sidebar-backdrop').hidden = !overlay;
    document.getElementById('right-panel').inert = overlay;
    if (overlay) {
        document.getElementById('close-sidebar').focus();
    } else if (document.getElementById('left-panel').contains(document.activeElement)) {
        btn_hide_sidebar.focus();
    }
}
document.getElementById('close-sidebar').addEventListener('click', () => setSidebar(false));
document.getElementById('sidebar-backdrop').addEventListener('click', () => setSidebar(false));
document.addEventListener('keydown', function (event) {
    if (!sidebarLayout.matches || btn_hide_sidebar.getAttribute('aria-expanded') !== 'true') return;
    if (event.key === 'Escape') setSidebar(false);
    if (event.key === 'Tab') {
        const items = document.getElementById('left-panel').querySelectorAll('button:not(:disabled), a[href]');
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
btn_hide_sidebar.addEventListener('click', function () {
    const ouvrir = this.getAttribute('aria-expanded') !== 'true';
    setSidebar(ouvrir);
    // Seul le choix du grand écran se retient : le tiroir se referme à chaque navigation.
    if (!sidebarLayout.matches) {
        try {
            localStorage.setItem(MENU_DEPLIE, ouvrir ? '1' : '0');
        } catch (erreur) { /* le choix ne sera pas mémorisé */ }
    }
});
sidebarLayout.addEventListener('change', function (event) {
    setSidebar(event.matches ? false : menuDeplieMemorise());
});
setSidebar(sidebarLayout.matches ? false : menuDeplieMemorise());

/* ---------- L'infobulle du rail ----------
 *
 * Replié, le menu ne montre que ses icônes : le libellé paraît à côté, au survol et au
 * clavier. Une bulle en position fixe, posée sur le corps de la page, et non un ::after : la
 * barre défile, et elle couperait tout ce qui dépasse de ses bords. Le libellé reste dans le
 * bouton, masqué à l'œil seulement — c'est lui qui donne son nom au bouton.
 */
(function () {
    const contenaire = document.getElementById('contenaire');
    const panneau = document.getElementById('left-panel');
    if (!contenaire || !panneau) return;

    const bulle = document.createElement('div');
    bulle.className = 'menu-bulle';
    bulle.setAttribute('aria-hidden', 'true');
    bulle.hidden = true;
    document.body.appendChild(bulle);

    function montrer(bouton) {
        if (!contenaire.classList.contains('menu-rail')) return;
        const libelle = bouton.querySelector(':scope > span:not(.nav-urgent-badge)')?.textContent.trim();
        if (!libelle) return;
        // Le compteur se lit aussi dans la bulle : sur le rail, il n'est plus qu'une pastille.
        const compte = bouton.querySelector('.nav-urgent-badge:not([hidden])')?.textContent.trim();
        bulle.textContent = compte ? libelle + ' · ' + compte : libelle;
        bulle.hidden = false;
        const repere = bouton.getBoundingClientRect();
        bulle.style.left = (repere.right + 10) + 'px';
        bulle.style.top = (repere.top + repere.height / 2 - bulle.offsetHeight / 2) + 'px';
    }

    const cacher = () => { bulle.hidden = true; };

    panneau.addEventListener('mouseover', evenement => {
        const bouton = evenement.target.closest('.nav-btn');
        if (bouton) montrer(bouton);
        else cacher();
    });
    panneau.addEventListener('mouseleave', cacher);
    panneau.addEventListener('focusin', evenement => {
        const bouton = evenement.target.closest('.nav-btn');
        if (bouton && bouton.matches(':focus-visible')) montrer(bouton);
    });
    panneau.addEventListener('focusout', cacher);
    panneau.addEventListener('click', cacher);
    panneau.addEventListener('scroll', cacher);
}());

/* ---------- La barre se parcourt aux flèches ----------
 *
 * C'est le comportement de la liste de Propel : un seul arrêt de tabulation pour toute la
 * barre, et les flèches déplacent d'une ligne à l'autre. Sans lui, atteindre « Paramètres »
 * demandait une tabulation par entrée — on traversait toute la barre avant d'arriver au
 * contenu, à chaque fois.
 *
 * L'arrêt se pose sur l'écran ouvert, qui porte déjà aria-current="page" : reprendre le
 * clavier après une navigation souris repart donc d'où l'on est, et non du haut.
 */
(function () {
    const barre = document.querySelector('.nav-header');
    if (!barre) return;

    // Un rayon replié passe en display:none, donc ses entrées n'ont plus d'offsetParent :
    // les flèches ne traversent pas ce qu'on ne voit pas.
    const lignes = () => Array.from(barre.querySelectorAll('.nav-rayon-titre, .nav-btn'))
        .filter(ligne => ligne.offsetParent !== null);

    function reposerArret() {
        const visibles = lignes();
        if (!visibles.length) return;
        const ouverte = barre.querySelector('.nav-btn[aria-current="page"]');
        const arret = visibles.includes(ouverte) ? ouverte : visibles[0];
        visibles.forEach(ligne => { ligne.tabIndex = ligne === arret ? 0 : -1; });
    }

    barre.addEventListener('keydown', function (evenement) {
        const pas = { ArrowDown: 1, ArrowUp: -1 };
        const saut = evenement.key === 'Home' || evenement.key === 'End';
        if (!(evenement.key in pas) && !saut) return;

        const visibles = lignes();
        const depart = visibles.indexOf(document.activeElement);
        if (depart === -1) return;
        evenement.preventDefault();

        let cible;
        if (evenement.key === 'Home') cible = 0;
        else if (evenement.key === 'End') cible = visibles.length - 1;
        else cible = (depart + pas[evenement.key] + visibles.length) % visibles.length;

        visibles.forEach(ligne => { ligne.tabIndex = -1; });
        visibles[cible].tabIndex = 0;
        visibles[cible].focus();
    });

    // L'écran ouvert change, ou un rayon se replie : l'arrêt se repose au bon endroit.
    new MutationObserver(reposerArret).observe(barre, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-current', 'aria-expanded'],
    });
    reposerArret();
}());
