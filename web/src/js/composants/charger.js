// Un écran dans un conteneur — #content le plus souvent —, à la place de $.load de jQuery.
//
// Même contrat que lui : la page n'est posée que si le serveur répond 2xx, et le conteneur
// garde son contenu sinon. Une différence, voulue : un chargement plus récent sur la même
// cible annule le précédent. Deux clics rapides dans la barre posaient les deux écrans, dans
// l'ordre où le serveur répondait — l'écran du premier clic pouvait arriver en dernier.
//
// Le chargement remplacé ne répond jamais : l'écran a changé, sa suite n'a plus lieu d'être.
(() => {
    'use strict';

    const enCours = new WeakMap();

    /**
     * @param {Element|string} cible  l'élément, ou son sélecteur
     * @param {string} adresse
     * @returns {Promise<{ok: boolean, statut: number}>} une fois la page posée (ok), ou le
     *          refus constaté (statut 0 : le réseau, pas le serveur)
     */
    window.chargerDans = function (cible, adresse) {
        const element = typeof cible === 'string' ? document.querySelector(cible) : cible;
        if (!element) return Promise.resolve({ ok: false, statut: 0 });

        enCours.get(element)?.abort();
        const controleur = new AbortController();
        enCours.set(element, controleur);

        return new Promise(resoudre => {
            fetch(adresse, { credentials: 'same-origin', signal: controleur.signal })
                .then(async reponse => {
                    const texte = await reponse.text();
                    if (controleur.signal.aborted) return;
                    if (reponse.ok) element.innerHTML = texte;
                    resoudre({ ok: reponse.ok, statut: reponse.status });
                })
                .catch(() => {
                    if (!controleur.signal.aborted) resoudre({ ok: false, statut: 0 });
                })
                .finally(() => {
                    if (enCours.get(element) === controleur) enCours.delete(element);
                });
        });
    };
})();
