// L'état d'un formulaire en cours d'envoi ou de vérification : hors du DOM, il disparaît avec
// le formulaire quand l'écran est remplacé.
const etatsFormulaires = new WeakMap();

function etatFormulaire(form) {
    let etat = etatsFormulaires.get(form);
    if (!etat) etatsFormulaires.set(form, etat = { envoi: false, revision: 0, disponible: null, minuterie: null });
    return etat;
}

/**
 * Un POST dont on attend du JSON. « corps » vaut null si la réponse n'en est pas : la
 * réussite exige les deux, un statut 2xx et un JSON lisible.
 */
async function envoyerFormulaire(adresse, donnees) {
    try {
        const reponse = await fetch(adresse, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(donnees) });
        const corps = await reponse.json().catch(() => null);
        return { ok: reponse.ok && corps !== null, statut: reponse.status, corps };
    } catch (erreur) {
        return { ok: false, statut: 0, corps: null };
    }
}

function ecrireMessage(message, texte, classe) {
    if (!message) return;
    message.classList.remove('success', 'error');
    if (classe) message.classList.add(classe);
    message.textContent = texte;
}

function boutonsActifs(form, actifs, selecteur = 'button') {
    form.querySelectorAll(selecteur).forEach(bouton => { bouton.disabled = !actifs; });
}

document.addEventListener('submit', async evenement => {
    const form = evenement.target;
    if (form.id !== 'passivation-form') return;
    evenement.preventDefault();
    const etat = etatFormulaire(form);
    if (etat.envoi) return;
    const message = form.querySelector('.prefa-message');
    ecrireMessage(message, 'Enregistrement…');
    etat.envoi = true;
    boutonsActifs(form, false);

    const { ok, corps } = await envoyerFormulaire('actions/create_passivation.php', new FormData(form));
    if (ok) {
        const lignes = document.getElementById('passivation-rows');
        lignes?.querySelectorAll('.passivation-empty').forEach(vide => vide.remove());
        const ligne = lignes && [...lignes.querySelectorAll('tr')].find(tr => tr.getAttribute('data-id') === String(corps.id));
        if (ligne) {
            ligne.querySelectorAll('.passivation-name').forEach(nom => { nom.textContent = corps.libelle; });
        } else if (lignes) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-id', corps.id);
            const numero = document.createElement('td');
            numero.textContent = corps.id;
            const nom = document.createElement('td');
            nom.className = 'passivation-name';
            nom.textContent = corps.libelle;
            const action = document.createElement('td');
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'edit-passivation';
            bouton.setAttribute('hx-get', 'actions/edit_passivation.php?id=' + corps.id);
            bouton.setAttribute('hx-target', 'closest tr');
            bouton.setAttribute('hx-swap', 'outerHTML');
            bouton.textContent = 'Modifier';
            action.append(bouton);
            tr.append(numero, nom, action);
            lignes.append(tr);
        }
        form.reset();
        htmx.process(lignes);
        ecrireMessage(message, corps.message, 'success');
    } else {
        ecrireMessage(message, corps?.message || 'Enregistrement impossible. Réessayez.', 'error');
    }
    etat.envoi = false;
    boutonsActifs(form, true);
});

// Seules les pastilles qui comptent des demandes urgentes. Le sélecteur prenait toutes
// les pastilles de la barre, celle des PV comprise.
function updateUrgentBadge(count) {
    document.querySelectorAll('.nav-urgent-badge[data-compteur="urgentes"]').forEach(pastille => {
        pastille.textContent = count;
        pastille.hidden = count === 0;
        pastille.setAttribute('aria-label', count + ' demandes urgentes en attente');
    });
}

const valeurChamp = (form, nom) => (form.querySelector('[name="' + nom + '"]')?.value || '').trim();

function identityKey(form) {
    return JSON.stringify([valeurChamp(form, 'name'), valeurChamp(form, 'prenom')]);
}

function verifierIdentite(evenement) {
    const champ = evenement.target.closest?.('#create-user-form [name="name"], #create-user-form [name="prenom"]');
    if (!champ) return;
    const form = champ.closest('form');
    const etat = etatFormulaire(form);
    const message = form.querySelector('#identifiant-message');
    const key = identityKey(form);
    const revision = ++etat.revision;
    etat.disponible = null;
    clearTimeout(etat.minuterie);
    boutonsActifs(form, false, '[type="submit"]');
    message?.classList.remove('success', 'error');

    if (!valeurChamp(form, 'name') || !valeurChamp(form, 'prenom')) {
        if (message) message.textContent = 'Saisissez le nom et le prénom.';
        return;
    }

    if (message) message.textContent = 'Vérification…';
    etat.minuterie = setTimeout(async () => {
        const { ok, corps } = await envoyerFormulaire('actions/check_identifiant.php', {
            name: valeurChamp(form, 'name'),
            prenom: valeurChamp(form, 'prenom'),
        });
        if (etat.revision !== revision || identityKey(form) !== key) return;
        if (!ok) {
            ecrireMessage(message, corps?.message || 'Vérification impossible. Modifiez le nom ou le prénom pour réessayer.', 'error');
            return;
        }
        const available = corps.available === true;
        message?.classList.add(available ? 'success' : 'error');
        if (message) message.textContent = corps.message;
        if (available) etat.disponible = key;
        boutonsActifs(form, available && !etat.envoi, '[type="submit"]');
    }, 300);
}

