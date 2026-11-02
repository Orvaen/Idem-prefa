(() => {
    // La connexion vaut deux heures, comptées depuis le mot de passe (Session::DUREE).
    //
    // Passé ce délai, le serveur répond 401 à tout, avec « X-Connexion: fermee ». Chaque
    // écran le disait dans son coin — « Veuillez vous reconnecter » dans une fenêtre, sous
    // un bouton, à la place d'une liste — et l'on cherchait à se reconnecter sans quitter la
    // page. C'est la page entière qui repart vers l'écran de connexion, et lui dit pourquoi.
    'use strict';

    const CONNEXION = '../';
    let partie = false;

    function repartir() {
        if (partie) return;
        partie = true;
        window.location.assign(CONNEXION);
    }

    // fetch : chargerDans() et les actions des écrans. L'en-tête, et non le 401 seul : un
    // mot de passe refusé à l'ajout d'un compte répond 401 aussi.
    const fetchDOrigine = window.fetch.bind(window);
    window.fetch = async (...parametres) => {
        const reponse = await fetchDOrigine(...parametres);
        if (reponse.status === 401 && reponse.headers.get('X-Connexion') === 'fermee') repartir();
        return reponse;
    };

    // htmx passe par XMLHttpRequest, que fetch ne voit pas.
    document.addEventListener('htmx:afterRequest', evenement => {
        const requete = evenement.detail && evenement.detail.xhr;
        if (requete && requete.status === 401 && requete.getResponseHeader('X-Connexion') === 'fermee') repartir();
    });

    // Et à l'heure dite, sans attendre une requête : un poste d'atelier laissé ouvert ne
    // doit plus montrer les données de quelqu'un une fois sa connexion finie. La seconde de
    // plus laisse le serveur constater l'échéance avant nous.
    const reste = Number(document.body.dataset.resteConnexion);
    if (reste > 0) window.setTimeout(repartir, reste * 1000 + 1000);
})();
