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
const sidebarLayout = window.matchMedia('(max-width: 900px)');
function setSidebar(open) {
    document.getElementById('contenaire').classList.toggle('sidebar-closed', !open);
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
    setSidebar(this.getAttribute('aria-expanded') !== 'true');
});
sidebarLayout.addEventListener('change', function (event) { setSidebar(!event.matches); });
setSidebar(!sidebarLayout.matches);
