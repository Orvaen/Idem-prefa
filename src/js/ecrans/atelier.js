(() => {
    // Plusieurs écrans partagent le même endpoint d'écriture : après une validation, on
    // recharge celui où l'on se trouve, et lui seul.
    //
    // « Mes plans » était le cas par défaut, et c'en était un de trop : le chef qui
    // validait une déclaration depuis « Suivi des soudures » se faisait recharger une page
    // réservée au personnel atelier. Le serveur répondait 403 et l'écran se vidait sur
    // « Impossible de charger vos affectations » — un message qui ne le concernait même pas.
    const pageOuverte = () => {
        if (document.getElementById('soudures-page')) return 'pages/soudures.php';
        if (document.getElementById('suivi-soudures-page')) return 'pages/suivi_soudures.php';
        return 'pages/atelier.php';
    };

    function refreshStaff(expanded = []) {
        if (!Array.isArray(expanded)) expanded = [];
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, pageOuverte()).then(({ ok }) => {
            if (!ok) {
                contenu.textContent = 'Impossible de recharger cet écran. Actualisez la page.';
                return;
            }
            expanded.forEach(id => {
                const row = document.getElementById(id);
                if (row) row.hidden = false;
                document.querySelectorAll('.prefa-toggle').forEach(button => {
                    if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
                });
            });
        });
    }
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.atelier-refresh')) refreshStaff(evenement);
    });
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.atelier-open-plan-create');
        if (!bouton) return;
        const dialog = document.getElementById(bouton.dataset.dialog);
        if (!dialog) return;
        // Le dialogue est rendu dans le panneau « Plans & affectations », masqué tant qu'il est replié :
        // on le sort du panneau le temps de l'afficher, sinon il faudrait le déplier pour voir la fenêtre.
        dialog.atelierHome ??= dialog.parentNode;
        document.body.append(dialog);
        dialog.addEventListener('close', () => { if (!dialog.open) dialog.atelierHome.append(dialog); }, { once: true });
        const form = dialog.querySelector('form');
        form.reset();
        form.querySelector('.atelier-message').textContent = '';
        dialog.showModal();
        form.elements.reference.focus();
    });
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.atelier-open-plan-edit');
        if (!bouton) return;
        const dialog = document.getElementById(bouton.dataset.dialog);
        if (!dialog) return;
        const form = dialog.querySelector('form');
        form.reset();
        form.elements.element.value = bouton.dataset.element;
        form.elements.reference.value = bouton.dataset.reference;
        form.elements.libelle.value = bouton.dataset.description;
        form.querySelector('.atelier-message').textContent = '';
        dialog.showModal();
        form.elements.reference.focus();
    });
    // Le pourcentage qui part sur le planning, montré pendant la frappe : l’atelier compte
    // des cordons, et voir « soit 86 % » au moment de taper 6 sur 7 évite de découvrir le
    // chiffre après coup.
    function montrerQuotient(champ) {
        const total = Number(champ.dataset.total || champ.max) || 0;
        const faites = Math.min(Math.max(Number(champ.value) || 0, 0), total);
        const sortie = champ.closest('div')?.querySelector('.atelier-quotient');
        if (sortie) sortie.textContent = total ? 'soit ' + Math.round(faites * 100 / total) + ' %' : '';
    }
    document.addEventListener('input', evenement => {
        const champ = evenement.target.closest('input[name="soudures"]');
        if (champ) montrerQuotient(champ);
    });

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.atelier-edit-progress');
        if (!bouton) return;
        const dialog = document.getElementById(bouton.dataset.dialog);
        if (!dialog) return;
        const form = dialog.querySelector('form');
        form.reset();
        form.elements.affectation.value = bouton.dataset.affectation;
        form.elements.revision.value = bouton.dataset.revision;
        form.elements.avancement.value = bouton.dataset.avancement;
        form.elements.commentaire.value = bouton.dataset.commentaire;
        dialog.querySelector('.atelier-progress-target').textContent = bouton.dataset.label;
        form.querySelector('.atelier-message').textContent = '';

        // Ce plan porte-t-il une liste de soudures ? Elle décide du champ présenté, et
        // désactive l’autre : laissé actif, il partirait aussi, et le serveur aurait deux
        // réponses à la même question.
        const total = Number(bouton.dataset.soudures) || 0;
        const soudures = form.elements.soudures;
        const pourcentage = form.elements.avancement;
        dialog.querySelector('.atelier-champ-soudures').hidden = !total;
        dialog.querySelector('.atelier-champ-pourcentage').hidden = total > 0;
        soudures.disabled = !total;
        soudures.required = total > 0;
        pourcentage.disabled = total > 0;
        pourcentage.required = !total;
        if (total) {
            soudures.max = total;
            soudures.value = Math.round(Number(bouton.dataset.avancement) * total / 100);
            // La phrase est écrite côté PHP et voyage sur le bouton : le même texte sert
            // ici et sur l’écran de l’atelier, sans être composé deux fois.
            dialog.querySelector('.atelier-soudures-origine').textContent = bouton.dataset.origine || '';
            montrerQuotient(soudures);
        }

        dialog.showModal();
        (total ? soudures : pourcentage).focus();
    });
    // Les renvois d'un écran à l'autre. Ils cliquent l'entrée du menu plutôt que de charger
    // la page eux-mêmes : elle seule tient l'onglet actif et l'historique de navigation.
    //
    // Ils sont ici, et non dans soudures.js, parce que l'encadrement ne charge pas ce
    // fichier-là : il n'a pas « Mes soudures », il a « Soudures ».
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.atelier-vers-soudures')) document.getElementById('btn-soudures')?.click();
    });
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.atelier-vers-suivi-soudures')) document.getElementById('btn-suivi-soudures')?.click();
    });

    document.addEventListener('click', evenement => {
        evenement.target.closest('.atelier-close-plan-edit')?.closest('dialog')?.close();
    });

    // ===== « Mes plans » : la recherche et les onglets =====
    // Sur le modèle de « Plans / ISO » (iso.js) : tous les plans confiés sont dans la page,
    // on filtre sur place. Onglet et recherche se combinent ; un titre de groupe disparaît
    // avec sa dernière ligne, et chaque compte dit ce qui reste visible.
    function filtrerMesPlans() {
        const page = document.getElementById('mes-plans-page');
        if (!page) return;
        const recherche = (document.getElementById('mes-plans-recherche')?.value || '').trim().toLowerCase();
        const onglet = page.querySelector('.mes-plans-onglet[aria-pressed="true"]')?.dataset.groupe || '';

        const parGroupe = {};
        page.querySelectorAll('.mes-plans-plan').forEach(plan => {
            const trouve = !recherche || (plan.dataset.cherche || '').includes(recherche);
            if (trouve) parGroupe[plan.dataset.groupe] = (parGroupe[plan.dataset.groupe] || 0) + 1;
            plan.hidden = !trouve || (onglet !== '' && plan.dataset.groupe !== onglet);
        });

        let visibles = 0;
        page.querySelectorAll('.mes-plans-groupe').forEach(titre => {
            const nombre = parGroupe[titre.dataset.groupe] || 0;
            const affiche = nombre > 0 && (onglet === '' || titre.dataset.groupe === onglet);
            titre.hidden = !affiche;
            titre.querySelector('.prefa-groupe-compte').textContent = nombre;
            if (affiche) visibles += nombre;
        });

        page.querySelectorAll('.mes-plans-onglet').forEach(bouton => {
            const groupe = bouton.dataset.groupe;
            const total = groupe === '' ? Object.values(parGroupe).reduce((a, b) => a + b, 0) : (parGroupe[groupe] || 0);
            bouton.querySelector('.prefa-onglet-compte').textContent = total;
        });

        const aucun = page.querySelector('.mes-plans-aucun-resultat');
        if (aucun) aucun.hidden = visibles > 0;
    }
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.mes-plans-onglet');
        if (!bouton) return;
        document.querySelectorAll('.mes-plans-onglet').forEach(onglet => {
            onglet.setAttribute('aria-pressed', String(onglet === bouton));
        });
        filtrerMesPlans();
    });
    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#mes-plans-recherche')) filtrerMesPlans();
    });
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.atelier-tab');
        if (!bouton) return;
        bouton.closest('.atelier-tablist').querySelectorAll('.atelier-tab').forEach(onglet => {
            const actif = onglet === bouton;
            onglet.setAttribute('aria-selected', String(actif));
            onglet.tabIndex = actif ? 0 : -1;
            document.getElementById(onglet.getAttribute('aria-controls')).hidden = !actif;
        });
    });
    document.addEventListener('keydown', event => {
        const bouton = event.target.closest('.atelier-tab');
        if (!bouton) return;
        const pas = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        if (!pas) return;
        event.preventDefault();
        const onglets = Array.from(bouton.closest('.atelier-tablist').querySelectorAll('.atelier-tab'));
        const suivant = onglets[(onglets.indexOf(bouton) + pas + onglets.length) % onglets.length];
        suivant.focus();
        suivant.click();
    });
    // ===== Affecter une personne : un menu sous le bouton =====
    //
    // Le choix se faisait dans un panneau ouvert tout en bas du plan, sous les feuilles
    // ISO et les documents : on cliquait un poste en haut, on descendait chercher la liste,
    // on choisissait, on enregistrait. Le menu s'ouvre maintenant sous le bouton cliqué, et
    // choisir un nom enregistre aussitôt.
    //
    // Il ne réinvente pas l'envoi : il remplit le formulaire caché du plan (jeton, poste,
    // personne) et le soumet, et c'est le même chemin qu'avant qui écrit et recharge.
    let menuOuvert = null;

    function fermerMenuAffectation(rendreLeFocus) {
        if (!menuOuvert) return;
        const { menu, bouton } = menuOuvert;
        menuOuvert = null;
        menu.remove();
        bouton.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', surClicHorsDuMenu, true);
        if (rendreLeFocus) bouton.focus();
    }

    function surClicHorsDuMenu(evenement) {
        if (menuOuvert && !menuOuvert.menu.contains(evenement.target) && evenement.target !== menuOuvert.bouton) {
            fermerMenuAffectation(false);
        }
    }

    const COCHE = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>';

    function ouvrirMenuAffectation(bouton) {
        const panneau = document.getElementById(bouton.dataset.form);
        const form = panneau?.querySelector('form');
        if (!form) return;
        const actuel = bouton.dataset.user || '';
        const personnes = Array.from(form.elements.utilisateur.options).filter(option => option.value);

        const menu = document.createElement('div');
        menu.className = 'atelier-affecter-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', (actuel ? 'Changer la personne : ' : 'Affecter : ') + bouton.dataset.label);

        if (!personnes.length) {
            menu.innerHTML = '<p class="atelier-affecter-vide">Aucun membre du personnel atelier.</p>';
        }

        // La recherche, en tête : on tape trois lettres d'un nom plutôt que de le chercher
        // des yeux dans la liste. Elle ignore la casse et les accents — « helene » trouve
        // « Hélène ».
        let recherche = null;
        if (personnes.length) {
            const zone = document.createElement('div');
            zone.className = 'atelier-affecter-recherche';
            zone.innerHTML = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>';
            recherche = document.createElement('input');
            recherche.type = 'search';
            recherche.placeholder = 'Rechercher une personne…';
            recherche.setAttribute('aria-label', 'Rechercher une personne');
            recherche.autocomplete = 'off';
            zone.append(recherche);
            menu.append(zone);
        }
        const liste = document.createElement('div');
        liste.className = 'atelier-affecter-liste';
        menu.append(liste);
        const aucun = document.createElement('p');
        aucun.className = 'atelier-affecter-vide';
        aucun.textContent = 'Personne de ce nom.';
        aucun.hidden = true;

        personnes.forEach(option => {
            const choix = document.createElement('button');
            choix.type = 'button';
            choix.className = 'atelier-affecter-choix';
            choix.setAttribute('role', 'menuitemradio');
            choix.setAttribute('aria-checked', String(option.value === actuel));
            choix.dataset.user = option.value;
            choix.innerHTML = '<span class="atelier-affecter-coche">' + (option.value === actuel ? COCHE : '') + '</span>';
            choix.append(option.textContent);
            choix.dataset.cherche = sansAccents(option.textContent);
            liste.append(choix);
        });
        liste.append(aucun);

        recherche?.addEventListener('input', () => {
            const cherche = sansAccents(recherche.value.trim());
            let visibles = 0;
            liste.querySelectorAll('.atelier-affecter-choix').forEach(choix => {
                choix.hidden = cherche !== '' && !choix.dataset.cherche.includes(cherche);
                if (!choix.hidden) visibles++;
            });
            aucun.hidden = visibles > 0;
        });
        if (actuel) {
            const filet = document.createElement('div');
            filet.className = 'atelier-affecter-filet';
            filet.setAttribute('role', 'separator');
            const retirer = document.createElement('button');
            retirer.type = 'button';
            retirer.className = 'atelier-affecter-retirer';
            retirer.setAttribute('role', 'menuitem');
            retirer.textContent = 'Retirer l’affectation';
            menu.append(filet, retirer);
        }
        const mot = document.createElement('p');
        mot.className = 'atelier-affecter-mot';
        mot.setAttribute('role', 'status');
        mot.hidden = true;
        menu.append(mot);

        // Posé dans la ligne du poste, sous le bouton : il défile avec le volet.
        const ligne = bouton.closest('.atelier-plan-work') || bouton.parentNode;
        ligne.append(menu);
        menu.style.top = (bouton.offsetTop + bouton.offsetHeight + 4) + 'px';
        menu.style.left = bouton.offsetLeft + 'px';

        menuOuvert = { menu, bouton, form, actuel };
        bouton.setAttribute('aria-expanded', 'true');
        document.addEventListener('pointerdown', surClicHorsDuMenu, true);
        (recherche || menu.querySelector('button'))?.focus();
        // La personne en place reste à portée de vue dans une longue liste.
        menu.querySelector('[aria-checked="true"]')?.scrollIntoView({block: 'nearest'});
    }

    const sansAccents = texte => texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    function envoyerAffectation(utilisateur) {
        if (!menuOuvert) return;
        const { menu, bouton, form, actuel } = menuOuvert;
        if (utilisateur === actuel) { fermerMenuAffectation(true); return; }
        form.elements.type.value = bouton.dataset.type;
        form.elements.utilisateur.value = utilisateur || actuel;
        menu.querySelectorAll('button, input').forEach(b => { b.disabled = true; });
        const mot = menu.querySelector('.atelier-affecter-mot');
        mot.hidden = false;
        mot.textContent = 'Enregistrement…';
        const envoi = utilisateur
            ? form.querySelector('button[type="submit"]:not(.atelier-remove-assignment)')
            : form.querySelector('.atelier-remove-assignment');
        form.requestSubmit(envoi);
    }

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.atelier-edit-assignment');
        if (!bouton) return;
        const memeBouton = menuOuvert?.bouton === bouton;
        fermerMenuAffectation(false);
        if (!memeBouton) ouvrirMenuAffectation(bouton);
    });
    document.addEventListener('click', evenement => {
        const choix = evenement.target.closest('.atelier-affecter-choix');
        if (choix) envoyerAffectation(choix.dataset.user);
    });
    document.addEventListener('click', evenement => {
        if (evenement.target.closest('.atelier-affecter-retirer')) envoyerAffectation('');
    });

    // Le clavier : flèches pour parcourir, Échap pour refermer sans rien changer. Échap ne
    // remonte pas plus haut — sans cela, il refermait aussi le volet.
    //
    // Depuis la recherche : Flèche bas descend dans la liste, Entrée choisit le premier nom
    // trouvé. Depuis le premier nom, Flèche haut remonte dans la recherche.
    document.addEventListener('keydown', function (evenement) {
        if (!menuOuvert || !menuOuvert.menu.contains(document.activeElement)) return;
        const recherche = menuOuvert.menu.querySelector('.atelier-affecter-recherche input');
        const items = Array.from(menuOuvert.menu.querySelectorAll('button:not(:disabled):not([hidden])'));
        const rang = items.indexOf(document.activeElement);
        const dansRecherche = document.activeElement === recherche;
        if (evenement.key === 'Escape') {
            evenement.preventDefault();
            evenement.stopImmediatePropagation();
            fermerMenuAffectation(true);
        } else if (dansRecherche && evenement.key === 'Enter') {
            evenement.preventDefault();
            const premier = items.find(item => item.classList.contains('atelier-affecter-choix'));
            if (premier) envoyerAffectation(premier.dataset.user);
        } else if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
            evenement.preventDefault();
            if (dansRecherche) {
                (evenement.key === 'ArrowDown' ? items[0] : items[items.length - 1])?.focus();
                return;
            }
            if (recherche && evenement.key === 'ArrowUp' && rang === 0) { recherche.focus(); return; }
            const pas = evenement.key === 'ArrowDown' ? 1 : -1;
            items[(rang + pas + items.length) % items.length]?.focus();
        } else if (!dansRecherche && (evenement.key === 'Home' || evenement.key === 'End')) {
            evenement.preventDefault();
            items[evenement.key === 'Home' ? 0 : items.length - 1]?.focus();
        } else if (evenement.key === 'Tab') {
            fermerMenuAffectation(false);
        }
    }, true);

    // Les contrôles d'une validation. La case « Tous les cordons » d'une colonne la coche ou
    // la décoche entière ; elle se recoche d'elle-même quand chaque cordon l'est, et se
    // décoche dès qu'un seul ne l'est plus. Elle n'a pas de nom : elle ne part pas avec le
    // formulaire, seules les cases des cordons comptent.
    //
    // « :scope > tbody » et non « tbody » : la liste vit dans la ligne d'un autre tableau, et
    // le sélecteur seul trouvait aussi le tbody de celui-ci — la case « Tous » se comptait
    // alors parmi les cordons, et ne se recochait jamais.
    document.addEventListener('change', evenement => {
        const croix = evenement.target.closest('.soudure-controles .soudure-croix');
        if (!croix) return;
        const table = croix.closest('table');
        const controle = croix.dataset.controle;
        const cases = table.querySelectorAll(`:scope > tbody .soudure-croix[data-controle="${controle}"]`);
        const tout = table.querySelector(`.soudure-controles-tout[data-controle="${controle}"]`);
        if (croix === tout) cases.forEach(caseCordon => { caseCordon.checked = tout.checked; });
        else if (tout) tout.checked = Array.from(cases).every(caseCordon => caseCordon.checked);
    });

    // Les rechargements après un enregistrement rouvrent les lignes dépliées avant.
    function redeplier(expanded) {
        expanded.forEach(id => {
            const row = document.getElementById(id);
            if (row) row.hidden = false;
            document.querySelectorAll('.prefa-toggle').forEach(button => {
                if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
            });
        });
    }

    document.addEventListener('submit', async event => {
        const form = event.target.closest('.atelier-action');
        if (!form) return;
        event.preventDefault();
        const endpoint = form.dataset.endpoint;
        if (!['save_atelier.php', 'update_atelier.php'].includes(endpoint)) return;
        if (form.classList.contains('atelier-delete-plan') && !await window.confirmer(
            'Supprimer le plan « ' + form.dataset.planName + ' » ?', {
                texte: 'Ses affectations et leur suivi seront supprimés. Les fichiers joints à la demande seront conservés.',
                action: 'Supprimer',
                ton: 'danger',
            })) return;
        // Les champs texte seuls, comme un formulaire sérialisé : un fichier ne part pas.
        const data = new URLSearchParams();
        new FormData(form).forEach((valeur, nom) => {
            if (typeof valeur === 'string') data.append(nom, valeur);
        });
        // Le FormData est pris sans le bouton qui a envoyé le formulaire : deux boutons dans
        // un même formulaire ont donc besoin qu’on les relise ici.
        const submitter = event.submitter;
        if (submitter?.value === 'revoke') data.set('operation', 'revoke');
        if (submitter?.name === 'decision') data.set('decision', submitter.value);
        const messages = form.querySelectorAll('.atelier-message');
        const ecrireMessage = texte => messages.forEach(message => { message.textContent = texte; });
        const buttons = form.querySelectorAll('button');
        buttons.forEach(bouton => { bouton.disabled = true; });
        ecrireMessage('Enregistrement…');
        const expanded = Array.from(document.querySelectorAll('.prefa-toggle[aria-expanded="true"]')).map(button => button.getAttribute('aria-controls'));

        // Réussi : une réponse 2xx, et du JSON lisible.
        let reussi = false;
        let corps = null;
        try {
            const reponse = await fetch('actions/' + endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: data.toString(),
            });
            corps = await reponse.json().catch(() => null);
            reussi = reponse.ok && corps !== null;
        } catch {
            reussi = false;
        }
        buttons.forEach(bouton => { bouton.disabled = false; });

        if (!reussi) {
            const texte = corps?.message || 'Enregistrement impossible. Réessayez.';
            ecrireMessage(texte);
            // Le formulaire d'affectation est caché : son message se dit dans le menu
            // resté ouvert, dont les choix redeviennent cliquables.
            if (menuOuvert && menuOuvert.form === form) {
                const mot = menuOuvert.menu.querySelector('.atelier-affecter-mot');
                mot.hidden = false;
                mot.textContent = texte;
                mot.classList.add('est-erreur');
                menuOuvert.menu.querySelectorAll('button, input').forEach(b => { b.disabled = false; });
            }
            return;
        }

        form.closest('dialog')?.close();
        if (menuOuvert && menuOuvert.form === form) fermerMenuAffectation(false);
        // « Mes plans » et « Mes soudures » se rechargent elles-mêmes. Les
        // écrans de l'encadrement, non : la page Plans / ISO et la liste des demandes
        // ont chacune leur façon de se remettre à jour, plus bas. Sans cette
        // exception, valider un avancement depuis la fiche d'une demande emmenait le
        // chef sur « Mes soudures », qu'il n'a même pas.
        if (endpoint === 'update_atelier.php'
            && !document.getElementById('plans-iso-page')
            && !document.getElementById('prefa-filters')) { refreshStaff(expanded); return; }
        // Les deux écrans ci-dessus ont leur propre façon de se remettre à jour,
        // juste en dessous.
        const prefaFilters = document.getElementById('prefa-filters');
        if (prefaFilters) {
            const params = new URLSearchParams(new FormData(prefaFilters));
            params.set('results', '1');
            fetch('pages/lists_prefa.php?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(async reponse => {
                    if (!reponse.ok) throw new Error(String(reponse.status));
                    const modele = document.createElement('template');
                    modele.innerHTML = await reponse.text();
                    document.getElementById('prefa-results')?.replaceWith(modele.content);
                    redeplier(expanded);
                })
                .catch(() => ecrireMessage('Enregistrement effectué. Actualisez pour voir le résultat.'));
        } else if (document.getElementById('plans-iso-page')) {
            const openCardIds = Array.from(document.querySelectorAll('.plans-iso-detail:not([hidden])')).map(c => c.id);
            // Enregistrement fait depuis une fenêtre : déplier le panneau pour montrer le résultat.
            const detailId = 'plans-iso-detail-' + form.elements.id.value;
            if (!openCardIds.includes(detailId)) openCardIds.push(detailId);
            // Le volet revient sur le plan où l'on était, à la même hauteur : il
            // rouvrait sur le premier plan, en haut, après chaque affectation faite
            // sur le deuxième.
            const ongletsActifs = Array.from(document.querySelectorAll('.plans-iso-detail:not([hidden]) .atelier-tab[aria-selected="true"]')).map(onglet => onglet.id);
            const defilements = Object.fromEntries(openCardIds.map(id => [id, document.getElementById(id)?.scrollTop || 0]));
            const contenu = document.getElementById('content');
            if (!contenu) return;
            window.chargerDans(contenu, 'pages/plans_iso.php').then(() => {
                openCardIds.forEach(id => {
                    const card = document.getElementById(id);
                    if (card) {
                        card.hidden = false;
                        document.querySelectorAll('.plans-iso-toggle').forEach(button => {
                            if (button.getAttribute('aria-controls') === id) button.setAttribute('aria-expanded', 'true');
                        });
                    }
                });
                ongletsActifs.forEach(id => {
                    const onglet = document.getElementById(id);
                    if (onglet && onglet.getAttribute('aria-selected') !== 'true') onglet.click();
                });
                Object.entries(defilements).forEach(([id, haut]) => {
                    const card = document.getElementById(id);
                    if (card) card.scrollTop = haut;
                });
            });
        }
    });
})();
