const form = document.querySelector('#login-form');
const message = document.getElementById("form-message");
const creation = document.getElementById('creation-form');
const oubli = document.getElementById('oubli-form');

/**
 * Le message d'un formulaire de l'écran : une erreur par défaut, une information avec
 * « info » — la connexion expirée, l'oubli bien transmis. Vide, il disparaît.
 */
function dire(cible, texte, info = false) {
    cible.classList.toggle('est-info', info);
    cible.innerText = texte;
    cible.style.display = texte ? 'flex' : 'none';
}

// Les trois étapes de l'écran : se connecter, choisir son mot de passe — première connexion,
// ou après une réinitialisation —, signaler un oubli. Un seul formulaire à la fois, et le
// titre dit lequel.
const TITRES = { connexion: 'Connexion', creation: 'Votre mot de passe', oubli: 'Mot de passe oublié' };
const FORMULAIRES = { connexion: form, creation, oubli };

function etape(nom, login = '') {
    Object.entries(FORMULAIRES).forEach(([cle, formulaire]) => {
        if (formulaire) formulaire.hidden = cle !== nom;
    });
    document.getElementById('connexion-titre').textContent = TITRES[nom];
    const cible = FORMULAIRES[nom];
    cible.querySelectorAll('.connexion-message').forEach(zone => dire(zone, ''));
    // Revenir à la connexion, c'est revenir à l'identifiant seul.
    if (nom === 'connexion') montrerMotDePasse(false);
    if (nom === 'creation') {
        // Le code d'accès d'abord : l'administrateur l'a donné, sans lui on ne choisit rien
        // (auth/creer_mot_de_passe.php).
        cible.elements.login.value = login;
        cible.querySelector('[data-creation-compte]').textContent = login;
        cible.elements.code.value = '';
        cible.elements.password.value = '';
        cible.elements.confirmation.value = '';
    }
    if (nom === 'oubli') {
        cible.elements.login.value = login;
        cible.querySelector('button[type="submit"]').hidden = false;
    }
    (nom === 'oubli' && login ? cible.querySelector('button[type="submit"]')
        : cible.querySelector('input:not([type="hidden"])'))?.focus();
}

document.addEventListener('click', event => {
    const lien = event.target.closest('.connexion-lien[data-etape]');
    if (!lien) return;
    etape(lien.dataset.etape, (form.elements.login.value || '').trim());
});

// ---------------------------------------------------------------- l'identifiant d'abord
//
// L'écran ne demande d'abord que l'identifiant. Le champ « Mot de passe » était toujours là,
// et à la première connexion — un compte neuf, ou réinitialisé, n'en a pas — la personne
// restait devant, bloquée, sans savoir qu'il fallait le laisser vide.
//
// « Continuer » demande au serveur si ce compte attend son premier mot de passe : oui, on
// passe à « Votre mot de passe » pour le choisir ; non — ou compte inconnu, la réponse est la
// même —, le champ du mot de passe paraît, et le bouton devient « Se connecter ».
const blocMotDePasse = form.querySelector('.connexion-mdp');
const boutonConnexion = form.querySelector('button[type="submit"]');

function montrerMotDePasse(oui) {
    form.dataset.etape = oui ? 'mot-de-passe' : 'identifiant';
    blocMotDePasse.hidden = !oui;
    boutonConnexion.textContent = oui ? 'Se connecter' : 'Continuer';
    if (oui) {
        form.elements.password.focus();
    } else {
        form.elements.password.value = '';
    }
}

/** Le compte attend-il son premier mot de passe ? null si le serveur ne répond pas. */
async function compteACreer(login) {
    const donnees = new FormData();
    donnees.set('csrf', creation?.elements.csrf?.value || '');
    donnees.set('login', login);
    try {
        const reponse = await axios.post('auth/compte_a_creer.php', donnees, { timeout: 8000 });
        return Boolean(reponse.data?.creer);
    } catch (erreur) {
        return null;
    }
}

// Corriger l'identifiant, une fois le mot de passe affiché, ramène à la première étape : le
// compte n'est peut-être plus le même. Le refus d'avant part avec : il restait affiché sous
// un identifiant seul, sans champ de mot de passe, et l'on ne savait plus ce qui était faux.
form.elements.login.addEventListener('input', () => {
    if (form.dataset.etape !== 'mot-de-passe') return;
    montrerMotDePasse(false);
    dire(message, '');
});

