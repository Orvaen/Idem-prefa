// Ce que l'application répond, en bas à droite.
//
// Les écrans écrivaient leur réponse dans un paragraphe posé quelque part dans la page —
// sous le bandeau pour les affaires, sous le formulaire ailleurs. Deux défauts : il fallait
// une place vide en permanence pour un message qui paraît trois secondes, et le message
// partait avec l'écran dès qu'on le rechargeait, c'est-à-dire juste après l'avoir écrit.
//
// Le toast vit dans <body> : il survit au remplacement de #content, et il n'occupe aucune
// place tant qu'il n'a rien à dire.
(() => {
    'use strict';

    const DUREE = 4000;

    const CROCHET = '<path d="m4 12.5 5 5 11-11" />';
    const ALERTE = '<path d="M12 7v6M12 17h.01" /><circle cx="12" cy="12" r="9" />';

    /** La pile, créée au premier message et gardée ensuite. */
    function pile() {
        let boite = document.getElementById('toasts');

        if (!boite) {
            boite = document.createElement('div');
            boite.id = 'toasts';
            boite.className = 'toasts';
            document.body.appendChild(boite);
        }

        return boite;
    }

    /**
     * Annonce un message.
     *
     * @param {string} texte      Ce qu'on annonce. Vide, rien ne paraît.
     * @param {boolean} erreur    Un refus : il reste plus longtemps et se lit en rouge.
     */
    window.toast = function (texte, { erreur = false, duree = null } = {}) {
        texte = (texte || '').trim();
        if (!texte) return null;

        const boite = document.createElement('div');
        boite.className = 'toast' + (erreur ? ' est-erreur' : '');
        // « alert » pour un refus, « status » pour le reste : un lecteur d'écran doit
        // interrompre sa lecture pour annoncer un échec, pas pour confirmer un succès.
        boite.setAttribute('role', erreur ? 'alert' : 'status');

        boite.innerHTML = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + (erreur ? ALERTE : CROCHET) + '</svg>';

        const mot = document.createElement('span');
        mot.textContent = texte;
        boite.appendChild(mot);

        pile().appendChild(boite);

        // Un refus reste le temps qu'on le lise deux fois : on vient de perdre un geste,
        // et le message dit quoi corriger.
        const attente = duree || (erreur ? DUREE * 1.75 : DUREE);

        const partir = () => {
            if (!boite.isConnected) return;
            boite.classList.add('est-sortant');
            boite.addEventListener('animationend', () => boite.remove(), { once: true });
            // Le repli de sécurité : sans animation — « prefers-reduced-motion » la coupe —
            // « animationend » ne vient jamais, et le toast resterait à l'écran.
            setTimeout(() => boite.remove(), 400);
        };

        const minuteur = setTimeout(partir, attente);

        // On le chasse d'un clic : quatre secondes, c'est long quand on a déjà lu.
        boite.addEventListener('click', () => {
            clearTimeout(minuteur);
            partir();
        });

        return boite;
    };
})();
