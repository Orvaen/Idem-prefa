(() => {
    'use strict';

    // Sélectionner des lignes comme dans l'explorateur de fichiers : un clic choisit,
    // Ctrl ajoute, Maj étend depuis la dernière ligne cliquée, et un glissement dans le
    // vide trace un lasso. Une barre apparaît en bas dès qu'il y a une sélection.
    //
    // Rien à déclarer ligne par ligne : une table marquée data-selection, des lignes qui
    // portent data-id, et c'est tout. L'écran dit seulement ce que fait l'action en lot,
    // par data-selection-action et data-selection-libelle.
    //
    // Délégation sur le document : la liste arrive par htmx et se remplace à chaque filtre.

    const SEUIL = 4;   // en deçà, c'est un clic, pas un glissement

    let ancre = null;      // la dernière ligne cliquée sans Maj
    let lasso = null;      // le rectangle en cours de tracé
    let depart = null;

    const tables = () => [...document.querySelectorAll('[data-selection]')];
    // Une ligne marquée data-protege reste hors de toute sélection : la compter aurait
    // promis une suppression que le serveur refuse ensuite, et on ne l'apprendrait qu'après.
    // L'attribut dit aussi pourquoi, et la ligne l'affiche au survol.
    const lignes = table => [...table.querySelectorAll('tbody tr[data-id]:not([data-protege])')];
    const choisies = table => lignes(table).filter(l => l.classList.contains('est-choisie'));

    /** Les commandes d'une ligne ne sélectionnent pas : on y clique pour agir sur elle. */
    const estCommande = cible => !!cible.closest('button, a, input, select, textarea, label');

    function marquer(ligne, etat) {
        ligne.classList.toggle('est-choisie', etat);
        ligne.setAttribute('aria-selected', String(etat));
    }

    function vider(table) {
        lignes(table).forEach(l => marquer(l, false));
        barre(table);
    }

    function etendre(table, jusqua) {
        const toutes = lignes(table);
        const a = toutes.indexOf(ancre);
        const b = toutes.indexOf(jusqua);
        if (a < 0 || b < 0) return;
        const [debut, fin] = a < b ? [a, b] : [b, a];
        toutes.forEach((l, i) => marquer(l, i >= debut && i <= fin));
    }

    // ---------------------------------------------------------------- la barre d'action

    function barre(table) {
        const nombre = choisies(table).length;
        let pied = document.getElementById('selection-barre');

        if (!nombre) {
            pied?.remove();
            return;
        }
        if (!pied) {
            pied = document.createElement('div');
            pied.id = 'selection-barre';
            pied.className = 'selection-barre';
            pied.setAttribute('role', 'status');
            document.body.appendChild(pied);
        }
        const libelle = table.dataset.selectionLibelle || 'ligne';
        pied.innerHTML = '<span class="selection-compte"></span>'
            + '<button type="button" class="selection-vider">Tout désélectionner</button>'
            + '<button type="button" class="selection-supprimer"></button>';
        pied.querySelector('.selection-compte').textContent =
            nombre + ' ' + libelle + (nombre > 1 ? 's' : '') + (nombre > 1 ? ' sélectionnés' : ' sélectionné');
        pied.querySelector('.selection-supprimer').textContent =
            'Supprimer' + (nombre > 1 ? ' les ' + nombre : '');
        pied.querySelector('.selection-vider').onclick = () => vider(table);
        pied.querySelector('.selection-supprimer').onclick = () => supprimer(table);
    }

    async function supprimer(table) {
        const cibles = choisies(table);
        const noms = cibles.map(l => l.dataset.nom || l.dataset.id);
        const quoi = cibles.length === 1
            ? noms[0]
            : cibles.length + ' ' + (table.dataset.selectionLibelle || 'ligne') + 's';
        if (!window.confirm('Supprimer ' + quoi + ' ? Cette action est définitive.')) return;

        const corps = new URLSearchParams();
        corps.set('csrf', table.dataset.csrf || '');
        cibles.forEach(l => corps.append('ids[]', l.dataset.id));

        const bouton = document.querySelector('.selection-supprimer');
        if (bouton) bouton.disabled = true;
        try {
            const reponse = await fetch(table.dataset.selectionAction, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corps,
                credentials: 'same-origin',
            });
            const resultat = await reponse.json().catch(() => ({}));
            if (!reponse.ok || !resultat.success) {
                window.alert(resultat.message || 'Suppression impossible. Réessayez.');
                return;
            }
            if (resultat.resistants) {
                window.alert(resultat.supprimes + ' supprimé(s). ' + resultat.resistants
                    + ' n’ont pas pu l’être : ils ont déjà travaillé sur des demandes.');
            } else if (resultat.moiEcarte) {
                window.alert('Votre propre compte a été laissé de côté : on ne se supprime pas soi-même.');
            }
            document.getElementById('selection-barre')?.remove();
            // La liste se recharge seule : elle écoute cet événement depuis htmx.
            document.body.dispatchEvent(new CustomEvent('usersChanged'));
        } catch (erreur) {
            window.alert('Le serveur ne répond pas. Réessayez.');
        } finally {
            if (bouton) bouton.disabled = false;
        }
    }

    // ---------------------------------------------------------------- le clic

    document.addEventListener('click', evenement => {
        const ligne = evenement.target.closest?.('[data-selection] tbody tr[data-id]:not([data-protege])');
        if (!ligne) {
            // Un clic hors de toute liste efface la sélection, sauf s'il vise la barre.
            if (!evenement.target.closest?.('#selection-barre')) tables().forEach(vider);
            return;
        }
        if (estCommande(evenement.target)) return;

        const table = ligne.closest('[data-selection]');
        if (evenement.shiftKey && ancre) {
            // Le navigateur sélectionne le texte entre les deux clics : ce n'est pas ce
            // qu'on demande en tenant Maj sur une liste.
            window.getSelection()?.removeAllRanges();
            etendre(table, ligne);
        } else if (evenement.ctrlKey || evenement.metaKey) {
            marquer(ligne, !ligne.classList.contains('est-choisie'));
            ancre = ligne;
        } else {
            const seule = ligne.classList.contains('est-choisie') && choisies(table).length === 1;
            vider(table);
            marquer(ligne, !seule);
            ancre = seule ? null : ligne;
        }
        barre(table);
    });

    document.addEventListener('keydown', evenement => {
        if (evenement.key === 'Escape') tables().forEach(vider);
    });

    // ---------------------------------------------------------------- le lasso

    document.addEventListener('pointerdown', evenement => {
        if (evenement.button !== 0) return;
        const table = evenement.target.closest?.('[data-selection]');
        if (!table || estCommande(evenement.target)) return;

        depart = { x: evenement.clientX, y: evenement.clientY, table, ajoute: evenement.ctrlKey || evenement.metaKey };
    });

    document.addEventListener('pointermove', evenement => {
        if (!depart) return;
        const dx = Math.abs(evenement.clientX - depart.x);
        const dy = Math.abs(evenement.clientY - depart.y);
        if (!lasso && dx < SEUIL && dy < SEUIL) return;

        if (!lasso) {
            lasso = document.createElement('div');
            lasso.className = 'selection-lasso';
            document.body.appendChild(lasso);
            document.body.classList.add('selection-en-cours');
            if (!depart.ajoute) vider(depart.table);
        }
        // Le texte se surlignerait sous le lasso.
        window.getSelection()?.removeAllRanges();

        const gauche = Math.min(depart.x, evenement.clientX);
        const haut = Math.min(depart.y, evenement.clientY);
        const largeur = Math.abs(evenement.clientX - depart.x);
        const hauteur = Math.abs(evenement.clientY - depart.y);
        Object.assign(lasso.style, {
            left: gauche + 'px', top: haut + 'px',
            width: largeur + 'px', height: hauteur + 'px',
        });

        const bas = haut + hauteur;
        lignes(depart.table).forEach(l => {
            const r = l.getBoundingClientRect();
            // Il suffit que le lasso croise la ligne : viser une bande de quarante pixels
            // au pixel près serait pénible.
            const touche = r.bottom > haut && r.top < bas;
            if (touche) marquer(l, true);
            else if (!depart.ajoute) marquer(l, false);
        });
        barre(depart.table);
    });

    document.addEventListener('pointerup', () => {
        if (lasso) {
            lasso.remove();
            document.body.classList.remove('selection-en-cours');
            // Le clic qui suit le relâchement effacerait ce qu'on vient de tracer.
            const avale = e => e.stopPropagation();
            document.addEventListener('click', avale, { capture: true, once: true });
        }
        lasso = null;
        depart = null;
    });
})();