/** Un refus : le message, et le mot de passe sélectionné pour être retapé. */
function refuser(texte) {
    dire(message, texte);
    if (form.dataset.etape !== 'mot-de-passe') montrerMotDePasse(true);
    form.elements.password.select();
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton.disabled) return;
    dire(message, '');

    // Première étape : l'identifiant seul, toujours. Un mot de passe que le navigateur a glissé
    // dans le champ caché ne part pas : on ne l'a pas vu. Il partait — « un gestionnaire qui a
    // tout rempli passe directement à la connexion » —, et c'était souvent celui d'un autre
    // compte, ou d'avant une remise à zéro : on tapait son identifiant, « Continuer », et
    // « Identifiant ou mot de passe incorrect » tombait avant d'avoir rien tapé d'autre
    // (6 novembre 2026). Le champ paraît vide ; le navigateur y propose encore les siens.
    if (form.dataset.etape !== 'mot-de-passe') {
        const login = (form.elements.login.value || '').trim();
        if (!login) return;
        submitButton.disabled = true;
        const creer = await compteACreer(login);
        submitButton.disabled = false;
        if (creer) {
            etape('creation', login);
        } else {
            // Non, ou pas de réponse : le mot de passe. « Se connecter » refera la vérification.
            form.elements.password.value = '';
            montrerMotDePasse(true);
        }
        return;
    }

    submitButton.disabled = true;

    const formData = new FormData(form);

    try {
        const response = await axios.post(
            'auth/action.php',
            formData,
            { timeout: 15000 }
        );

        if (response.data.success) {
            // replace et non href : l'écran de connexion ne reste pas dans l'historique.
            // Précédent y ramenait depuis le premier écran du tableau de bord, et l'écran de
            // connexion renvoyait aussitôt au tableau de bord — on tournait en rond.
            window.location.replace('dashboard/dashboard.php');
        } else if (response.data.creer) {
            // Le compte n'a pas encore de mot de passe : la personne choisit le sien.
            etape('creation', (formData.get('login') || '').trim());
        } else {
            refuser(response.data.message || "La connexion a échoué. Veuillez réessayer.");
        }

    } catch (error) {
        if (error.response?.status === 401) {
            refuser(error.response.data?.message || 'Identifiant ou mot de passe incorrect.');
        } else if (error.response) {
            dire(message, error.response.data?.message || "Le serveur est indisponible. Veuillez réessayer.");
        } else {
            dire(message, "Le serveur ne répond pas. Veuillez réessayer dans quelques instants.");
        }
    } finally {
        submitButton.disabled = false;
    }
});

/** Envoie un formulaire de l'écran, et rend la réponse — ou celle de l'erreur. */
async function envoyer(formulaire, adresse) {
    const bouton = formulaire.querySelector('button[type="submit"]');
    const zone = formulaire.querySelector('.connexion-message');
    bouton.disabled = true;
    dire(zone, '');
    try {
        const reponse = await axios.post(adresse, new FormData(formulaire), { timeout: 15000 });
        return { ok: true, corps: reponse.data, zone };
    } catch (erreur) {
        dire(zone, erreur.response?.data?.message || 'Le serveur ne répond pas. Réessayez dans quelques instants.');
        return { ok: false, corps: null, zone };
    } finally {
        bouton.disabled = false;
    }
}

creation?.addEventListener('submit', async event => {
    event.preventDefault();
    const zone = creation.querySelector('.connexion-message');
    if (creation.elements.password.value !== creation.elements.confirmation.value) {
        dire(zone, 'Les deux saisies ne sont pas les mêmes.');
        return;
    }
    const { ok, corps } = await envoyer(creation, 'auth/creer_mot_de_passe.php');
    if (ok && corps.success) window.location.replace('dashboard/dashboard.php');
});

oubli?.addEventListener('submit', async event => {
    event.preventDefault();
    const { ok, corps, zone } = await envoyer(oubli, 'auth/mot_de_passe_oublie.php');
    if (!ok || !corps.success) return;
    // C'est transmis : il n'y a plus rien à envoyer d'ici, seulement à revenir plus tard.
    dire(zone, corps.message, true);
    oubli.querySelector('button[type="submit"]').hidden = true;
});

// ---------------------------------------------------------------- développement
//
// Les comptes proposés sous le formulaire n'existent qu'en développement : le PHP ne rend
// cette section que si le serveur tourne avec APP_ENV=dev, et l'action le revérifie de
// son côté. En production, il n'y a rien à écouter ici.
const comptesDev = document.querySelector('.connexion-dev');

if (comptesDev) {
    comptesDev.addEventListener('click', async (event) => {
        const bouton = event.target.closest('.connexion-dev-compte');
        if (!bouton || bouton.disabled) return;

        bouton.disabled = true;
        message.style.display = 'none';

        const donnees = new FormData();
        donnees.set('csrf', comptesDev.dataset.csrf);
        donnees.set('compte', bouton.dataset.compte);

        try {
            const reponse = await axios.post('auth/dev_connexion.php', donnees, { timeout: 15000 });
            if (reponse.data.success) {
                window.location.replace('dashboard/dashboard.php');
                return;
            }
            message.style.display = 'flex';
            message.innerText = reponse.data.message || 'Connexion impossible.';
        } catch (erreur) {
            message.style.display = 'flex';
            message.innerText = erreur.response?.data?.message || 'Le serveur ne répond pas.';
        } finally {
            bouton.disabled = false;
        }
    });
}
