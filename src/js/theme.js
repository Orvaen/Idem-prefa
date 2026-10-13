(() => {
    // Le thème, décidé à un seul endroit.
    //
    // Il se décidait à quatre : deux scripts en tête de page, le menu de la barre et la page
    // Paramètres — et tous notaient le choix de la même façon fautive. « Sombre » s'écrivait
    // en effaçant la clé, donc « je veux du sombre » et « je n'ai rien choisi » se rangeaient
    // au même endroit. Impossible, dans ces conditions, de suivre le système : on ne pouvait
    // pas distinguer l'un de l'autre.
    //
    // Trois valeurs, désormais : 'auto' (rien en mémoire, on suit le système), 'light',
    // 'dark'. La feuille de style n'a qu'une bascule — data-theme="light" — le sombre étant
    // ce que :root pose par défaut.

    const CLE = 'app_theme';
    const requete = window.matchMedia ? matchMedia('(prefers-color-scheme: light)') : null;

    /** Ce que la personne a choisi : 'auto', 'light' ou 'dark'. */
    function choix() {
        try {
            const garde = localStorage.getItem(CLE);
            return garde === 'light' || garde === 'dark' ? garde : 'auto';
        } catch (erreur) {
            // Navigation privée : on ne mémorise rien, on suit le système.
            return 'auto';
        }
    }

    /** Le thème réellement affiché, une fois 'auto' résolu. */
    const effectif = () => {
        const voulu = choix();
        if (voulu !== 'auto') return voulu;
        return requete && requete.matches ? 'light' : 'dark';
    };

    function peindre() {
        const clair = effectif() === 'light';
        const racine = document.documentElement;
        if (clair) racine.setAttribute('data-theme', 'light');
        else racine.removeAttribute('data-theme');
        // Le corps porte la même marque : des règles du thème clair le visent directement.
        if (document.body) {
            if (clair) document.body.setAttribute('data-theme', 'light');
            else document.body.removeAttribute('data-theme');
        }
    }

    function appliquer(voulu) {
        try {
            if (voulu === 'light' || voulu === 'dark') localStorage.setItem(CLE, voulu);
            else localStorage.removeItem(CLE);
        } catch (erreur) { /* le choix ne sera pas mémorisé, il s'applique quand même */ }
        peindre();
    }

    // Le système change d'avis — la nuit tombe, l'horaire bascule : on suit, mais seulement
    // si personne n'a tranché.
    if (requete) {
        const suivre = () => { if (choix() === 'auto') peindre(); };
        if (requete.addEventListener) requete.addEventListener('change', suivre);
        else if (requete.addListener) requete.addListener(suivre);
    }

    // Le corps n'existe pas encore quand ce script est lu dans l'en-tête.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', peindre);
    }

    window.Theme = { choix, effectif, appliquer, peindre };
    peindre();
})();