document.addEventListener('input', verifierIdentite);
document.addEventListener('change', verifierIdentite);

document.addEventListener('submit', async evenement => {
    const form = evenement.target;
    if (form.id !== 'create-user-form') return;
    evenement.preventDefault();

    const etat = etatFormulaire(form);
    const message = document.getElementById('form-message');

    if (etat.envoi) return;
    if (etat.disponible !== identityKey(form)) {
        form.querySelector('[name="name"]')?.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }
    etat.envoi = true;
    boutonsActifs(form, false, '[type="submit"]');

    // Nettoyage de l'ancien message
    ecrireMessage(message, '');

    const { ok, statut, corps } = await envoyerFormulaire('actions/create_users.php', new FormData(form));
    if (ok) {
        ecrireMessage(message, corps.message, 'success');

        // Vider le formulaire
        form.reset();
        etat.disponible = null;
        etat.revision++;
        clearTimeout(etat.minuterie);
        ecrireMessage(form.querySelector('#identifiant-message'), 'Saisissez le nom et le prénom.');

        // Ton select custom
        const role = document.getElementById('role');
        if (role) role.value = '';

        // Créé depuis la fenêtre de la liste : on la referme, on le dit, et la liste
        // se recharge pour montrer le nouveau compte.
        const dialogue = form.closest('dialog');
        if (dialogue) {
            ecrireMessage(message, '');
            dialogue.close();
            if (window.toast) window.toast(corps.message);
            if (window.htmx) htmx.trigger(document.body, 'usersChanged');
        }
    } else {
        if (statut === 409) etat.disponible = null;
        ecrireMessage(message, corps?.message || 'Une erreur est survenue.', 'error');
    }
    etat.envoi = false;
    boutonsActifs(form, etat.disponible === identityKey(form), '[type="submit"]');
});

function heuresChiffrees(evenement) {
    const champ = evenement.target.closest?.('#prefa-form [name="pouces_total_iso"]');
    if (!champ) return;
    const form = champ.closest('form');
    const rate = Number(form.getAttribute('data-hours-per-inch'));
    const inches = Number(champ.value);
    const heures = form.querySelector('[name="heures_chiffrees"]');
    if (heures) heures.value = champ.value !== '' && champ.validity.valid ? (Math.round(inches * rate / 24 * 100) / 100).toFixed(2) : '';
}

document.addEventListener('input', heuresChiffrees);
document.addEventListener('change', heuresChiffrees);

document.addEventListener('submit', async evenement => {
    const form = evenement.target;
    if (form.id !== 'prefa-settings-form') return;
    evenement.preventDefault();
    const etat = etatFormulaire(form);
    if (etat.envoi) return;

    const message = form.querySelector('.prefa-message');
    message?.classList.remove('success', 'error');
    const hoursInput = form.querySelector('[name="duree_heures"]');
    const minutesInput = form.querySelector('[name="duree_minutes"]');

    if (hoursInput && minutesInput) {
        const h = parseInt(hoursInput.value, 10) || 0;
        const m = parseInt(minutesInput.value, 10) || 0;
        if (h === 0 && m === 0) {
            ecrireMessage(message, 'La durée doit être d’au moins 1 minute.', 'error');
            return;
        }
    }

    if (message) message.textContent = 'Enregistrement…';
    etat.envoi = true;
    boutonsActifs(form, false);
    const { ok, corps } = await envoyerFormulaire('actions/save_prefa_settings.php', new FormData(form));
    if (ok) {
        ecrireMessage(message, corps.message, 'success');
        if (corps.formatted) {
            form.querySelectorAll('.settings-preview-text').forEach(apercu => { apercu.textContent = corps.formatted; });
        }
    } else {
        ecrireMessage(message, corps?.message || 'Enregistrement impossible. Réessayez.', 'error');
    }
    etat.envoi = false;
    boutonsActifs(form, true);
});



// ===== Utilisateurs : les onglets de rôle et la fenêtre « Nouvel utilisateur » =====

document.addEventListener('click', evenement => {
    // L'onglet remplit le champ « role » du formulaire de recherche, et htmx fait le reste.
    const onglet = evenement.target.closest('.utilisateurs-onglet');
    if (onglet) {
        const form = document.getElementById('users-filters');
        if (!form) return;
        form.querySelector('[name="role"]').value = onglet.dataset.role;
        document.querySelectorAll('.utilisateurs-onglet').forEach(autre => {
            autre.setAttribute('aria-pressed', String(autre === onglet));
        });
        htmx.trigger(form, 'submit');
        return;
    }

    const nouveau = evenement.target.closest('.utilisateurs-nouveau');
    if (nouveau) {
        const dialogue = document.getElementById(nouveau.dataset.dialog);
        if (!dialogue) return;
        const form = dialogue.querySelector('form');
        form.reset();
        const role = document.getElementById('role');
        if (role) role.value = '';
        ecrireMessage(form.querySelector('#form-message'), '');
        form.querySelector('[name="name"]')?.dispatchEvent(new Event('input', { bubbles: true }));
        dialogue.showModal();
        form.querySelector('[name="prenom"]').focus();
        return;
    }

    evenement.target.closest('.utilisateur-dialogue-fermer')?.closest('dialog')?.close();

    // Un clic sur le voile, à côté de la fenêtre, la referme : le geste des fenêtres modales.
    if (evenement.target.matches('.utilisateur-dialogue')) evenement.target.close();
});
