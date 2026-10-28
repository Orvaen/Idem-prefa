const form = document.querySelector('#login-form');
const message = document.getElementById("form-message");

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton.disabled) return;
    submitButton.disabled = true;
    message.style.display = "none";

    const formData = new FormData(form);

    try {
        const response = await axios.post(
            'auth/action.php',
            formData,
            { timeout: 15000 }
        );

        if (response.data.success) {
            window.location.href = 'dashboard/dashboard.php';
        } else {
            message.style.display = "flex";
            message.innerText = response.data.message || "La connexion a échoué. Veuillez réessayer.";
        }

    } catch (error) {
        message.style.display = "flex";
        if (error.response) {
            message.innerText = error.response.data?.message || "Le serveur est indisponible. Veuillez réessayer.";
        } else {
            message.innerText = "Le serveur ne répond pas. Veuillez réessayer dans quelques instants.";
        }
    } finally {
        submitButton.disabled = false;
    }
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
