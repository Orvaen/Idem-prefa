// Les questions que l'application pose, dans sa propre fenêtre.
//
// window.alert, confirm et prompt ouvraient la boîte du navigateur : grise sous Windows,
// coiffée de « localhost:8080 indique », sans nos jetons, nos rayons ni notre police — et
// prompt faisait taper le numéro d'un bon au lieu de montrer la liste des bons. Les trois
// sont remplacées par une « dialog.fenetre », la recette de toutes les fenêtres :
//
//   window.alerte(titre, {texte, ton})                       → Promise, à la fermeture
//   window.confirmer(titre, {texte, action, annuler, ton})   → Promise<boolean>
//   window.choisir(titre, libelles, {texte, tous})           → Promise<rang | 'tous' | null>
//
// Une promesse et non une réponse : la boîte du navigateur arrêtait le script jusqu'au
// clic, celle-ci le laisse courir. Une confirmation s'écrit donc
// « if (!await window.confirmer(…)) return; », dans un gestionnaire déclaré async.
//
// Le comportement est celui de l'Alert Dialog de Propel : Annuler vient d'abord et prend le
// focus, Échap annule, et un clic à côté ne ferme rien — une question qui supprime ne se
// balaie pas d'un geste distrait.
(() => {
    'use strict';

    // La pastille dit l'intention avant qu'on lise : rouge, on va perdre quelque chose.
    const GLYPHES = {
        danger: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" />',
        attention: '<circle cx="12" cy="12" r="9" /><path d="M12 7v6M12 17h.01" />',
        info: '<circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" />',
    };
    const FICHIER = '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" />';

    let numero = 0;

    const dessin = trace => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + trace + '</svg>';

    /**
     * Pose la fenêtre, l'ouvre et attend qu'on la referme.
     *
     * @param {string}   titre    La question, ou le fait : c'est le nom de la fenêtre.
     * @param {string}   texte    Ce que la réponse emporte. Vide, rien ne paraît.
     * @param {string}   ton      danger | attention | info : la pastille. Vide, pas de pastille.
     * @param {string[]} choix    Des lignes à cliquer ; la réponse d'une ligne est son rang.
     * @param {Array}    boutons  Le pied, dans l'ordre : [libellé, réponse, classe].
     * @returns {Promise<string>} La réponse du bouton pris ; '' pour Annuler comme pour Échap.
     */
    function ouvrir({ titre, texte = '', ton = '', choix = [], boutons }) {
        const id = 'alerte-' + (++numero);
        const boite = document.createElement('dialog');
        boite.className = 'fenetre alerte';
        // « alertdialog » : le lecteur d'écran lit la conséquence avec la question, sans
        // attendre qu'on aille la chercher.
        boite.setAttribute('role', 'alertdialog');
        boite.setAttribute('aria-labelledby', id + '-titre');
        boite.innerHTML = '<div class="alerte-cadre"><div class="alerte-corps">'
            + (GLYPHES[ton] ? '<span class="alerte-pastille est-' + ton + '">' + dessin(GLYPHES[ton]) + '</span>' : '')
            + '<div class="alerte-intro"><h2 id="' + id + '-titre"></h2></div>'
            + '</div><div class="fenetre-pied"></div></div>';

        // Les textes passent par textContent : un nom de fichier ou un message du serveur
        // peut porter un chevron.
        boite.querySelector('h2').textContent = titre;
        if (texte) {
            const phrase = document.createElement('p');
            phrase.id = id + '-texte';
            phrase.textContent = texte;
            boite.querySelector('.alerte-intro').appendChild(phrase);
            boite.setAttribute('aria-describedby', phrase.id);
        }

        if (choix.length) {
            const liste = document.createElement('div');
            liste.className = 'alerte-choix';
            choix.forEach((libelle, rang) => {
                const ligne = document.createElement('button');
                ligne.type = 'button';
                ligne.dataset.reponse = String(rang);
                ligne.innerHTML = dessin(FICHIER) + '<span></span>';
                ligne.lastChild.textContent = libelle;
                liste.appendChild(ligne);
            });
            boite.querySelector('.alerte-corps').appendChild(liste);
        }

        const pied = boite.querySelector('.fenetre-pied');
        boutons.forEach(([libelle, reponse, classe]) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = classe;
            bouton.dataset.reponse = reponse;
            bouton.textContent = libelle;
            pied.appendChild(bouton);
        });

        // Le focus va au premier bouton : la première ligne d'un choix, sinon Annuler —
        // Entrée ne supprime rien par mégarde.
        boite.querySelector('[data-reponse]').autofocus = true;

        // La fenêtre est une couche à part, comme l'était celle du navigateur : ce qui s'y
        // passe ne remonte pas jusqu'au document. Sans cela, « Annuler » valait un clic à
        // côté — il vidait la sélection de lignes, refermait le menu des comptes — et Échap
        // refermait en plus le volet ou le menu posé dessous.
        boite.addEventListener('click', evenement => {
            evenement.stopPropagation();
            const pris = evenement.target.closest('[data-reponse]');
            if (pris) boite.close(pris.dataset.reponse);
        });

        boite.addEventListener('keydown', evenement => {
            evenement.stopPropagation();
            // Les flèches parcourent les lignes d'un choix, comme dans un menu.
            if (evenement.key !== 'ArrowDown' && evenement.key !== 'ArrowUp') return;
            const lignes = [...boite.querySelectorAll('.alerte-choix button')];
            const rang = lignes.indexOf(document.activeElement);
            if (rang < 0) return;
            evenement.preventDefault();
            lignes[(rang + (evenement.key === 'ArrowDown' ? 1 : -1) + lignes.length) % lignes.length].focus();
        });

        document.body.appendChild(boite);

        return new Promise(resoudre => {
            boite.addEventListener('close', () => {
                resoudre(boite.returnValue);
                // Retirée après sa sortie, que « dialog.fenetre » anime en 16 centièmes.
                setTimeout(() => boite.remove(), 400);
            }, { once: true });
            boite.showModal();
        });
    }

    /**
     * Annonce un fait dont il faut prendre acte : un refus, une suppression faite en partie.
     *
     * @param {string} titre  Le fait, en une phrase. Un message du serveur s'y pose tel quel :
     *                        il est déjà rédigé, un titre au-dessus le répéterait.
     * @param {string} texte  Ce qu'il faut en savoir de plus. Facultatif.
     * @param {string} ton    'danger' pour un refus, 'attention', ou 'info' (par défaut).
     */
    window.alerte = function (titre, { texte = '', ton = 'info' } = {}) {
        return ouvrir({ titre, texte, ton, boutons: [['OK', 'ok', 'fenetre-valider']] }).then(() => {});
    };

    /**
     * Demande avant d'agir. Vrai si l'on confirme ; faux pour Annuler comme pour Échap.
     *
     * @param {string} titre    La question : « Supprimer l’affaire « Bâtiment A » ? »
     * @param {string} texte    Ce que la réponse emporte : « Ses dates seront effacées. »
     * @param {string} action   Le verbe du bouton, celui de la question : « Supprimer ».
     * @param {string} annuler  L'issue sûre, quand « Annuler » prêterait à confusion.
     * @param {string} ton      'danger' quand on ne pourra pas revenir en arrière : la
     *                          pastille et le bouton passent au rouge. 'attention' sinon.
     */
    window.confirmer = function (titre, { texte = '', action = 'Confirmer', annuler = 'Annuler', ton = 'attention' } = {}) {
        return ouvrir({
            titre,
            texte,
            ton,
            boutons: [
                [annuler, '', 'fenetre-annuler'],
                [action, 'oui', 'fenetre-valider' + (ton === 'danger' ? ' est-danger' : '')],
            ],
        }).then(reponse => reponse === 'oui');
    };

    /**
     * Fait choisir dans une courte liste — ce que faisait prompt en demandant un numéro.
     *
     * @param {string}   titre     La question : « Quel bon lire ? »
     * @param {string[]} libelles  Une ligne par choix ; un clic la prend.
     * @param {string}   tous      Le libellé d'un bouton qui les prend toutes. Facultatif.
     * @returns {Promise<number|'tous'|null>} le rang choisi, 'tous', ou null si l'on renonce.
     */
    window.choisir = function (titre, libelles, { texte = '', tous = '' } = {}) {
        const boutons = [['Annuler', '', 'fenetre-annuler']];
        if (tous) boutons.push([tous, 'tous', 'fenetre-valider']);
        return ouvrir({ titre, texte, choix: libelles, boutons }).then(reponse =>
            reponse === '' ? null : reponse === 'tous' ? 'tous' : Number(reponse));
    };

    // hx-confirm : htmx pose sa question avec window.confirm, sauf si on la lui reprend.
    // L'élément qui la porte dit le reste — data-confirmer-texte pour la conséquence,
    // data-confirmer-action pour le bouton —, et une commande « est-destructrice » passe
    // au rouge. Sans hx-confirm, htmx annonce l'événement quand même : on le laisse filer.
    document.addEventListener('htmx:confirm', evenement => {
        const { question, elt, issueRequest } = evenement.detail;
        if (!question) return;
        evenement.preventDefault();
        window.confirmer(question, {
            texte: elt.dataset.confirmerTexte || '',
            action: elt.dataset.confirmerAction || 'Confirmer',
            ton: elt.classList.contains('est-destructrice') ? 'danger' : 'attention',
        }).then(oui => {
            // true : la question est posée, htmx ne doit pas la reposer à sa façon.
            if (oui) issueRequest(true);
        });
    });
})();
