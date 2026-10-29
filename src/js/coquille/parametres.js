// Les paramètres : une fenêtre, sur le modèle de celle de Claude.
//
// Elle s'ouvre de trois endroits — le pied de la barre latérale, le menu du compte, et
// Ctrl+, — sur la rubrique demandée, ou sur la dernière ouverte. Le gabarit est rendu par le
// serveur avec le tableau de bord (includes/vue/parametres.php) : ce script montre une
// rubrique, cherche, et transmet ce qu'on règle.
//
// Un réglage s'applique dès qu'on le touche. Le thème vit dans theme.js ; la cadence et les
// passivations partent par les gestionnaires de dashboard-forms.js, ceux de l'ancien écran ;
// les unités du matériel et la signature, par ceux d'ici.
(() => {
    'use strict';

    const fenetre = document.getElementById('parametres');
    if (!fenetre) return;

    const onglets = [...fenetre.querySelectorAll('.parametres-onglet')];
    const rubriques = [...fenetre.querySelectorAll('.parametres-rubrique')];
    const contenu = fenetre.querySelector('.parametres-contenu');
    const recherche = fenetre.querySelector('.parametres-recherche input');
    const aucun = fenetre.querySelector('.parametres-aucun');
    let courante = 'general';

    // Comme la palette : sans accents ni majuscules, « theme » trouve « Thème ».
    const aplatir = texte => String(texte || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    /** Montre une rubrique. Une rubrique que cette personne n'a pas ramène à Général. */
    function montrer(nom) {
        courante = rubriques.some(r => r.dataset.rubrique === nom) ? nom : 'general';
        onglets.forEach(onglet => {
            if (onglet.dataset.rubrique === courante) onglet.setAttribute('aria-current', 'true');
            else onglet.removeAttribute('aria-current');
        });
        rubriques.forEach(r => { r.hidden = r.dataset.rubrique !== courante; });
        contenu.scrollTop = 0;
    }

    /**
     * La recherche traverse les rubriques : les lignes qui répondent restent, sous le nom de
     * la leur. Tous les mots tapés doivent s'y retrouver, dans n'importe quel ordre — ceux de
     * la ligne, de sa section et de sa rubrique : « apparence » trouve le thème.
     */
    function chercher() {
        const mots = aplatir(recherche.value).split(/\s+/).filter(Boolean);
        fenetre.classList.toggle('est-en-recherche', mots.length > 0);

        if (!mots.length) {
            fenetre.querySelectorAll('.parametres-section, .parametre').forEach(el => { el.hidden = false; });
            aucun.hidden = true;
            montrer(courante);
            return;
        }

        onglets.forEach(onglet => onglet.removeAttribute('aria-current'));
        let sectionsTrouvees = 0;
        rubriques.forEach(rubrique => {
            const nom = rubrique.querySelector('.parametres-rubrique-titre').textContent;
            let sections = 0;
            rubrique.querySelectorAll('.parametres-section').forEach(section => {
                const contexte = nom + ' ' + section.querySelector('h4').textContent + ' ' + (section.dataset.cherche || '');
                let lignes = 0;
                section.querySelectorAll('.parametre').forEach(ligne => {
                    const texte = aplatir(contexte + ' ' + ligne.textContent + ' ' + (ligne.dataset.cherche || ''));
                    ligne.hidden = !mots.every(mot => texte.includes(mot));
                    if (!ligne.hidden) lignes++;
                });
                section.hidden = lignes === 0;
                if (lignes) sections++;
            });
            rubrique.hidden = sections === 0;
            sectionsTrouvees += sections;
        });
        aucun.hidden = sectionsTrouvees > 0;
        aucun.querySelector('span').textContent = recherche.value.trim();
        contenu.scrollTop = 0;
    }

    /** Les boutons du thème disent le choix en cours, d'où qu'il soit venu. */
    function cocherTheme() {
        const choix = window.Theme ? window.Theme.choix() : 'auto';
        fenetre.querySelectorAll('[name="parametres-theme"]').forEach(radio => { radio.checked = radio.value === choix; });
    }

    function ouvrir(nom) {
        recherche.value = '';
        chercher();
        montrer(nom || courante);
        cocherTheme();
        if (!fenetre.open) fenetre.showModal();
        // Le focus sur la rubrique ouverte : les flèches parcourent la colonne, la recherche
        // est une tabulation plus haut.
        fenetre.querySelector('.parametres-onglet[aria-current]')?.focus();
    }

    window.ouvrirParametres = ouvrir;

    // ---------------------------------------------------------------- dans la fenêtre

    // Un clic sur le voile referme, comme chez Claude — s'il y a commencé : un glissement
    // parti d'un champ et lâché dehors ne ferme rien.
    let appuiSurLeVoile = false;
    fenetre.addEventListener('pointerdown', evenement => { appuiSurLeVoile = evenement.target === fenetre; });

    fenetre.addEventListener('click', evenement => {
        if (evenement.target === fenetre) {
            if (appuiSurLeVoile) fenetre.close();
            return;
        }
        const onglet = evenement.target.closest('.parametres-onglet');
        if (onglet) {
            recherche.value = '';
            chercher();
            montrer(onglet.dataset.rubrique);
            return;
        }
        if (evenement.target.closest('[data-fermer-parametres]')) {
            fenetre.close();
            return;
        }
        // « Modifiable dans Utilisateurs » : on y va, par le bouton de la barre, pour que
        // l'entrée se marque comme ouverte.
        const vers = evenement.target.closest('[data-parametres-vers]');
        if (vers) {
            fenetre.close();
            document.getElementById('btn-' + vers.dataset.parametresVers)?.click();
        }
    });

    // Les flèches parcourent la colonne, comme dans la barre latérale.
    fenetre.querySelector('.parametres-nav').addEventListener('keydown', evenement => {
        const rang = onglets.indexOf(document.activeElement);
        if (rang < 0) return;
        const vers = { ArrowDown: rang + 1, ArrowUp: rang - 1, Home: 0, End: onglets.length - 1 }[evenement.key];
        if (vers === undefined) return;
        evenement.preventDefault();
        const cible = onglets[(vers + onglets.length) % onglets.length];
        cible.focus();
        cible.click();
    });

    recherche.addEventListener('input', chercher);

    // Échap vide d'abord la recherche ; il ne referme la fenêtre qu'une fois le champ vide.
    recherche.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape' || !recherche.value) return;
        evenement.preventDefault();
        recherche.value = '';
        chercher();
    });

    fenetre.addEventListener('change', evenement => {
        const champ = evenement.target;
        if (champ.name === 'parametres-theme' && window.Theme) window.Theme.appliquer(champ.value);
        // La cadence part dès qu'on la quitte, par Entrée ou un clic ailleurs. Le formulaire
        // ne se valide pas dans le navigateur : sa bulle grise est celle de Windows, et le
        // serveur dit mieux ce qui ne va pas, sous la ligne.
        if (champ.id === 'parametres-cadence') champ.form.requestSubmit();
    });

    // Le menu du thème, dans la barre du haut, peut aussi changer le thème.
    document.addEventListener('theme:choisi', cocherTheme);

    /**
     * Envoie une opération à une action des paramètres, et rend sa réponse — ou null. Le
     * refus s'écrit sous le champ, ou en toast faute de place.
     */
    async function envoyer(adresse, champs, message) {
        if (message) {
            message.textContent = '';
            message.classList.remove('error');
        }
        try {
            const reponse = await fetch(adresse, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams(champs),
            });
            const corps = await reponse.json().catch(() => null);
            if (!reponse.ok || !corps?.success) throw new Error(corps?.message || 'Enregistrement impossible. Réessayez.');
            window.toast?.(corps.message);
            return corps;
        } catch (erreur) {
            if (message) {
                message.textContent = erreur.message;
                message.classList.add('error');
            } else {
                window.toast?.(erreur.message, { erreur: true });
            }
            return null;
        }
    }

    // ---------------------------------------------------------------- les unités du matériel
    //
    // La liste se tient sur place : « Ajouter » en tête, « Modifier » et « Supprimer » au bout
    // de chaque ligne. Chaque réponse rend la liste entière, dans son ordre
    // (actions/unite_materiel.php) : on la remplace d'un bloc, sans chercher où ranger.

    const lignesUnites = fenetre.querySelector('#unites-lignes');
    const formUnite = fenetre.querySelector('#unites-form');
    // La ligne telle qu'elle était avant « Modifier » : « Annuler » la remet.
    const avantEdition = new WeakMap();

    async function envoyerUnite(champs, message) {
        const corps = await envoyer('actions/unite_materiel.php', { csrf: formUnite.elements.csrf.value, ...champs }, message);
        if (corps) lignesUnites.innerHTML = corps.html;
        return Boolean(corps);
    }

    function annulerEdition(ligne) {
        if (ligne && avantEdition.has(ligne)) ligne.innerHTML = avantEdition.get(ligne);
    }

    function editer(ligne) {
        // Une seule ligne à la fois : celle qu'on quitte reprend sa forme.
        lignesUnites.querySelectorAll('tr:has(.unite-edition)').forEach(autre => { if (autre !== ligne) annulerEdition(autre); });
        avantEdition.set(ligne, ligne.innerHTML);
        const cellule = ligne.querySelector('.unite-libelle');
        const actuel = cellule.textContent.trim();
        cellule.innerHTML = '<form class="unite-edition" novalidate>'
            + '<input name="libelle" maxlength="16" autocomplete="off" aria-label="Nouveau nom de l’unité">'
            + '<p class="prefa-message" role="alert"></p></form>';
        const champ = cellule.querySelector('input');
        champ.value = actuel;
        ligne.lastElementChild.innerHTML = '<button type="button" class="parametres-ligne-action" data-unite-enregistrer>Enregistrer</button>'
            + '<button type="button" class="parametres-ligne-action" data-unite-annuler>Annuler</button>';
        champ.focus();
        champ.select();
    }

    function enregistrer(ligne) {
        const champ = ligne.querySelector('.unite-edition input');
        if (!champ) return;
        envoyerUnite({ operation: 'renommer', id: ligne.dataset.unite, libelle: champ.value },
            ligne.querySelector('.unite-edition .prefa-message'));
    }

    formUnite?.addEventListener('submit', async evenement => {
        evenement.preventDefault();
        const champ = formUnite.elements.libelle;
        if (await envoyerUnite({ operation: 'ajouter', libelle: champ.value }, formUnite.querySelector('.parametre-message'))) {
            champ.value = '';
            champ.focus();
        }
    });

    lignesUnites?.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('button');
        const ligne = bouton?.closest('tr[data-unite]');
        if (!ligne) return;
        if (bouton.hasAttribute('data-unite-modifier')) editer(ligne);
        else if (bouton.hasAttribute('data-unite-annuler')) annulerEdition(ligne);
        else if (bouton.hasAttribute('data-unite-enregistrer')) enregistrer(ligne);
        else if (bouton.hasAttribute('data-unite-supprimer')) {
            const nom = ligne.querySelector('.unite-libelle').textContent.trim();
            if (!await window.confirmer('Supprimer l’unité « ' + nom + ' » ?', {
                texte: 'Elle ne sera plus proposée au personnel d’atelier. Les manques déjà signalés la gardent.',
                action: 'Supprimer',
                ton: 'danger',
            })) return;
            envoyerUnite({ operation: 'supprimer', id: ligne.dataset.unite }, null);
        }
    });

    // Entrée enregistre le nouveau nom ; Échap l'abandonne sans refermer la fenêtre.
    lignesUnites?.addEventListener('submit', evenement => {
        evenement.preventDefault();
        enregistrer(evenement.target.closest('tr[data-unite]'));
    });
    lignesUnites?.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape' || !evenement.target.closest('.unite-edition')) return;
        evenement.preventDefault();
        annulerEdition(evenement.target.closest('tr[data-unite]'));
    });

    // ---------------------------------------------------------------- la signature
    //
    // Tracée une fois, elle vise les cordons que la personne valide
    // (includes/atelier/signature.php). Le bloc la montre comme au bas d'un document ; le
    // cadre où l'on signe a sa forme et prend sa place. C'est celui de signature_pad, chargé à
    // la première ouverture seulement : on ouvre ses paramètres pour bien d'autres choses. Les
    // points partent en fractions du cadre ; le serveur recadre sur l'encre et range.

    const SIGNATURE_PAD = 'https://cdn.jsdelivr.net/npm/signature_pad@5.1.4/dist/signature_pad.umd.min.js';
    const SIGNATURE_PAD_EMPREINTE = 'sha384-tDSOCzVljSggNbjPF/Hz+FRfFWI/03Gp04+9fEFbNzsT1CI/tk1WBY2QG4dlmevX';
    const sectionSignature = fenetre.querySelector('.signature-section');
    const actuelle = fenetre.querySelector('#signature-actuelle');
    const pave = sectionSignature?.querySelector('.signature-pave');
    const cadreASigner = pave?.querySelector('.signature-cadre');
    const canevas = pave?.querySelector('canvas');
    let chargementPad = null;
    let signaturePad = null;
    let largeurCadre = 0;

    function chargerSignaturePad() {
        chargementPad ??= new Promise((resoudre, rejeter) => {
            if (window.SignaturePad) {
                resoudre(window.SignaturePad);
                return;
            }
            const script = document.createElement('script');
            script.src = SIGNATURE_PAD;
            script.integrity = SIGNATURE_PAD_EMPREINTE;
            script.crossOrigin = 'anonymous';
            script.onload = () => resoudre(window.SignaturePad);
            script.onerror = () => {
                // L'échec n'est pas retenu : le prochain clic retente.
                chargementPad = null;
                script.remove();
                rejeter(new Error('Le cadre de signature ne se charge pas. Vérifiez la connexion, puis réessayez.'));
            };
            document.head.appendChild(script);
        });
        return chargementPad;
    }

    /**
     * Le canevas à la taille du cadre, en pixels de l'écran : sans cela le trait sort flou, ou
     * décalé du doigt. Ce qui est déjà tracé suit le cadre quand il change de taille — élargir
     * la fenêtre ne doit pas effacer une signature à moitié faite.
     */
    function ajusterCadre() {
        const largeur = canevas.offsetWidth;
        if (!signaturePad || !largeur || largeur === largeurCadre) return;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const facteur = largeurCadre ? largeur / largeurCadre : 1;
        const traits = signaturePad.toData();
        canevas.width = largeur * ratio;
        canevas.height = canevas.offsetHeight * ratio;
        canevas.getContext('2d').scale(ratio, ratio);
        signaturePad.fromData(traits.map(groupe => ({
            ...groupe,
            points: groupe.points.map(point => ({ ...point, x: point.x * facteur, y: point.y * facteur })),
        })));
        largeurCadre = largeur;
    }

    /**
     * Les outils suivent le cadre : défaire, effacer et enregistrer n'ont rien à faire sur un
     * cadre vide, et l'invite « Signez ici » ne reste que tant qu'il l'est.
     */
    function suivreLeCadre() {
        const vide = !signaturePad || signaturePad.isEmpty();
        const outils = pave.querySelectorAll('[data-signature-defaire], [data-signature-effacer], [data-signature-enregistrer]');
        // Un bouton qui s'éteint perd le focus : sans relais, il tomberait hors du cadre, et
        // Échap fermerait alors toute la fenêtre au lieu d'abandonner la signature.
        const focusPerdu = vide && [...outils].includes(document.activeElement);
        cadreASigner.classList.toggle('est-vide', vide);
        outils.forEach(bouton => { bouton.disabled = vide; });
        if (focusPerdu) pave.querySelector('[data-signature-annuler]').focus();
    }

    function direSignature(texte, erreur) {
        const message = sectionSignature.querySelector('.signature-message');
        message.textContent = texte;
        message.classList.toggle('error', Boolean(erreur));
    }

    async function ouvrirCadre() {
        direSignature('');
        let SignaturePad;
        try {
            SignaturePad = await chargerSignaturePad();
        } catch (erreur) {
            direSignature(erreur.message, true);
            return;
        }
        actuelle.hidden = true;
        pave.hidden = false;
        // L'encre prend la couleur du texte : la signature est rangée sans couleur, et se
        // redessine dans celle de ce qui l'entoure.
        const encre = getComputedStyle(cadreASigner).color;
        if (!signaturePad) {
            signaturePad = new SignaturePad(canevas, { penColor: encre, minWidth: 0.8, maxWidth: 2.4 });
            // L'invite s'efface dès que la plume touche, sans attendre la fin du trait.
            signaturePad.addEventListener('beginStroke', () => cadreASigner.classList.remove('est-vide'));
            signaturePad.addEventListener('endStroke', suivreLeCadre);
            new ResizeObserver(ajusterCadre).observe(canevas);
        }
        signaturePad.penColor = encre;
        signaturePad.clear();
        signaturePad.on();
        largeurCadre = 0;
        ajusterCadre();
        suivreLeCadre();
        // Le focus dans le cadre : Échap l'abandonne plutôt que de fermer la fenêtre.
        pave.querySelector('[data-signature-annuler]').focus();
    }

    function fermerCadre(rendreLeFocus = true) {
        if (!pave || pave.hidden) return;
        pave.hidden = true;
        actuelle.hidden = false;
        signaturePad?.clear();
        signaturePad?.off();
        if (rendreLeFocus) actuelle.querySelector('button')?.focus();
    }

    function defaireLeDernierTrait() {
        const traits = signaturePad.toData();
        traits.pop();
        signaturePad.fromData(traits);
        suivreLeCadre();
    }

    async function enregistrerSignature() {
        if (!signaturePad || signaturePad.isEmpty()) return;
        // Les points en fractions du cadre, au dix-millième : le serveur n'a pas à connaître
        // la taille de l'écran.
        const { width: largeur, height: hauteur } = canevas.getBoundingClientRect();
        const fraction = (valeur, total) => Math.round(valeur / total * 10000) / 10000;
        const traits = signaturePad.toData()
            .map(groupe => groupe.points.map(point => [fraction(point.x, largeur), fraction(point.y, hauteur)]));
        const corps = await envoyer('actions/ma_signature.php', {
            csrf: sectionSignature.dataset.csrf,
            operation: 'enregistrer',
            dessin: JSON.stringify({ proportion: largeur / hauteur, traits }),
        }, sectionSignature.querySelector('.signature-message'));
        if (!corps) return;
        actuelle.innerHTML = corps.html;
        fermerCadre();
    }

    async function supprimerSignature() {
        if (!await window.confirmer('Supprimer votre signature ?', {
            texte: 'Vos prochains visas porteront votre nom. Les cordons déjà visés gardent leur signature.',
            action: 'Supprimer',
            ton: 'danger',
        })) return;
        const corps = await envoyer('actions/ma_signature.php', { csrf: sectionSignature.dataset.csrf, operation: 'supprimer' },
            sectionSignature.querySelector('.signature-message'));
        if (!corps) return;
        actuelle.innerHTML = corps.html;
        actuelle.querySelector('button')?.focus();
    }

    sectionSignature?.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('button');
        if (!bouton) return;
        if (bouton.hasAttribute('data-signature-tracer')) ouvrirCadre();
        else if (bouton.hasAttribute('data-signature-supprimer')) supprimerSignature();
        else if (bouton.hasAttribute('data-signature-defaire')) defaireLeDernierTrait();
        else if (bouton.hasAttribute('data-signature-effacer')) {
            signaturePad?.clear();
            suivreLeCadre();
        } else if (bouton.hasAttribute('data-signature-annuler')) fermerCadre();
        else if (bouton.hasAttribute('data-signature-enregistrer')) enregistrerSignature();
    });

    pave?.addEventListener('keydown', evenement => {
        if (evenement.key !== 'Escape') return;
        evenement.preventDefault();
        fermerCadre();
    });

    // Le focus peut aussi être ailleurs — sur la page, après un trait à la souris. Échap
    // abandonne alors la signature, et seulement elle : la fenêtre reste ouverte.
    fenetre.addEventListener('cancel', evenement => {
        if (!pave || pave.hidden) return;
        evenement.preventDefault();
        fermerCadre();
    });

    // Refermée en pleine signature, la fenêtre se rouvre sans le cadre.
    fenetre.addEventListener('close', () => fermerCadre(false));

    // ---------------------------------------------------------------- l'ouvrir

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('[data-ouvre-parametres]');
        if (!bouton) return;
        // Sur un écran étroit, la barre passe par-dessus le contenu : on la range d'abord.
        if (window.matchMedia('(max-width: 1080px)').matches && typeof setSidebar === 'function') setSidebar(false);
        ouvrir(bouton.dataset.ouvreParametres);
    });

    // Ctrl+, : le raccourci des réglages dans la plupart des logiciels de bureau.
    document.addEventListener('keydown', evenement => {
        if (evenement.key !== ',' || !(evenement.ctrlKey || evenement.metaKey) || evenement.altKey) return;
        evenement.preventDefault();
        // Une autre fenêtre a la main : on ne passe pas par-dessus.
        if (document.querySelector('dialog[open]')) return;
        ouvrir();
    });

    // Les adresses de l'ancien écran : ?vue=settings, et ses ancres. Le serveur a rendu
    // l'accueil à sa place ; la fenêtre s'ouvre par-dessus, sur la bonne rubrique, puis
    // l'adresse reprend le nom de l'écran qu'on voit. Dans cet ordre : si le navigateur
    // refuse de réécrire l'adresse, la fenêtre est ouverte quand même.
    if (new URLSearchParams(location.search).get('vue') === 'settings') {
        ouvrir({ hours: 'fabrication', passivation: 'passivation', theme: 'general' }[location.hash.slice(1)]);
        history.replaceState(history.state, '', location.pathname + '?vue=' + (document.getElementById('content')?.dataset.vue || 'accueil'));
    }
})();
