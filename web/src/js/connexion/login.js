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
    if (nom === 'creation') {
        cible.elements.login.value = login;
        cible.querySelector('[data-creation-compte]').textContent = login;
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

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton.disabled) return;
    submitButton.disabled = true;
    dire(message, '');

    const formData = new FormData(form);

    try {
        const response = await axios.post(
            'auth/action.php',
            formData,
            { timeout: 15000 }
        );

        if (response.data.success) {
            window.location.href = 'dashboard/dashboard.php';
        } else if (response.data.creer) {
            // Le compte n'a pas encore de mot de passe : la personne choisit le sien.
            etape('creation', (formData.get('login') || '').trim());
        } else {
            dire(message, response.data.message || "La connexion a échoué. Veuillez réessayer.");
        }

    } catch (error) {
        if (error.response) {
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
    if (ok && corps.success) window.location.href = 'dashboard/dashboard.php';
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
                window.location.href = 'dashboard/dashboard.php';
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
