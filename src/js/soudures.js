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

    /**
     * Ce que le tableau porte à cet instant, en une chaîne.
     *
     * Sert à savoir s'il y a quelque chose à enregistrer : une feuille entièrement
     * soudée et déjà envoyée n'a plus rien à dire, et le bouton n'a plus lieu d'être
     * cliquable. On compare ce qui est à l'écran à ce que le serveur a renvoyé — pas une
     * liste de lignes « touchées », qui tiendrait une seconde vérité à jour.
     */
    function empreinte(corps) {
        return [...corps.querySelectorAll('.soudures-ligne')].map(li => {
            const date = li.querySelector('.soudures-date');
            const type = li.querySelector('.soudures-type');
            return li.dataset.numero + '' + (date ? date.value : '') + '' + (type ? type.value : '');
        }).join('');
    }

    /**
     * Le bouton d'enregistrement ne s'allume que s'il y a quelque chose à écrire.
     *
     * Une feuille complète et enregistrée laissait un bouton actif qui répondait « Rien
     * à enregistrer » : il faut cliquer pour apprendre qu'il n'y avait rien à faire. Le
     * bouton le dit maintenant sans qu'on le touche, et se rallume dès qu'on corrige une
     * ligne.
     */
    function majEnregistrer(racine) {
        const corps = racine.querySelector('#soudures-corps');
        const form = racine.querySelector('#soudures-form');
        if (!corps || !form) return;
        const bouton = form.querySelector('button[type="submit"]');
        // Rangé sur une feuille close : il n'y a plus de sort à lui donner.
        if (!bouton || bouton.hidden) return;
        const change = empreinte(corps) !== (form.dataset.empreinte || '');
        bouton.disabled = !change;
        bouton.title = change ? '' : 'Rien de nouveau à enregistrer sur cette feuille.';
    }

    function poser(corps, lignes) {
        const modele = document.getElementById('soudures-modele');
        if (!modele) return;
        corps.replaceChildren();
        lignes.forEach(ligne => {
            const li = modele.content.firstElementChild.cloneNode(true);
            li.dataset.numero = ligne.numero;
            li.classList.toggle('est-faite', Boolean(ligne.remplie));
            // Renseignée mais pas encore validée par le chef : elle ne s'écrit pas sur le
            // plan tant qu'il ne l'a pas tranchée, et la ligne le dit.
            li.classList.toggle('est-en-attente', Boolean(ligne.remplie) && !ligne.validee);
            li.classList.toggle('est-verrouillee', Boolean(ligne.verrouillee));
            li.classList.toggle('est-hors-tableau', !ligne.imprimee);

            li.querySelector('.soudures-numero').textContent = ligne.numero;
            if (!ligne.imprimee) {
                li.querySelector('.soudures-numero').title =
                    'Ce repère n’a pas de ligne imprimée sur la feuille : il a été ajouté.';
            }
            li.querySelector('.soudures-soudeur').textContent = ligne.soudeur || '';

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

        // Ce que le serveur vient de dire fait foi : c'est à partir de là qu'on mesure
        // ce qui a changé.
        const form = corps.closest('form');
        if (form) form.dataset.empreinte = empreinte(corps);
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

        // La barre suit le compte. Elle était dessinée au chargement et jamais reprise :
        // « 4 / 4 — Soudée » gardait sa barre aux trois quarts. Une feuille qui vient
        // d'apprendre son total n'en avait pas encore : elle la reçoit.
        if (compte && typeof feuille.part === 'number' && feuille.imprimees) {
            let barre = ligne.querySelector('.soudures-barre');
            if (!barre) {
                barre = document.createElement('span');
                barre.className = 'soudures-barre';
                barre.setAttribute('aria-hidden', 'true');
                barre.appendChild(document.createElement('span'));
                compte.after(barre);
            }
            barre.firstElementChild.style.setProperty('--part', feuille.part + '%');
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
                ? faites + ' / ' + total + ' cordons'
                : (reponse.message || 'aucune ligne reconnue sur cette feuille');
        }
        majTableau(racine, reponse);

        // Ce qui attend le chef, dit une fois pour toute la feuille. Deux choses peuvent
        // attendre, et pas toujours ensemble : des cordons renseignés qui ne s'écrivent
        // pas encore sur le plan, et la déclaration partie au chef. On peut avoir la
        // seconde sans les premiers — une validation d'hier couvre déjà ces lignes.
        const attente = racine.querySelector('[data-feuille-attente]');
        if (attente) {
            const combien = reponse.attente ? reponse.attente.cordons : 0;
            const depuis = reponse.attente ? reponse.attente.depuis : '';
            attente.hidden = !combien && !depuis;
            if (combien) {
                // Court : la phrase tient sur une ligne de la pastille, à côté du point.
                attente.textContent = (combien > 1
                    ? combien + ' cordons à valider par le chef'
                    : '1 cordon à valider par le chef')
                    + (depuis ? ' · déclarés le ' + depuis : '');
            } else if (depuis) {
                attente.textContent = 'Déclaration chez le chef depuis le ' + depuis;
            }
        }

        // Une feuille entièrement soudée dont le chef a tout validé est close : ce qu'elle
        // porte est signé et le plan l'écrit. Il n'y a plus rien à y enregistrer — le
        // bouton s'en va, et les cases avec lui. Tant qu'un cordon attend, en revanche,
        // elle reste ouverte : le chef peut refuser, et il faut pouvoir reprendre.
        const close = total > 0 && faites >= total
            && !(reponse.attente && reponse.attente.cordons);

        const form = racine.querySelector('#soudures-form');
        if (form) {
            form.classList.toggle('est-close', close);
            const envoyer = form.querySelector('button[type="submit"]');
            if (envoyer) envoyer.hidden = close;
            if (close) {
                corps.querySelectorAll('.soudures-date, .soudures-type')
                    .forEach(champ => { champ.disabled = true; });
            }
        }

        majEnregistrer(racine);
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
        const volet = racine.querySelector('#soudures-volet');
        // La même feuille, volet ouvert : rien à relire. Volet refermé — par Échap ou un
        // clic à côté —, on le rouvre.
        if (ligne.classList.contains('est-choisie') && volet && !volet.hidden) return;

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

        // La feuille s'ouvre dans le volet flottant, à droite, par-dessus la liste : un seul
        // volet à la fois, comme sur les autres écrans.
        document.querySelectorAll('.prefa-detail-row:not([hidden])').forEach(autre => {
            if (autre !== volet) autre.hidden = true;
        });
        if (volet) {
            volet.hidden = false;
            volet.scrollTop = 0;
        }

        // Le plan de la feuille, dans l'en-tête du volet : à droite du tableau, celui-ci a
        // cédé ses colonnes d'identité.
        const plan = racine.querySelector('[data-feuille-plan]');
        if (plan) {
            // Le numéro de la demande seul : la référence complète y ajoutait l'affaire, et la
            // ligne passait sur deux.
            plan.textContent = [ligne.dataset.plan, ligne.dataset.reference || ligne.dataset.demande].filter(Boolean).join(' · ');
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

    /** Refermer la feuille travaillée : le volet se range, la ligne n'est plus choisie. */
    function fermerSaisie() {
        const racine = page();
        if (!racine) return;
        const volet = racine.querySelector('#soudures-volet');
        if (volet) volet.hidden = true;
        racine.querySelectorAll('.soudures-feuille.est-choisie').forEach(ligne => {
            ligne.classList.remove('est-choisie');
            ligne.removeAttribute('aria-current');
        });
    }


    // ===== Choisir un plan =====
    //
    // Une liste à recherche plutôt que des onglets : un soudeur peut avoir des centaines de
    // plans. Choisir recharge l'écran sur ce plan — seules ses feuilles sont lues — et le
    // serveur s'en souvient pour les rechargements suivants.
    //
    // Au-delà de cent lignes trouvées, la liste n'en montre que cent et invite à préciser :
    // en dessiner dix mille à chaque frappe ne servirait personne.
    const PLANS_MONTRES = 100;
    const sansAccents = texte => texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    function choixPlan() {
        const racine = page();
        const zone = racine?.querySelector('[data-choix-plan]');
        if (!zone) return null;
        return {
            zone,
            bouton: zone.querySelector('.soudures-plan-declencheur'),
            menu: zone.querySelector('.soudures-plan-menu'),
            recherche: zone.querySelector('.soudures-plan-recherche input'),
            options: [...zone.querySelectorAll('.soudures-plan-option')],
            mot: zone.querySelector('.soudures-plan-mot'),
        };
    }

    function filtrerPlans() {
        const c = choixPlan();
        if (!c) return;
        const cherche = sansAccents(c.recherche.value.trim());
        let trouves = 0;
        c.options.forEach(option => {
            const garde = !cherche || sansAccents(option.dataset.cherche || '').includes(cherche);
            if (garde) trouves++;
            option.hidden = !garde || trouves > PLANS_MONTRES;
        });
        c.mot.hidden = trouves > 0 && trouves <= PLANS_MONTRES;
        c.mot.textContent = trouves === 0
            ? 'Aucun plan ne correspond.'
            : PLANS_MONTRES + ' plans montrés sur ' + trouves + ' : précisez la recherche.';
    }

    function ouvrirChoixPlan() {
        const c = choixPlan();
        if (!c) return;
        c.menu.hidden = false;
        c.bouton.setAttribute('aria-expanded', 'true');
        c.recherche.value = '';
        filtrerPlans();
        c.recherche.focus();
        c.zone.querySelector('.soudures-plan-option[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
        document.addEventListener('pointerdown', surClicHorsChoix, true);
    }

    function fermerChoixPlan(rendreLeFocus) {
        const c = choixPlan();
        if (!c || c.menu.hidden) return;
        c.menu.hidden = true;
        c.bouton.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', surClicHorsChoix, true);
        if (rendreLeFocus) c.bouton.focus();
    }

    function surClicHorsChoix(evenement) {
        const c = choixPlan();
        if (c && !c.zone.contains(evenement.target)) fermerChoixPlan(false);
    }

    function choisirPlan(option) {
        if (!option) return;
        if (option.getAttribute('aria-selected') === 'true') { fermerChoixPlan(true); return; }
        const c = choixPlan();
        c.mot.hidden = false;
        c.mot.textContent = 'Ouverture du plan…';
        $('#content').load('pages/soudures.php?plan=' + encodeURIComponent(option.dataset.element), function (_, statut) {
            if (statut === 'error') $(this).text('Impossible d’ouvrir ce plan. Actualisez la page.');
        });
    }

    $(document).on('click', '.soudures-plan-declencheur', function () {
        const c = choixPlan();
        if (c && !c.menu.hidden) fermerChoixPlan(true);
        else ouvrirChoixPlan();
    });
    $(document).on('input', '.soudures-plan-recherche input', filtrerPlans);
    $(document).on('click', '.soudures-plan-option', function () { choisirPlan(this); });

    // Le clavier : la recherche garde le focus, les flèches promènent la surbrillance,
    // Entrée ouvre le plan surligné — ou le premier trouvé —, Échap referme.
    $(document).on('keydown', '.soudures-plan-recherche input, .soudures-plan-option', function (evenement) {
        const c = choixPlan();
        if (!c) return;
        const visibles = c.options.filter(option => !option.hidden);
        const actif = c.zone.querySelector('.soudures-plan-option.est-active');
        if (evenement.key === 'Escape') {
            evenement.preventDefault();
            evenement.stopPropagation();
            fermerChoixPlan(true);
        } else if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
            evenement.preventDefault();
            const rang = visibles.indexOf(actif);
            const pas = evenement.key === 'ArrowDown' ? 1 : -1;
            const suivant = visibles[rang === -1 ? (pas > 0 ? 0 : visibles.length - 1) : (rang + pas + visibles.length) % visibles.length];
            actif?.classList.remove('est-active');
            suivant?.classList.add('est-active');
            suivant?.scrollIntoView({ block: 'nearest' });
        } else if (evenement.key === 'Enter') {
            evenement.preventDefault();
            choisirPlan(actif && !actif.hidden ? actif : visibles[0]);
        }
    });
    $(document).on('input', '.soudures-plan-recherche input', function () {
        choixPlan()?.zone.querySelector('.soudures-plan-option.est-active')?.classList.remove('est-active');
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

        // La recherche vit dans la barre d'outils du plan ouvert : elle ne porte que sur
        // ses feuilles. Filtrer aussi les autres plans rangerait la section qui la
        // contient, et elle disparaîtrait en même temps que ce qu'elle filtre.
        const section = racine.querySelector('.soudures-feuilles-section:not([hidden])');
        if (!section) return;

        let trouvees = 0;
        let total = 0;
        section.querySelectorAll('.soudures-feuille').forEach(ligne => {
            total++;
            const garde = (!texte || (ligne.dataset.cherche || '').includes(texte))
                && (!etats.length || etats.includes(ligne.dataset.etat));
            ligne.hidden = !garde;
            if (garde) trouvees++;
        });

        // Un filtre peut tout cacher : un mot le dit plutôt que de laisser un tableau sans
        // lignes sous ses en-têtes.
        const aucune = section.querySelector('[data-aucune]');
        if (aucune) aucune.hidden = trouvees > 0;

        // Ce que la recherche a trouvé, et de quoi tout défaire. Muets tant qu'on n'a rien
        // demandé : un compte qui ne bouge jamais n'apprend rien.
        const resultat = racine.querySelector('.soudures-resultat');
        if (resultat) {
            resultat.hidden = !cherche;
            resultat.textContent = trouvees === 0
                ? 'Aucune feuille ne correspond'
                : trouvees + (trouvees > 1 ? ' feuilles' : ' feuille') + ' sur ' + total;
        }
        const effacer = racine.querySelector('.filtres-tout-effacer');
        if (effacer) effacer.hidden = !cherche;
    }

    // La barre de filtres de la maison prévient son formulaire quand on coche : c'est à
    // l'écran de savoir ce qu'il en fait. Ici, rien ne part au serveur — tout est déjà là.
    // Corriger une ligne rallume le bouton ; revenir à ce qui était enregistré l'éteint.
    $(document).on('input change', '#soudures-corps .soudures-date, #soudures-corps .soudures-type', function () {
        const racine = page();
        if (racine) majEnregistrer(racine);
    });

    $(document).on('input', '#soudures-recherche', filtrer);
    $(document).on('change', '.soudures-filtres', filtrer);
    $(document).on('submit', '.soudures-filtres', event => event.preventDefault());

    // Fermer la feuille : le volet se range et la ligne n'est plus choisie. Sans ce
    // geste, le seul moyen de quitter une feuille est d'en ouvrir une autre.
    $(document).on('click', '[data-fermer-saisie]', fermerSaisie);

    $(document).on('click', '.soudures-renseigner', function () {
        choisir(this.closest('.soudures-feuille'));
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
            // Les boutons reviennent tous, puis celui d'enregistrement retrouve son sort :
            // la feuille qu'on vient d'envoyer n'a plus rien à dire.
            majEnregistrer(racine);
        }
    });

    // ===== Signaler un manque =====
    //
    // La fenêtre de la maison, ouverte depuis l'en-tête ou depuis la feuille travaillée. Le
    // plan proposé est celui qu'on regarde ; les articles suggérés sont ceux du bon de
    // commande de sa demande — on peut toujours en écrire un autre.
    const fenetreManque = () => document.getElementById('soudures-manque');

    function articlesDuPlan(fenetre, plan) {
        let articles = {};
        try { articles = JSON.parse(fenetre.dataset.articles || '{}'); } catch { articles = {}; }
        return articles[plan] || [];
    }

    /** Pose une valeur dans une liste filtrable de la fenêtre, texte affiché compris. */
    function choisirDans(fenetre, prefixe, valeur) {
        const filtrable = fenetre.querySelector('[data-liste-filtrable="' + prefixe + '"]');
        if (!filtrable) return;
        const option = [...filtrable.querySelectorAll('[role="option"]')].find(o => o.dataset.value === String(valeur));
        filtrable.querySelector('.liste-filtrable-champ').value = option && valeur !== '' ? option.textContent.trim() : '';
        filtrable.querySelector('input[type="hidden"]').value = option ? valeur : '';
        filtrable.querySelectorAll('[role="option"]').forEach(o => {
            o.setAttribute('aria-selected', o === option ? 'true' : 'false');
        });
    }

    /**
     * La liste des articles suit le plan : ce sont ceux du bon de commande de sa demande.
     * Sans bon lu, il n'y a rien à proposer — la liste s'efface et le champ libre la
     * remplace, plutôt qu'une liste qui n'offrirait que « Autre article… ».
     */
    function poserArticles(fenetre) {
        const plan = fenetre.querySelector('[name="element"]').value;
        const articles = articlesDuPlan(fenetre, plan);
        const filtrable = fenetre.querySelector('[data-liste-filtrable="manque-article"]');
        const liste = filtrable.querySelector('.liste-filtrable-options');
        const autre = liste.querySelector('.liste-filtrable-autre');
        liste.querySelectorAll('[role="option"]:not(.liste-filtrable-autre)').forEach(o => o.remove());
        articles.forEach((article, rang) => {
            const option = document.createElement('div');
            option.id = 'manque-article-option-' + rang;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.dataset.value = article.designation;
            option.dataset.unite = article.unite || '';
            option.textContent = article.designation;
            liste.insertBefore(option, autre);
        });

        const libre = fenetre.querySelector('#manque-article-autre-field');
        const precision = fenetre.querySelector('#manque-article-autre');
        choisirDans(fenetre, 'manque-article', '');
        precision.value = '';
        const sansListe = articles.length === 0;
        filtrable.hidden = sansListe;
        libre.hidden = !sansListe;
    }

    $(document).on('click', '[data-signaler-manque]', function () {
        const fenetre = fenetreManque();
        if (!fenetre) return;
        const form = fenetre.querySelector('form');
        form.reset();
        // Le plan de la feuille ouverte s'il y en a une, sinon celui du sélecteur.
        const racine = page();
        const feuille = racine && ligneChoisie(racine);
        const section = racine && racine.querySelector('.soudures-feuilles-section');
        const plan = (feuille && feuille.dataset.element) || (section && section.dataset.element) || '';
        if (plan) {
            const champ = form.querySelector('[name="element"]');
            if (fenetre.querySelector('[data-liste-filtrable="manque-plan"]')) choisirDans(fenetre, 'manque-plan', plan);
            else champ.value = plan;
        }
        choisirDans(fenetre, 'manque-motif', '');
        poserArticles(fenetre);
        const message = form.querySelector('.fenetre-message');
        message.textContent = '';
        message.classList.remove('is-error');
        fenetre.showModal();
        const premier = fenetre.querySelector('[data-liste-filtrable="manque-article"]:not([hidden]) .liste-filtrable-champ')
            || fenetre.querySelector('#manque-article-autre');
        premier?.focus();
    });

    // Le composant prévient par un « change » sur son champ caché.
    $(document).on('change', '#soudures-manque input[name="element"]', function () {
        poserArticles(fenetreManque());
    });

    // Un article du bon apporte son unité : « m » pour un tube, « u » pour une bride. On ne
    // l'écrase pas si la personne en a déjà tapé une.
    $(document).on('change', '#soudures-manque input[name="designation"]', function () {
        const fenetre = fenetreManque();
        const unite = fenetre.querySelector('[name="unite"]');
        const option = [...fenetre.querySelectorAll('[data-liste-filtrable="manque-article"] [role="option"]')]
            .find(o => o.dataset.value === this.value);
        if (option && option.dataset.unite && !unite.value) unite.value = option.dataset.unite;
    });

    $(document).on('click', '[data-fermer-manque]', function () {
        fenetreManque()?.close();
    });

    $(document).on('submit', '#soudures-manque-form', async function (event) {
        event.preventDefault();
        const form = this;
        const message = form.querySelector('.fenetre-message');
        const bouton = form.querySelector('.fenetre-valider');
        // Les listes de la maison écrivent dans des champs cachés, qu'aucun « required »
        // ne couvre : on dit ce qui manque avant d'envoyer.
        const article = form.querySelector('[name="designation"]').value;
        const libre = form.querySelector('#manque-article-autre').value.trim();
        const manque = (!article || article === 'autre') && !libre ? 'Indiquez l’article qui manque.'
            : !form.querySelector('[name="quantite"]').value.trim() ? 'Indiquez la quantité.'
            : !form.querySelector('[name="motif"]').value ? 'Choisissez le motif du manque.' : '';
        if (manque) {
            message.classList.add('is-error');
            message.textContent = manque;
            return;
        }

        const donnees = new URLSearchParams(new FormData(form));
        donnees.set('csrf', form.dataset.csrf);

        bouton.disabled = true;
        message.classList.remove('is-error');
        message.textContent = 'Envoi…';
        try {
            const reponse = await fetch('actions/signaler_manque.php', {
                method: 'POST', body: donnees,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const corps = await reponse.json();
            if (!reponse.ok || !corps.success) {
                message.classList.add('is-error');
                message.textContent = corps.message || 'Signalement impossible.';
                return;
            }
            fenetreManque().close();
            // Le tableau « Matériel signalé » se met à jour sur place, et son compte avec.
            const racine = page();
            const zone = racine && racine.querySelector('[data-manques]');
            if (zone && corps.html) zone.innerHTML = corps.html;
            const compte = racine && racine.querySelector('#soudures-materiel-titre .prefa-onglet-compte');
            if (compte && zone) compte.textContent = zone.querySelectorAll('tbody tr').length;
            // Ouvert depuis une feuille : le pied du volet le dit. Sinon, le tableau suffit.
            const retour = racine && racine.querySelector('#soudures-volet:not([hidden]) .atelier-message');
            if (retour) retour.textContent = corps.message;
            racine?.querySelector('.soudures-materiel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch {
            message.classList.add('is-error');
            message.textContent = 'Le serveur ne répond pas.';
        } finally {
            bouton.disabled = false;
        }
    });

    // Rien ne se lit à l'arrivée : aucune feuille n'est choisie, et la première lecture
    // d'une page coûte quelques secondes — les dépenser pour une feuille que personne n'a
    // demandée, c'est faire attendre devant un tableau qu'on n'allait pas remplir.
})();
