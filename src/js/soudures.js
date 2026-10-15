(() => {
    // « Mes soudures » : on choisit sa feuille dans le tableau, son tableau de cordons
    // arrive dessous, on le remplit.
    //
    // La liste des cordons n'est pas rendue avec la page. Elle est reconnue sur le PDF du
    // plan, et la première lecture d'une page coûte quelques secondes : relire les quinze
    // feuilles d'un cahier avant d'afficher quoi que ce soit ferait attendre une
    // demi-minute devant du vide. On ne lit donc que la feuille regardée, au moment où on
    // la regarde — le tableau des feuilles, lui, se contente de ce qui est déjà connu.
    const page = () => document.getElementById('soudures-page');

    function vues() {
        const racine = page();
        if (!racine) return null;
        const corps = racine.querySelector('#soudures-corps');
        const form = racine.querySelector('#soudures-form');
        return corps && form ? { racine, corps, form } : null;
    }

    /** La feuille travaillée : c'est la ligne marquée du tableau, et elle porte tout. */
    const ligneChoisie = racine => racine.querySelector('.soudures-feuille.est-choisie');

    /** « élément:feuille:page » — un seul repère, parce que c'est une seule question. */
    function repere(ligne) {
        const [element, document_, numero] = String((ligne && ligne.dataset.repere) || '').split(':');
        return { element, document: document_, page: numero };
    }

    /** Ce que le tableau dit quand il n'a pas de lignes à montrer : une seule cellule. */
    function attente(corps, texte) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.className = 'prefa-table-empty';
        td.textContent = texte;
        tr.append(td);
        corps.replaceChildren(tr);
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

    /**
     * La ligne de la feuille qu'on vient de lire ou d'enregistrer suit : son compte de
     * cordons, et son état.
     *
     * C'est le serveur qui les dit, et non ce script : une feuille jamais ouverte ignorait
     * son total et vient de l'apprendre, et le libellé de l'état est celui de l'écran du
     * chef. Deux listes de libellés à tenir d'accord finiraient par ne plus l'être.
     */
    function majTableau(racine, reponse) {
        const ligne = ligneChoisie(racine);
        const feuille = reponse.feuille;
        if (!ligne || !feuille) return;

        const compte = ligne.querySelector('[data-cordons]');
        if (compte && feuille.compte) compte.textContent = feuille.compte;
        const etat = ligne.querySelector('[data-etat]');
        if (etat && feuille.etat) {
            etat.textContent = feuille.etat.libelle;
            etat.className = 'prefa-status ' + feuille.etat.classe;
        }
    }

    /**
     * Ce que la réponse du serveur déplace à l'écran : le compte dans l'en-tête de la
     * feuille, celui du pied, et la ligne de la feuille dans le tableau de son plan.
     *
     * Un seul compte, dit deux fois au même endroit — en haut à côté du nom, en bas à côté
     * du bouton qui enregistre. L'écran en portait trois, sur une ligne où ils se
     * touchaient : on ne savait plus lequel parlait de quoi.
     */
    function montrer(racine, corps, reponse) {
        const total = reponse.imprimees || corps.querySelectorAll('.soudures-ligne').length;
        const faites = corps.querySelectorAll('.soudures-ligne.est-faite').length;

        const entete = racine.querySelector('[data-feuille-compte]');
        if (entete) {
            entete.textContent = total
                ? faites + ' / ' + total + ' cordons renseignés'
                : (reponse.message || 'aucune ligne reconnue sur cette feuille');
        }
        majTableau(racine, reponse);

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
        const { racine, corps, form } = v;
        const cle = repere(ligneChoisie(racine));
        if (!cle.element) return;

        attente(corps, 'Lecture de la feuille — quelques secondes à sa première ouverture.');
        const entete = racine.querySelector('[data-feuille-compte]');
        if (entete) entete.textContent = 'lecture en cours…';
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

    /**
     * Choisir une feuille : sa ligne se marque, l'en-tête de la saisie prend son nom, et
     * son tableau se lit. Rien n'est rechargé — la page entière est déjà là.
     */
    function choisir(ligne) {
        const racine = page();
        if (!racine || !ligne) return;
        if (ligne.classList.contains('est-choisie')) return;

        racine.querySelectorAll('.soudures-feuille').forEach(autre => {
            const choisie = autre === ligne;
            autre.classList.toggle('est-choisie', choisie);
            if (choisie) autre.setAttribute('aria-current', 'true');
            else autre.removeAttribute('aria-current');
        });

        // L'en-tête de la saisie redit la feuille : le tableau fait quinze lignes sur un
        // cahier, et on ne doit pas remonter pour savoir ce qu'on remplit. Le plan, lui,
        // est juste au-dessus — la saisie se pose dans son bloc.
        const titre = racine.querySelector('[data-feuille-titre]');
        const bouton = racine.querySelector('[data-feuille-plan-bouton]');
        if (titre) titre.textContent = ligne.dataset.feuille || '';
        if (bouton) bouton.dataset.repere = ligne.dataset.repere || '';

        // La feuille s'ouvre à droite, et l'écran se coupe en deux. Les listes rendent
        // alors la moitié de leur largeur — c'est le seul moment où elles la cèdent, et
        // la feuille de style la leur reprend dès qu'on referme le volet.
        const saisie = racine.querySelector('.soudures-saisie');
        if (saisie) saisie.removeAttribute('data-vide');
        const zone = racine.querySelector('.soudures-plan-de-travail');
        if (zone) zone.classList.add('est-coupe-en-deux');

        // Le plan de la feuille, dans l'en-tête du volet : à droite du tableau, celui-ci a
        // cédé ses colonnes d'identité.
        const plan = racine.querySelector('[data-feuille-plan]');
        if (plan) {
            plan.textContent = [ligne.dataset.plan, ligne.dataset.demande].filter(Boolean).join(' · ');
        }

        charger();
    }

    // Cliquer la ligne choisit la feuille : c'est le geste qu'on a dans une liste. Les
    // commandes de la ligne gardent le leur — « Plan » ouvre le dessin sans changer de
    // feuille travaillée, et on y clique pour vérifier un repère, pas pour se déplacer.
    $(document).on('click', '.soudures-feuille', function (evenement) {
        if (evenement.target.closest('button, a, input, label')) return;
        choisir(this);
    });

    /**
     * Ouvrir un plan : ses feuilles paraissent, celles des autres se rangent.
     *
     * Rien n'est demandé au serveur — toutes les sections sont rendues avec la page. Ce
     * qui coûte, c'est la feuille : sa liste de cordons est reconnue sur le PDF. On ferme
     * donc la saisie en changeant de plan, plutôt que de laisser à droite une feuille qui
     * n'appartient plus à ce qu'on regarde à gauche.
     */
    function ouvrirPlan(ligne) {
        const racine = page();
        if (!racine || !ligne) return;
        // Cliquer le plan ouvert le referme : on revient à la liste des plans, sans avoir
        // à chercher par où sortir. C'est la croix du volet, un étage plus haut.
        poserPlan(ligne.classList.contains('est-choisie') ? null : ligne.dataset.element);
        filtrer();
    }

    /**
     * Pose l'ouverture d'un plan, sans rien filtrer.
     *
     * À part, parce que la recherche s'en sert aussi : quand elle ne laisse qu'un plan,
     * elle l'ouvre — et elle ne peut pas se rappeler elle-même pour autant.
     */
    function poserPlan(element) {
        const racine = page();
        if (!racine) return;

        racine.querySelectorAll('.soudures-plan-ligne').forEach(autre => {
            const ouvert = element !== null && autre.dataset.element === element;
            autre.classList.toggle('est-choisie', ouvert);
            if (ouvert) autre.setAttribute('aria-current', 'true');
            else autre.removeAttribute('aria-current');
            // Le bouton dit dans quel état on est : il n'y a pas d'autre marque à lire.
            const bouton = autre.querySelector('.soudures-ouvrir-plan-feuilles');
            if (bouton) {
                bouton.textContent = ouvert ? 'Fermer' : 'Ouvrir';
                bouton.setAttribute('aria-expanded', String(ouvert));
            }
        });
        racine.querySelectorAll('.soudures-feuilles-section').forEach(section => {
            section.hidden = element === null || section.dataset.element !== element;
        });

        fermerSaisie();
    }

    /** Refermer la feuille travaillée : le volet se range, la ligne n'est plus choisie. */
    function fermerSaisie() {
        const racine = page();
        if (!racine) return;
        const saisie = racine.querySelector('.soudures-saisie');
        if (saisie) saisie.setAttribute('data-vide', '');
        const zone = racine.querySelector('.soudures-plan-de-travail');
        if (zone) zone.classList.remove('est-coupe-en-deux');
        racine.querySelectorAll('.soudures-feuille.est-choisie').forEach(ligne => {
            ligne.classList.remove('est-choisie');
            ligne.removeAttribute('aria-current');
        });
    }

    // Cliquer la ligne d'un plan l'ouvre, comme son bouton : c'est le geste qu'on a dans
    // une liste.
    $(document).on('click', '.soudures-plan-ligne', function () {
        ouvrirPlan(this);
    });

    // Le filtrage : tout est déjà rendu, on se contente de cacher ce qui ne correspond
    // pas. Un cahier porte quinze feuilles et on en a souvent trois : quarante lignes se
    // parcourent mal à l'œil, et un rechargement serveur à chaque frappe coûterait plus
    // qu'il ne rapporte. C'est la mécanique de l'écran « Soudures » du chef.
    function filtrer() {
        const racine = page();
        if (!racine) return;
        const texte = (racine.querySelector('#soudures-recherche')?.value || '').trim().toLowerCase();
        const etats = [...racine.querySelectorAll('.soudures-filtres input[name="etat[]"]:checked')]
            .map(choix => choix.value);
        const cherche = texte !== '' || etats.length > 0;

        // Les feuilles retenues, comptées par plan : c'est ce compte qui décide ensuite
        // quels plans restent au tableau du dessus.
        const retenues = new Map();
        racine.querySelectorAll('.soudures-feuille').forEach(ligne => {
            const garde = (!texte || (ligne.dataset.cherche || '').includes(texte))
                && (!etats.length || etats.includes(ligne.dataset.etat));
            ligne.hidden = !garde;
            if (garde) retenues.set(ligne.dataset.element, (retenues.get(ligne.dataset.element) || 0) + 1);
        });

        // La recherche porte sur toutes les feuilles, pas seulement celles du plan ouvert :
        // un soudeur connaît son numéro de ligne, pas le plan auquel il appartient. Les
        // plans sans feuille retenue se rangent donc.
        let seul = null;
        let plans = 0;
        racine.querySelectorAll('.soudures-plan-ligne').forEach(ligne => {
            const garde = !cherche || (retenues.get(ligne.dataset.element) || 0) > 0;
            ligne.hidden = !garde;
            if (garde) { plans++; seul = ligne; }
        });

        const aucunPlan = racine.querySelector('#soudures-aucun-plan');
        if (aucunPlan) aucunPlan.hidden = plans > 0;

        // Un seul plan retenu : il s'ouvre. Ce n'est pas deviner à la place de quelqu'un,
        // c'est répondre à ce qu'il vient de taper.
        if (cherche && plans === 1 && !seul.classList.contains('est-choisie')) {
            poserPlan(seul.dataset.element);
        }

        racine.querySelectorAll('.soudures-feuilles-section').forEach(section => {
            // Un filtre peut tout cacher dans ce plan : un mot le dit plutôt que de
            // laisser un tableau sans lignes sous ses en-têtes.
            const aucune = section.querySelector('[data-aucune]');
            if (aucune) aucune.hidden = (retenues.get(section.dataset.element) || 0) > 0;
        });

        // Ce que la recherche a trouvé, et de quoi tout défaire. Muets tant qu'on n'a rien
        // demandé : un compte qui ne bouge jamais n'apprend rien.
        const total = racine.querySelectorAll('.soudures-feuille').length;
        const trouvees = [...retenues.values()].reduce((somme, n) => somme + n, 0);
        const resultat = racine.querySelector('.soudures-resultat');
        if (resultat) {
            resultat.hidden = !cherche;
            resultat.textContent = trouvees === 0
                ? 'Aucune feuille ne correspond'
                : trouvees + (trouvees > 1 ? ' feuilles' : ' feuille') + ' sur ' + total
                    + (plans > 1 ? ' · ' + plans + ' plans' : '');
        }
        const effacer = racine.querySelector('.filtres-tout-effacer');
        if (effacer) effacer.hidden = !cherche;
    }

    // La barre de filtres de la maison prévient son formulaire quand on coche : c'est à
    // l'écran de savoir ce qu'il en fait. Ici, rien ne part au serveur — tout est déjà là.
    $(document).on('input', '#soudures-recherche', filtrer);
    $(document).on('change', '.soudures-filtres', filtrer);
    $(document).on('submit', '.soudures-filtres', event => event.preventDefault());

    // Fermer la feuille : le volet se range et la ligne n'est plus choisie. Sans ce
    // geste, le seul moyen de quitter une feuille est d'en ouvrir une autre.
    $(document).on('click', '[data-fermer-saisie]', fermerSaisie);

    $(document).on('click', '.soudures-renseigner', function () {
        choisir(this.closest('.soudures-feuille'));
        // Le volet est à droite quand l'écran est large, dessous quand il ne l'est pas :
        // on ne se déplace que dans le second cas, et seulement s'il tombe hors de vue.
        const saisie = document.querySelector('.soudures-saisie');
        if (saisie && saisie.getBoundingClientRect().top > window.innerHeight - 120) {
            saisie.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    // « Plan » et « Ouvrir la feuille » sont servis par plan-viewer.js : c'est lui qui sait
    // aller à la bonne page d'un cahier, et dupliquer ce savoir ici le ferait diverger.

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

    // Rien ne se lit à l'arrivée : aucune feuille n'est choisie, et la première lecture
    // d'une page coûte quelques secondes — les dépenser pour une feuille que personne n'a
    // demandée, c'est faire attendre devant un tableau qu'on n'allait pas remplir.
})();
