(() => {
    // « Mes soudures » : on choisit une feuille, son tableau arrive, on le remplit.
    //
    // La liste n'est pas rendue avec la page. Elle est reconnue sur le PDF du plan, et la
    // première lecture d'une page coûte quelques secondes : relire les quinze feuilles d'un
    // cahier avant d'afficher quoi que ce soit ferait attendre une demi-minute devant du
    // vide. On ne lit donc que la feuille regardée, au moment où on la regarde.
    const page = () => document.getElementById('soudures-page');

    function vues() {
        const racine = page();
        if (!racine) return null;
        const choix = racine.querySelector('#soudures-feuille');
        const corps = racine.querySelector('#soudures-corps');
        const form = racine.querySelector('#soudures-form');
        return choix && corps && form ? { racine, choix, corps, form } : null;
    }

    /** « élément:feuille:page » — un seul choix, parce que c'est une seule question. */
    function repere(choix) {
        const [element, document_, numero] = (choix.value || '').split(':');
        return { element, document: document_, page: numero };
    }

    function chiffre(racine, nom, valeur, note) {
        const zone = racine.querySelector('[data-chiffre="' + nom + '"]');
        if (zone) zone.textContent = valeur;
        const legende = racine.querySelector('[data-note="' + nom + '"]');
        if (legende && note !== undefined) legende.textContent = note;
    }

    function jauge(racine, faites, total) {
        const barre = racine.querySelector('[data-jauge]');
        if (barre) barre.style.width = total > 0 ? Math.round(faites * 100 / total) + '%' : '0';
    }

    function attente(corps, texte) {
        corps.replaceChildren();
        const li = document.createElement('li');
        li.className = 'soudures-attente';
        li.textContent = texte;
        corps.append(li);
    }

    /** Une pastille par contrôle passé, et le visa s'il est posé. Rien sinon. */
    function controles(ligne) {
        const zone = document.createDocumentFragment();
        ['rt', 'vi', 'pt'].forEach(nom => {
            if (!ligne[nom]) return;
            const marque = document.createElement('span');
            marque.className = 'soudures-controle';
            marque.textContent = nom.toUpperCase();
            marque.title = 'Contrôle ' + nom.toUpperCase() + ' porté sur le plan.';
            zone.append(marque);
        });
        if (ligne.visa) {
            const visa = document.createElement('span');
            visa.className = 'soudures-visa';
            visa.textContent = 'visa';
            visa.title = 'Visé par ' + ligne.visa + '.';
            zone.append(visa);
        }
        return zone;
    }

    function poser(corps, lignes) {
        const modele = document.getElementById('soudures-modele');
        if (!modele) return;
        corps.replaceChildren();
        lignes.forEach(ligne => {
            const li = modele.content.firstElementChild.cloneNode(true);
            li.dataset.numero = ligne.numero;
            li.classList.toggle('est-faite', Boolean(ligne.remplie));
            li.classList.toggle('est-verrouillee', Boolean(ligne.verrouillee));
            li.classList.toggle('est-hors-tableau', !ligne.imprimee);

            li.querySelector('.soudures-numero').textContent = ligne.numero;
            if (!ligne.imprimee) {
                li.querySelector('.soudures-numero').title =
                    'Ce repère n’a pas de ligne imprimée sur la feuille : il a été ajouté.';
            }
            li.querySelector('.soudures-soudeur').textContent = ligne.soudeur || '';
            li.querySelector('.soudures-controles').append(controles(ligne));

            const date = li.querySelector('.soudures-date');
            const type = li.querySelector('.soudures-type');
            date.value = ligne.date || '';
            type.value = ligne.type || '';
            // Le cordon de quelqu'un d'autre se lit, ne se reprend pas : son nom part sur le
            // plan final. Le serveur le redit de son côté — une case grisée ne protège rien.
            date.disabled = Boolean(ligne.verrouillee);
            type.disabled = Boolean(ligne.verrouillee);
            if (ligne.verrouillee) {
                date.title = 'Soudure déclarée par ' + (ligne.soudeur || 'quelqu’un d’autre') + '.';
                type.title = date.title;
            }
            corps.append(li);
        });
    }

    /** Les trois chiffres, la jauge et le compte du pied, d'une seule réponse du serveur. */
    function montrer(racine, corps, reponse) {
        const total = reponse.imprimees || corps.querySelectorAll('.soudures-ligne').length;
        const faites = corps.querySelectorAll('.soudures-ligne.est-faite').length;

        chiffre(racine, 'feuille', total ? faites + ' / ' + total : '—',
            total ? 'cordons renseignés sur cette feuille' : (reponse.message || 'aucune ligne reconnue'));
        const plan = reponse.plan || {};
        chiffre(racine, 'plan', plan.prevues ? plan.faites + ' / ' + plan.prevues : '—',
            plan.prevues ? (plan.feuilles || 'soudures prévues au plan') : 'aucune feuille lue');
        chiffre(racine, 'valide', (reponse.valide ?? 0) + ' %');
        jauge(racine, plan.faites || 0, plan.prevues || 0);

        const compte = racine.querySelector('.soudures-compte');
        if (compte) {
            compte.replaceChildren();
            if (total) {
                const fort = document.createElement('strong');
                fort.textContent = faites + ' sur ' + total;
                compte.append(fort, ' renseignée' + (faites > 1 ? 's' : '') + ' sur cette feuille');
            }
        }
    }

    async function charger() {
        const v = vues();
        if (!v) return;
        const { racine, choix, corps, form } = v;
        const cle = repere(choix);
        if (!cle.element) return;

        attente(corps, 'Lecture de la feuille — quelques secondes à sa première ouverture.');
        chiffre(racine, 'feuille', '—', 'lecture en cours…');
        form.dataset.element = cle.element;
        form.dataset.document = cle.document;
        form.dataset.page = cle.page;

        const donnees = new URLSearchParams();
        donnees.set('csrf', form.dataset.csrf);
        donnees.set('element', cle.element);
        donnees.set('document', cle.document);
        donnees.set('page', cle.page);

        try {
            const reponse = await fetch('actions/soudures_feuille.php', {
                method: 'POST', body: donnees,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const corpsJson = await reponse.json();
            if (!reponse.ok || !corpsJson.success) {
                attente(corps, corpsJson.message || 'Lecture impossible.');
                return;
            }
            if (!corpsJson.lignes.length) {
                attente(corps, corpsJson.message || 'Aucune ligne de soudure sur cette feuille.');
                montrer(racine, corps, corpsJson);
                return;
            }
            poser(corps, corpsJson.lignes);
            montrer(racine, corps, corpsJson);
        } catch {
            attente(corps, 'Le serveur ne répond pas.');
        }
    }

    $(document).on('change', '#soudures-feuille', charger);

    // « Ouvrir la feuille » est servi par plan-viewer.js : c'est lui qui sait aller à la
    // bonne page d'un cahier, et dupliquer ce savoir ici le ferait diverger.

    $(document).on('submit', '#soudures-form', async function (event) {
        event.preventDefault();
        const v = vues();
        if (!v) return;
        const { racine, corps, form } = v;
        const retour = form.querySelector('.atelier-message');
        const boutons = form.querySelectorAll('button');

        const donnees = new URLSearchParams();
        donnees.set('csrf', form.dataset.csrf);
        donnees.set('element', form.dataset.element);
        donnees.set('document', form.dataset.document);
        donnees.set('page', form.dataset.page);
        corps.querySelectorAll('.soudures-ligne').forEach(li => {
            const date = li.querySelector('.soudures-date');
            const type = li.querySelector('.soudures-type');
            // Les lignes verrouillées ne partent pas : le serveur les refuserait, et les
            // envoyer ferait croire qu'on a essayé de les changer.
            if (date.disabled && type.disabled) return;
            donnees.set('soudures[' + li.dataset.numero + '][date]', date.value || '');
            donnees.set('soudures[' + li.dataset.numero + '][type]', type.value || '');
        });

        boutons.forEach(b => { b.disabled = true; });
        retour.textContent = 'Enregistrement…';
        try {
            const reponse = await fetch('actions/save_soudures_atelier.php', {
                method: 'POST', body: donnees,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const corpsJson = await reponse.json();
            if (!reponse.ok || !corpsJson.success) {
                retour.textContent = corpsJson.message || 'Enregistrement impossible.';
                return;
            }
            poser(corps, corpsJson.lignes);
            montrer(racine, corps, corpsJson);
            retour.textContent = corpsJson.message || 'Enregistré.';
        } catch {
            retour.textContent = 'Le serveur ne répond pas.';
        } finally {
            boutons.forEach(b => { b.disabled = false; });
        }
    });

    // La page arrive par AJAX dans #content : rien ne se « charge » au sens du navigateur,
    // et il faut donc lancer la première lecture soi-même, une fois, dès qu'elle est posée.
    function amorcer() {
        const racine = page();
        if (!racine || racine.dataset.pret) return;
        racine.dataset.pret = '1';
        charger();
    }
    $(document).ajaxComplete(amorcer);
    amorcer();

    $(document).on('click', '.atelier-vers-soudures', function () {
        document.getElementById('btn-soudures')?.click();
    });
})();
