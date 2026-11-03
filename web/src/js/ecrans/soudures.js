(() => {
    // « Mes soudures » : on choisit sa feuille dans le tableau, son dessin s'ouvre, et on
    // clique une ligne de sa liste soudure pour la renseigner — la date et le procédé, dans
    // la fenêtre d'un cordon. La feuille se remplissait dans une liste à côté du dessin : on
    // allait et venait entre le tirage, le dessin et le tableau.
    //
    // Les numéros ne sont pas rendus avec la page. Ils sont reconnus sur le PDF du plan
    // (plan_reperes.php), et la première lecture d'une page coûte une ou deux secondes : on
    // ne lit que la page regardée, au moment où la fenêtre du plan l'affiche — elle le dit
    // par l'événement « plan:page ».
    const page = () => document.getElementById('soudures-page');

    // Le cordon dont la fenêtre est ouverte — { viewer, numero } —, ou null.
    let fiche = null;
    // Le procédé du dernier cordon enregistré : un soudeur en change rarement d'une soudure
    // à l'autre, il n'a pas à le retaper.
    let dernierProcede = '';

    // ===== Le dessin d'une feuille =====

    /** La page qu'une fenêtre du plan affiche. */
    const pageVue = viewer => Math.max(1, Number(viewer.dataset.page) || 1);

    /** « élément:feuille:page » : la ligne du tableau des feuilles qui montre cette page. */
    const repereDe = (viewer, numeroPage) => viewer.dataset.plan + ':' + viewer.dataset.document + ':' + numeroPage;

    /** Une fenêtre du plan de cet écran — et non celle d'un autre écran resté dans la page. */
    const duSoudeur = viewer => Boolean(viewer && viewer.closest && viewer.closest('.soudures-lecteur'));

    /** Ce que la fenêtre du plan dit dans sa barre : la lecture, un enregistrement, un refus. */
    function direDansLeDessin(viewer, texte, erreur) {
        const zone = viewer.querySelector('.plan-viewer-message');
        if (!zone) return;
        zone.textContent = texte;
        zone.classList.toggle('is-error', Boolean(erreur));
    }

    /**
     * Où en est un cordon : la classe de son numéro sur le dessin, et le mot qui le dit — au
     * féminin, l'écran parle de « soudures ». Le serveur a tranché — renseigné, validé, à
     * quelqu'un d'autre — ; le script le redit.
     */
    function etatCordon(ligne) {
        if (!ligne.remplie) return { cle: 'a-faire', court: 'à faire' };
        if (ligne.verrouillee) return { cle: 'verrouillee', court: 'soudée par ' + (ligne.soudeur || 'un autre soudeur') };
        if (!ligne.validee) return { cle: 'en-attente', court: 'en attente du chef' };
        return { cle: 'validee', court: 'validée' };
    }

    /**
     * Une feuille entièrement soudée dont le chef a tout validé est close : ce qu'elle porte
     * est signé et le plan l'écrit. Tant qu'un cordon attend, elle reste ouverte — le chef
     * peut refuser, et il faut pouvoir reprendre.
     */
    function estClose(donnees) {
        const lignes = [...donnees.lignes.values()];
        const total = donnees.reponse.imprimees || lignes.length;
        const faites = lignes.filter(ligne => ligne.remplie).length;
        return total > 0 && faites >= total && !(donnees.reponse.attente && donnees.reponse.attente.cordons);
    }

    /** Les lignes d'une réponse, rangées par numéro. */
    const parNumero = lignes => new Map((lignes || []).map(ligne => [String(ligne.numero), ligne]));

    /** Un bouton posé sur le dessin, en fractions de l'image comme tout ce qui s'y pose. */
    function boutonSurLeDessin(scene, classe, numero, x, y, l, h) {
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = classe;
        bouton.dataset.numero = numero;
        bouton.style.left = (x * 100).toFixed(4) + '%';
        bouton.style.top = (y * 100).toFixed(4) + '%';
        bouton.style.width = (l * 100).toFixed(4) + '%';
        bouton.style.height = (h * 100).toFixed(4) + '%';
        scene.append(bouton);
        return bouton;
    }

    /**
     * Pose ce qu'on clique sur le dessin de la page : chaque ligne de la liste soudure
     * imprimée — c'est là qu'on écrit, comme sur le tirage —, et chaque numéro reconnu le
     * long du tube, exactement sur son rectangle. Ceux de la page d'avant s'en vont : ils
     * n'ont de place que sur leur dessin.
     */
    function poserDessin(viewer) {
        const scene = viewer.querySelector('.plan-viewer-scene');
        if (!scene) return;
        scene.querySelectorAll('.plan-cordon, .plan-ligne-soudure, .plan-ligne-materiel').forEach(bouton => bouton.remove());
        const donnees = viewer.cordons;
        ecrireEnAttente(viewer);
        if (!donnees) {
            dessinerEtat(viewer);
            return;
        }

        donnees.cliquables = new Set();
        const tableau = donnees.tableau;
        if (tableau && tableau.lignes && tableau.bords && tableau.bords.length >= 2) {
            const gauche = tableau.bords[0];
            const largeur = tableau.bords[tableau.bords.length - 1] - gauche;
            tableau.lignes.forEach((ligne, rang) => {
                const numero = String(rang + 1);
                if (!donnees.lignes.has(numero)) return;
                // La ligne entière, d'un trait à l'autre : la géométrie relevée est celle de
                // son texte, en retrait des traits, et le pas rend la bande complète.
                const retrait = (tableau.pas - ligne.h) / 2;
                boutonSurLeDessin(scene, 'plan-ligne-soudure', numero, gauche, ligne.y - retrait, largeur, tableau.pas);
                donnees.cliquables.add(numero);
            });
        }
        // Un numéro dessiné que la liste ne porte pas n'a rien à renseigner ; un numéro
        // trouvé deux fois sur le tube ne se clique qu'à un endroit.
        const dessines = new Set();
        donnees.reperes.forEach(repere => {
            if (!donnees.lignes.has(repere.numero) || dessines.has(repere.numero)) return;
            dessines.add(repere.numero);
            boutonSurLeDessin(scene, 'plan-cordon', repere.numero, repere.x, repere.y, repere.w, repere.h);
            donnees.cliquables.add(repere.numero);
        });

        // Les lignes de la nomenclature, d'un bord du tableau à l'autre : on clique celle du
        // matériel qui manque, comme on la montrerait du doigt sur le tirage.
        const nomenclature = donnees.nomenclature;
        if (nomenclature && nomenclature.lignes) {
            nomenclature.lignes.forEach((ligne, rang) => {
                const bouton = boutonSurLeDessin(scene, 'plan-ligne-materiel', ligne.repere, nomenclature.gauche, ligne.y,
                    nomenclature.droite - nomenclature.gauche, ligne.h);
                bouton.dataset.rang = rang;
                marquerMateriel(bouton, ligne);
            });
        }
        majNumeros(viewer);
    }

    /** Ce que dit une ligne de nomenclature au survol, et sa couleur quand son manque est signalé. */
    function marquerMateriel(bouton, ligne) {
        bouton.classList.toggle('est-signale', Boolean(ligne.signale));
        const article = 'Repère ' + ligne.repere + (ligne.designation ? ' · ' + ligne.designation : '');
        const libelle = article + (ligne.signale ? ' — manque signalé le ' + ligne.signale : ' — signaler un manque');
        bouton.setAttribute('aria-label', libelle);
        bouton.title = libelle;
    }

    /**
     * L'état de chaque ligne et de chaque numéro, et la ligne sous le titre : redits après
     * chaque enregistrement. La ligne et le numéro d'un même cordon s'allument ensemble
     * quand sa fiche est ouverte — on voit où il est sur le tube.
     */
    function majNumeros(viewer) {
        const donnees = viewer.cordons;
        if (!donnees) return;
        viewer.querySelectorAll('.plan-cordon, .plan-ligne-soudure').forEach(bouton => {
            const ligne = donnees.lignes.get(bouton.dataset.numero);
            if (!ligne) return;
            const etat = etatCordon(ligne);
            const sorte = bouton.classList.contains('plan-ligne-soudure') ? 'plan-ligne-soudure' : 'plan-cordon';
            const ouvert = fiche && fiche.viewer === viewer && fiche.numero === bouton.dataset.numero;
            bouton.className = sorte + ' est-' + etat.cle + (ouvert ? ' est-ouvert' : '');
            const libelle = (sorte === 'plan-ligne-soudure' ? 'Ligne ' : 'Soudure ') + ligne.numero + ' : ' + etat.court;
            bouton.setAttribute('aria-label', libelle);
            bouton.title = libelle;
        });
        dessinerEtat(viewer);
    }

    /** « 2026-10-30 » devient « 30/10/26 » : la date comme le plan final l'écrit. */
    const dateDuPlan = iso => /^\d{4}-\d{2}-\d{2}$/.test(iso || '')
        ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(2, 4) : '';

    /**
     * Ce qui est renseigné et attend le chef s'écrit dans sa ligne de la liste, dans le ton
     * de l'attente : on voit sa saisie là où on l'a faite. Le validé, lui, est écrit par la
     * fenêtre du plan, comme sur le plan final.
     */
    function ecrireEnAttente(viewer) {
        if (typeof window.planEcrireEnAttente !== 'function') return;
        const donnees = viewer.cordons;
        const valeurs = {};
        if (donnees && donnees.tableau) {
            donnees.lignes.forEach(ligne => {
                if (!ligne.remplie || ligne.validee || !ligne.imprimee) return;
                valeurs[Number(ligne.numero) - 1] = ['', ligne.soudeur || '', dateDuPlan(ligne.date), ligne.type || '', '', '', '', ''];
            });
        }
        window.planEcrireEnAttente(viewer, donnees ? donnees.tableau : null, valeurs);
    }

    /**
     * La légende des couleurs du dessin, un point et un mot par couleur. Les trois de la
     * liste soudure — à faire, en attente du chef, validée — dès que la page a une liste ;
     * « soudée par un autre » et « manque signalé » seulement quand la page en porte, comme
     * les onglets de l'écran qui ne paraissent que s'ils comptent quelque chose.
     */
    function legende(donnees) {
        const entrees = [];
        if (donnees.lignes.size) {
            entrees.push(['a-faire', 'À faire'], ['en-attente', 'En attente du chef'], ['validee', 'Validée']);
            if ([...donnees.lignes.values()].some(ligne => etatCordon(ligne).cle === 'verrouillee')) {
                entrees.push(['verrouillee', 'Soudée par un autre']);
            }
        }
        if ((donnees.nomenclature?.lignes || []).some(ligne => ligne.signale)) entrees.push(['manque', 'Manque signalé']);
        if (!entrees.length) return null;

        const bloc = document.createElement('span');
        bloc.className = 'soudures-legende';
        const titre = document.createElement('span');
        titre.className = 'sr-only';
        titre.textContent = 'Légende :';
        bloc.append(titre);
        entrees.forEach(([cle, libelle]) => {
            const entree = document.createElement('span');
            entree.className = 'soudures-legende-entree est-' + cle;
            entree.textContent = libelle;
            bloc.append(entree);
        });
        return bloc;
    }

    /**
     * Sous la consigne de la fenêtre : la légende des couleurs, puis, seulement quand il y en
     * a, les soudures qu'aucune ligne de la liste ne porte — ajoutées hors de la liste
     * imprimée, ou toute une page dont la liste n'a pas été reconnue. Elles se renseignent
     * d'ici.
     *
     * Le reste se lit sur le dessin : la couleur de chaque ligne dit où en est sa soudure.
     * Le compte et la déclaration chez le chef s'écrivaient aussi en haut : on le lisait deux
     * fois, et le dessin passait après.
     */
    function dessinerEtat(viewer) {
        const titre = viewer.querySelector('.plan-dialog-titre');
        if (!titre) return;
        let zone = titre.querySelector('.soudures-dessin-etat');
        if (!zone) {
            zone = document.createElement('div');
            zone.className = 'soudures-dessin-etat';
            titre.append(zone);
        }
        zone.replaceChildren();
        const donnees = viewer.cordons;
        if (!donnees) return;
        const couleurs = legende(donnees);
        if (couleurs) zone.append(couleurs);

        const hors = [...donnees.lignes.values()].filter(ligne => !donnees.cliquables.has(String(ligne.numero)));
        if (!hors.length) return;
        const groupe = document.createElement('span');
        groupe.className = 'soudures-dessin-hors';
        groupe.textContent = donnees.cliquables.size ? 'Hors de la liste :' : 'Soudures à renseigner :';
        hors.forEach(ligne => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'soudures-hors-cordon est-' + etatCordon(ligne).cle;
            bouton.dataset.numero = ligne.numero;
            bouton.textContent = ligne.numero;
            bouton.setAttribute('aria-label', 'Soudure ' + ligne.numero + ' : ' + etatCordon(ligne).court);
            groupe.append(bouton);
        });
        zone.append(groupe);
    }

    /**
     * « Signaler un manque » dans la barre de la fenêtre, avec les autres commandes : c'est
     * en soudant qu'on s'aperçoit qu'une pièce n'est pas là, et le dessin occupe l'écran.
     */
    function poserManque(viewer) {
        const outils = viewer.querySelector('.plan-dialog-outils');
        if (!outils || outils.querySelector('.soudures-dessin-manque')) return;
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'soudures-dessin-manque';
        bouton.dataset.signalerManque = '';
        bouton.textContent = 'Signaler un manque';
        outils.insertBefore(bouton, outils.querySelector('.plan-dialog-imprimer, .plan-dialog-telecharger'));
    }

    /**
     * La barre de la fenêtre, telle que le soudeur s'en sert : « Signaler un manque », et
     * « Imprimer » à la place de « Télécharger le PDF ». Il vient chercher sa feuille sur
     * papier pour la souder, pas un fichier — et le plan final, que le lien téléchargeait,
     * refuse tant que rien n'y est validé. Posée dès que la fenêtre arrive, avant qu'elle
     * s'ouvre : le lien ne paraît jamais.
     */
    function equiperBarre(viewer) {
        poserManque(viewer);
        const lien = viewer.querySelector('.plan-dialog-telecharger');
        if (!lien) return;
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'plan-dialog-imprimer';
        bouton.title = 'Imprimer la feuille affichée';
        bouton.textContent = 'Imprimer';
        lien.replaceWith(bouton);
    }

    /** Un texte sûr dans le HTML qu'on écrit à la main — le titre de la page d'impression. */
    const echapper = texte => String(texte).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

    /**
     * « Imprimer » : la feuille affichée, ou tout le cahier — la fenêtre de choix de la maison
     * le demande quand le cahier en porte plusieurs. Une feuille seule s'imprime sans question.
     */
    async function imprimer(viewer) {
        const total = Math.max(1, Number(viewer.dataset.pages) || 1);
        const page = pageVue(viewer);
        let pages = [page];
        if (total > 1) {
            const feuille = document.querySelector('#soudures-page .soudures-feuille[data-repere="'
                + repereDe(viewer, page) + '"]')?.dataset.feuille || 'page ' + page;
            const choix = await window.choisir('Que voulez-vous imprimer ?',
                ['Cette feuille · ' + feuille, 'Tout le cahier · ' + total + ' feuilles']);
            if (choix === null) return;
            if (choix === 1) pages = Array.from({ length: total }, (_, rang) => rang + 1);
        }
        imprimerPages(viewer, pages);
    }

    /**
     * Imprime ces pages du cahier, une feuille par page de papier, à sa taille et dans son
     * sens : un cadre invisible qui ne porte que leurs images, et l'impression du navigateur.
     * La fenêtre du plan reste ouverte derrière, sur la même page.
     */
    function imprimerPages(viewer, pages) {
        const image = viewer.querySelector('.plan-viewer-scene img');
        if (!image || !image.dataset.src) return;
        const titre = (viewer.querySelector('.plan-dialog-titre strong')?.textContent || 'Plan')
            + (pages.length === 1 ? ' — page ' + pages[0] : '');
        const cadre = document.createElement('iframe');
        cadre.className = 'plan-impression';
        cadre.setAttribute('aria-hidden', 'true');
        // « 100vh », c'est la hauteur imprimable d'une page : chaque feuille y tient entière,
        // sans déborder sur la suivante.
        cadre.srcdoc = '<!doctype html><meta charset="utf-8"><title>' + echapper(titre) + '</title><style>'
            + '@page { size: ' + (image.naturalWidth >= image.naturalHeight ? 'landscape' : 'portrait') + '; margin: 8mm; }'
            + ' html, body { margin: 0; }'
            + ' img { display: block; width: 100%; height: 100vh; object-fit: contain; break-after: page; }'
            + ' img:last-child { break-after: auto; }'
            + '</style>' + pages.map(page => '<img src="'
                + echapper(new URL(image.dataset.src + '&page=' + page, location.href).href) + '" alt="">').join('');
        cadre.addEventListener('load', () => {
            const fenetre = cadre.contentWindow;
            // Toutes les feuilles arrivées, ou en échec : l'impression part une fois.
            const arrivees = [...cadre.contentDocument.images].map(feuille => feuille.complete ? null
                : new Promise(fini => {
                    feuille.addEventListener('load', fini, { once: true });
                    feuille.addEventListener('error', fini, { once: true });
                }));
            Promise.all(arrivees).then(() => {
                // Le cadre s'en va une fois l'impression faite ou abandonnée.
                fenetre.addEventListener('afterprint', () => cadre.remove(), { once: true });
                fenetre.focus();
                fenetre.print();
            });
        }, { once: true });
        document.body.append(cadre);
    }

    document.addEventListener('click', evenement => {
        const viewer = evenement.target.closest('.plan-dialog-imprimer')?.closest('.plan-viewer');
        if (duSoudeur(viewer)) imprimer(viewer);
    });

    /** Ce qu'on sait déjà d'une page de cette fenêtre — sa dernière lecture —, ou null. */
    function lectureDe(viewer, numeroPage) {
        viewer.lectures ??= {};
        return viewer.lectures[numeroPage] || null;
    }

    /**
     * Lit la page affichée : ses lignes, et où sont ses numéros. Une ouverture annonce
     * parfois deux fois la même page ; la seconde demande attend la première.
     */
    async function chargerPage(viewer, numeroPage) {
        const bloc = viewer.closest('.soudures-lecteur');
        if (!bloc || viewer.cordonsDemande === numeroPage) return;
        viewer.cordonsDemande = numeroPage;
        // Une page déjà lue se remontre telle qu'on l'a laissée, tout de suite, et se relit
        // sans bruit derrière — le chef a pu valider entre-temps. Rouvrir une feuille effaçait
        // tout ce qui était posé sur son dessin et redisait « Lecture de la feuille… », alors
        // qu'on l'avait sous les yeux une seconde plus tôt.
        const deja = lectureDe(viewer, numeroPage);
        if (viewer.cordons !== deja) {
            viewer.cordons = deja;
            poserDessin(viewer);
        }
        if (!deja) direDansLeDessin(viewer, 'Lecture de la feuille…', false);

        const corps = new URLSearchParams();
        corps.set('csrf', bloc.dataset.csrf);
        corps.set('element', viewer.dataset.plan);
        corps.set('document', viewer.dataset.document);
        corps.set('page', numeroPage);
        try {
            const reponse = await fetch('actions/soudures_feuille.php', {
                method: 'POST', body: corps,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const retour = await reponse.json();
            // On a tourné la page pendant la lecture : cette réponse décrit une autre feuille.
            if (pageVue(viewer) !== numeroPage) return;
            if (!reponse.ok || !retour.success) {
                direDansLeDessin(viewer, retour.message || 'Lecture impossible.', true);
                return;
            }
            viewer.cordons = { page: numeroPage, lignes: parNumero(retour.lignes), tableau: retour.tableau || null,
                reperes: retour.reperes || [], nomenclature: retour.nomenclature || null, reponse: retour,
                cliquables: new Set() };
            viewer.lectures[numeroPage] = viewer.cordons;
            poserDessin(viewer);
            majFeuille(repereDe(viewer, numeroPage), retour);
            direDansLeDessin(viewer, retour.message || '', Boolean(retour.message));
        } catch {
            // Relue en silence, la page montrée reste juste : seul ce qui n'a rien à montrer le dit.
            if (pageVue(viewer) === numeroPage && !deja) direDansLeDessin(viewer, 'Le serveur ne répond pas.', true);
        } finally {
            if (viewer.cordonsDemande === numeroPage) viewer.cordonsDemande = null;
        }
    }

    // La fenêtre du plan prévient quand une page est à l'écran : on y pose ses numéros. La
    // ligne de cette page se marque dans le tableau — en refermant, on sait d'où l'on vient.
    document.addEventListener('plan:page', evenement => {
        const viewer = evenement.target;
        if (!duSoudeur(viewer)) return;
        // La fenêtre d'un cordon parle d'une page : on n'en tourne pas une autre dessous.
        if (fenetreCordon()?.open) fenetreCordon().close();
        poserManque(viewer);
        marquerFeuille(repereDe(viewer, evenement.detail.page));
        chargerPage(viewer, evenement.detail.page);
    });

    /** La ligne de la feuille ouverte, marquée dans le tableau. */
    function marquerFeuille(repere) {
        document.querySelectorAll('#soudures-page .soudures-feuille').forEach(ligne => {
            const sienne = ligne.dataset.repere === repere;
            ligne.classList.toggle('est-choisie', sienne);
            if (sienne) ligne.setAttribute('aria-current', 'true');
            else ligne.removeAttribute('aria-current');
        });
    }

    /**
     * La ligne d'une feuille suit ce que le serveur vient de dire : son compte de cordons,
     * le point de son état et sa barre. Les onglets recomptent, et le résumé du plan suit.
     *
     * Ils viennent du serveur et non de ce script : une feuille jamais ouverte ignorait son
     * total et vient de l'apprendre, et le libellé de l'état est celui de l'écran du chef.
     */
    function majFeuille(repere, reponse) {
        const ligne = [...document.querySelectorAll('#soudures-page .soudures-feuille')]
            .find(candidate => candidate.dataset.repere === repere);
        const feuille = reponse.feuille;
        if (!ligne || !feuille) return;

        const compte = ligne.querySelector('[data-cordons]');
        if (compte && feuille.compte) compte.textContent = feuille.compte;
        const point = ligne.querySelector('[data-etat-point]');
        if (point && feuille.etat) {
            point.className = 'prefa-point soudures-point est-' + feuille.etat.cle;
            point.title = feuille.etat.libelle;
            point.setAttribute('aria-label', feuille.etat.libelle);
        }
        // Les onglets lisent la ligne : elle doit dire son état.
        if (feuille.etat) ligne.dataset.etat = feuille.etat.cle;

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
        majResume(repere.split(':')[0], reponse.plan);
        filtrer();
    }

    /**
     * Les comptes de soudures : celui du plan, en tête de ses feuilles — « 7 / 69 », comme une
     * feuille —, puis celui de tous les plans à droite des onglets, « 12 soudures faites sur
     * 58 » : la phrase de la page, refaite ici sur la somme des plans.
     */
    function majResume(element, plan) {
        const racine = page();
        if (!racine) return;
        const compte = racine.querySelector('.soudures-plan[data-element="' + element + '"] [data-plan-cordons]');
        if (compte && plan) {
            compte.dataset.faites = Number(plan.faites) || 0;
            compte.dataset.prevues = Number(plan.prevues) || 0;
            compte.textContent = compte.dataset.faites + ' / ' + (Number(compte.dataset.prevues) > 0 ? compte.dataset.prevues : '—');
        }
        let faites = 0;
        let prevues = 0;
        racine.querySelectorAll('[data-plan-cordons]').forEach(cellule => {
            faites += Number(cellule.dataset.faites) || 0;
            prevues += Number(cellule.dataset.prevues) || 0;
        });
        const faits = racine.querySelector('[data-cordons-faits]');
        const libelle = racine.querySelector('[data-cordons-libelle]');
        if (!faits || !libelle) return;
        faits.textContent = faites;
        libelle.textContent = (faites > 1 ? 'soudures faites' : 'soudure faite') + (prevues > 0 ? ' sur ' + prevues : '');
    }

    // ===== La fenêtre d'un cordon =====
    //
    // Cliquer une ligne de la liste soudure — ou le numéro du cordon sur le tube — ouvre la
    // fenêtre de la maison, par-dessus celle du plan : la date, le procédé, « Enregistrer ».
    // Échap ou « Annuler » la referment, et le dessin reste ouvert pour le cordon suivant.

    const fenetreCordon = () => document.getElementById('soudures-cordon');

    function aujourdhui() {
        const jour = new Date();
        const deux = nombre => String(nombre).padStart(2, '0');
        return jour.getFullYear() + '-' + deux(jour.getMonth() + 1) + '-' + deux(jour.getDate());
    }

    /** Les pastilles d'état de la maison, celles des PV et des bons de commande. */
    const PUCES = { 'en-attente': 'pv-puce-a_faire', validee: 'pv-puce-fait', verrouillee: 'pv-puce-sans_objet' };

    /** Le procédé dans sa liste : choisi s'il y est, sinon « Autre procédé… » et son texte dessous. */
    function poserProcede(fenetre, valeur) {
        const liste = fenetre.querySelector('[data-liste-filtrable="cordon-procede"]');
        const connu = valeur !== '' && valeur !== 'autre'
            && [...liste.querySelectorAll('[role="option"]')].some(option => option.dataset.value === valeur);
        choisirDans(fenetre, 'cordon-procede', connu ? valeur : (valeur ? 'autre' : ''));
        fenetre.querySelector('#cordon-procede-autre-field').hidden = connu || !valeur;
        fenetre.querySelector('#cordon-procede-autre').value = connu ? '' : valeur;
    }

    /** Le procédé tel que la fenêtre le dit : choisi, écrit dans « Autre », ou tapé sans choisir. */
    function procedeSaisi(form) {
        const choisi = form.elements.type.value;
        if (choisi === 'autre') return form.elements.type_autre.value.trim();
        const tape = form.querySelector('[data-liste-filtrable="cordon-procede"] .liste-filtrable-champ');
        return choisi || (tape ? tape.value.trim() : '');
    }

    function ouvrirCordon(viewer, numero) {
        const donnees = viewer.cordons;
        const ligne = donnees && donnees.lignes.get(numero);
        const fenetre = fenetreCordon();
        if (!ligne || !fenetre) return;
        const form = fenetre.querySelector('form');
        fiche = { viewer, numero };

        const etat = etatCordon(ligne);
        // Le cordon d'un autre soudeur se lit sans se reprendre ; une feuille close aussi.
        const figee = ligne.verrouillee || estClose(donnees);

        fenetre.querySelector('[data-cordon-numero]').textContent = numero;
        const puce = fenetre.querySelector('[data-cordon-etat]');
        puce.hidden = !PUCES[etat.cle];
        puce.className = 'pv-puce soudures-cordon-etat ' + (PUCES[etat.cle] || '');
        puce.textContent = etat.cle === 'verrouillee' ? 'Soudée par ' + (ligne.soudeur || 'un autre soudeur')
            : etat.cle === 'en-attente' ? 'En attente du chef' : 'Validée';

        // Un cordon à faire s'ouvre sur la date du jour et le dernier procédé : on soude, on
        // clique, on enregistre. Rien ne part sans « Enregistrer ».
        form.elements.date.value = ligne.date || (figee ? '' : aujourdhui());
        poserProcede(fenetre, ligne.type || (figee ? '' : dernierProcede));
        form.elements.date.disabled = figee;
        form.elements.type_autre.disabled = figee;
        const champProcede = fenetre.querySelector('[data-liste-filtrable="cordon-procede"] .liste-filtrable-champ');
        champProcede.disabled = figee;
        form.querySelector('[data-cordon-effacer]').hidden = figee || !ligne.remplie;
        form.querySelector('.fenetre-valider').hidden = figee;
        form.querySelector('.fenetre-annuler').textContent = figee ? 'Fermer' : 'Annuler';
        const message = form.querySelector('.fenetre-message');
        message.textContent = '';
        message.classList.remove('is-error');

        majNumeros(viewer);
        fenetre.showModal();
        // La date est déjà là. Le procédé aussi, le plus souvent — celui du cordon d'avant :
        // il n'y a plus qu'à enregistrer, et Entrée le fait. Sinon c'est lui qu'on vient
        // choisir, et sa liste s'ouvre. Ouverte d'office, elle couvrait « Enregistrer ».
        const suivant = figee ? form.querySelector('.fenetre-annuler')
            : procedeSaisi(form) ? form.querySelector('.fenetre-valider') : champProcede;
        suivant.focus();
    }

    /**
     * Enregistre le cordon de la fenêtre, et lui seul : l'action reçoit les cordons qu'on lui
     * envoie et laisse les autres tels quels. Effacer, c'est l'envoyer vide — il redevient
     * à faire.
     */
    async function enregistrerCordon(effacer) {
        if (!fiche) return;
        const { viewer, numero } = fiche;
        const fenetre = fenetreCordon();
        const form = fenetre.querySelector('form');
        const donnees = viewer.cordons;
        const bloc = viewer.closest('.soudures-lecteur');
        const message = form.querySelector('.fenetre-message');
        const date = effacer ? '' : form.elements.date.value;
        const type = effacer ? '' : procedeSaisi(form);
        if (!effacer && !date && !type) {
            message.textContent = 'Notez la date ou le procédé.';
            message.classList.add('is-error');
            return;
        }

        const corps = new URLSearchParams();
        corps.set('csrf', bloc.dataset.csrf);
        corps.set('element', viewer.dataset.plan);
        corps.set('document', viewer.dataset.document);
        corps.set('page', donnees.page);
        corps.set('soudures[' + numero + '][date]', date);
        corps.set('soudures[' + numero + '][type]', type);

        const boutons = form.querySelectorAll('.fenetre-pied button');
        boutons.forEach(bouton => { bouton.disabled = true; });
        message.classList.remove('is-error');
        message.textContent = 'Enregistrement…';
        try {
            const reponse = await fetch('actions/save_soudures_atelier.php', {
                method: 'POST', body: corps,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const retour = await reponse.json();
            if (!reponse.ok || !retour.success) {
                message.textContent = retour.message || 'Enregistrement impossible.';
                message.classList.add('is-error');
                return;
            }
            if (type) dernierProcede = type;
            // Ce que le serveur vient de dire fait foi : la page reprend ses lignes.
            if (viewer.cordons === donnees) {
                donnees.lignes = parNumero(retour.lignes);
                donnees.reponse = retour;
            }
            // La fenêtre se referme sur le dessin : la ligne qui vient de s'écrire, dans sa
            // couleur, dit que c'est fait. Aucun message n'a à le redire en haut.
            fenetre.close();
            majNumeros(viewer);
            ecrireEnAttente(viewer);
            // Un cordon validé qu'on reprend repart chez le chef : la liste du dessin se
            // relit, et il n'y est plus écrit comme validé.
            if (typeof window.planRelireSoudures === 'function') window.planRelireSoudures(viewer);
            majFeuille(repereDe(viewer, donnees.page), retour);
        } catch {
            message.textContent = 'Le serveur ne répond pas.';
            message.classList.add('is-error');
        } finally {
            boutons.forEach(bouton => { bouton.disabled = false; });
        }
    }

    // Une ligne de la liste soudure, un numéro le long du tube, ou un cordon hors de la liste
    // sous le titre : sa fenêtre s'ouvre.
    document.addEventListener('click', evenement => {
        const numero = evenement.target.closest('.plan-ligne-soudure, .plan-cordon, .soudures-hors-cordon');
        if (!numero) return;
        const viewer = numero.closest('.plan-viewer');
        if (duSoudeur(viewer)) ouvrirCordon(viewer, numero.dataset.numero);
    });

    document.addEventListener('submit', evenement => {
        if (!evenement.target.closest('#soudures-cordon-form')) return;
        evenement.preventDefault();
        enregistrerCordon(false);
    });

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('#soudures-cordon [data-cordon-effacer]')) enregistrerCordon(true);
        else if (evenement.target.closest('#soudures-cordon [data-fermer-cordon]')) fenetreCordon()?.close();
    });

    // La fenêtre refermée — enregistrée, annulée, ou par Échap — rend sa couleur à la ligne.
    document.addEventListener('close', evenement => {
        if (evenement.target.id !== 'soudures-cordon' || !fiche) return;
        const { viewer } = fiche;
        fiche = null;
        majNumeros(viewer);
    }, true);

    // Cliquer la ligne d'une feuille ouvre son dessin : c'est le geste qu'on a dans une liste.
    // Le nom est un bouton, pour le clavier ; c'est lui que la ligne actionne.
    document.addEventListener('click', evenement => {
        const ligne = evenement.target.closest('#soudures-page .soudures-feuille');
        if (!ligne || evenement.target.closest('button, a, input, label')) return;
        ligne.querySelector('.soudures-ouvrir-plan')?.click();
    });

    // ===== La fenêtre du plan =====
    //
    // Une par plan, rendue à la première feuille qu'on en ouvre (actions/soudures_lecteur.php)
    // et posée sous le tableau : l'écran montre tous les plans de la personne, et n'a pas à
    // construire des fenêtres qu'on n'ouvrira pas. Elle est demandée dès que le pointeur passe
    // sur une feuille de ce plan, ou que le clavier arrive sur son bouton : au clic, elle est
    // d'ordinaire déjà là.

    /** Le bloc qui porte la fenêtre du plan de cet élément : déjà dans la page, ou demandé. */
    function lecteurDe(element) {
        const racine = page();
        const zone = racine && racine.querySelector('[data-lecteurs]');
        if (!zone) return Promise.reject(new Error(''));
        const present = zone.querySelector('.soudures-lecteur[data-element="' + element + '"]');
        if (present) return Promise.resolve(present);
        // Une demande par plan, que le survol et le clic se partagent. Elle est gardée sur la
        // zone : l'écran rechargé repart de zéro.
        zone.demandes ??= new Map();
        if (!zone.demandes.has(element)) {
            zone.demandes.set(element, fetch('actions/soudures_lecteur.php?plan=' + encodeURIComponent(element),
                { credentials: 'same-origin' })
                .then(async reponse => {
                    const corps = await reponse.json().catch(() => null);
                    if (!reponse.ok || !corps?.html) throw new Error(corps?.message || '');
                    zone.insertAdjacentHTML('beforeend', corps.html);
                    const bloc = zone.querySelector('.soudures-lecteur[data-element="' + element + '"]');
                    bloc?.querySelectorAll('.plan-viewer').forEach(equiperBarre);
                    return bloc;
                })
                .catch(erreur => {
                    // Ratée, elle se redemande au geste suivant.
                    zone.demandes.delete(element);
                    throw erreur;
                }));
        }
        return zone.demandes.get(element);
    }

    // Le survol d'une feuille, ou le clavier sur son bouton : la fenêtre de son plan se
    // prépare. Un échec ne dit rien ici ; le clic le dira.
    ['pointerover', 'focusin'].forEach(type => document.addEventListener(type, evenement => {
        const ligne = evenement.target.closest('#soudures-page .soudures-feuille');
        if (ligne) lecteurDe(ligne.dataset.element).catch(() => {});
    }));

    // « Renseigner » — ou toute la ligne, qui l'actionne : la fenêtre du plan s'ouvre sur la
    // feuille désignée, l'élément, le cahier ET la page, pas sur la première page du cahier. La
    // page est donnée à l'ouverture (pageVoulue, plan-viewer.js) : une seule image, une seule
    // lecture des tableaux.
    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('#soudures-page .soudures-ouvrir-plan');
        if (!bouton) return;
        const [element, feuille, numeroPage] = String(bouton.dataset.repere || '').split(':');
        if (!element) return;
        let bloc;
        try {
            bloc = await lecteurDe(element);
        } catch (erreur) {
            window.toast?.(erreur.message || 'Le plan n’a pas pu s’ouvrir. Réessayez.', { erreur: true });
            return;
        }
        const lecteur = bloc && bloc.querySelector('.plan-viewer[data-document="' + feuille + '"]');
        // Deux clics pendant que la fenêtre arrive : elle ne s'ouvre qu'une fois.
        if (!lecteur || lecteur.querySelector('.plan-dialog')?.open) return;
        bloc.hidden = false;
        // Ce qui se pose sur le dessin suit la feuille avant que la fenêtre paraisse : sa
        // dernière lecture si on l'a déjà ouverte, rien sinon — jamais les lignes de la
        // feuille d'avant, le temps que celle-ci arrive.
        const page = Number(numeroPage) || 1;
        const deja = lectureDe(lecteur, page);
        if (lecteur.cordons !== deja) {
            lecteur.cordons = deja;
            poserDessin(lecteur);
        }
        lecteur.dataset.pageVoulue = page;
        lecteur.querySelector('.plan-viewer-ouvrir').click();
    });

    // ===== Les plans, repliés ou déroulés =====
    //
    // Chaque plan arrive replié : sa ligne, son avancement, et ses feuilles se déroulent
    // dessous d'un clic. Une recherche ou un onglet déroule de lui-même les plans où il trouve
    // des feuilles — on cherche la feuille qu'on a en main sans savoir de quel plan elle est —,
    // et les vider rend les plans comme on les avait laissés.

    /** Les plans déroulés, par leur élément. */
    const plansDeroules = racine => new Set([...racine.querySelectorAll('.soudures-plan')]
        .filter(groupe => groupe.querySelector('.soudures-plan-bascule')?.getAttribute('aria-expanded') === 'true')
        .map(groupe => groupe.dataset.element));

    /** Déroule ou replie un plan. Sa flèche le dit ; filtrer() montre ou cache ses feuilles. */
    function derouler(groupe, ouvert) {
        groupe.querySelector('.soudures-plan-bascule')?.setAttribute('aria-expanded', String(ouvert));
    }

    // Le mouvement. Dérouler fait entrer les feuilles l'une après l'autre, et ce qui est
    // dessous descend au lieu de sauter ; replier les efface, puis le dessous remonte. Un
    // tableau ne sait pas animer sa hauteur : on note où était chaque ligne avant le
    // changement, puis on la fait glisser de là à sa nouvelle place. Rien ne bouge quand le
    // système demande moins d'animations, ni quand c'est la recherche qui déroule : la liste
    // ne danse pas à chaque lettre tapée.
    const MOUVEMENT = { duration: 220, easing: 'cubic-bezier(.2, .8, .2, 1)' };
    const sansMouvement = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** Les lignes montrées d'un plan : ses feuilles, ou sa ligne d'attente. */
    const lignesDuPlan = groupe => [...page().querySelectorAll(
        '.soudures-feuille[data-element="' + groupe.dataset.element + '"]:not([hidden]) > tr,'
        + ' .soudures-plan-vide[data-element="' + groupe.dataset.element + '"]:not([hidden]) > tr')];

    /** Ce qui est sous un plan — les lignes des plans suivants, le matériel —, et où c'est. */
    function placesDessous(groupe) {
        const places = new Map();
        for (let corps = groupe.nextElementSibling; corps; corps = corps.nextElementSibling) {
            if (corps.hidden || corps.dataset.element === groupe.dataset.element) continue;
            [...corps.rows].forEach(ligne => places.set(ligne, ligne.getBoundingClientRect().top));
        }
        const materiel = page()?.querySelector('.soudures-materiel');
        if (materiel) places.set(materiel, materiel.getBoundingClientRect().top);
        return places;
    }

    /** Chacun glisse de sa place d'avant à la sienne. Sous l'écran, personne ne le verrait. */
    function glisser(places) {
        places.forEach((avant, element) => {
            // Un glissement pas fini s'arrête : le suivant part d'où l'on voyait la ligne.
            element.getAnimations().forEach(animation => animation.cancel());
            const apres = element.getBoundingClientRect().top;
            if (avant === apres || Math.min(avant, apres) > window.innerHeight) return;
            element.animate([{ transform: 'translateY(' + (avant - apres) + 'px)' }, { transform: 'none' }], MOUVEMENT);
        });
    }

    /** Déroule ou replie un plan sous les yeux de qui a cliqué. */
    function basculer(groupe, ouvert) {
        // Un repli en cours s'arrête : on rouvre avant qu'il ait fini.
        groupe.sortie?.forEach(animation => animation.cancel());
        groupe.sortie = null;
        derouler(groupe, ouvert);
        if (sansMouvement()) {
            filtrer();
            return;
        }

        if (ouvert) {
            const places = placesDessous(groupe);
            filtrer();
            glisser(places);
            // Les feuilles entrent l'une après l'autre ; passé la dixième, d'un bloc.
            lignesDuPlan(groupe).forEach((ligne, rang) => ligne.animate(
                [{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }],
                { ...MOUVEMENT, delay: Math.min(rang, 10) * 16, fill: 'backwards' }));
            return;
        }

        // Les feuilles s'effacent d'abord, à leur place ; puis elles disparaissent, et le
        // dessous remonte d'où il était.
        const sortie = lignesDuPlan(groupe).map(ligne => ligne.animate(
            [{ opacity: 1 }, { opacity: 0, transform: 'translateY(-4px)' }],
            { duration: 120, easing: 'ease-in', fill: 'forwards' }));
        groupe.sortie = sortie;
        Promise.all(sortie.map(animation => animation.finished)).then(() => {
            if (groupe.sortie !== sortie) return;
            groupe.sortie = null;
            const places = placesDessous(groupe);
            filtrer();
            sortie.forEach(animation => animation.cancel());
            glisser(places);
        }, () => {});
    }

    // Un clic sur la ligne d'un plan — sa flèche, son nom, n'importe où — le déroule ou le
    // replie. Déroulé, la fenêtre de son plan se prépare : on va ouvrir l'une de ses feuilles.
    document.addEventListener('click', evenement => {
        const groupe = evenement.target.closest('#soudures-page .soudures-plan');
        if (!groupe) return;
        const ouvert = groupe.querySelector('.soudures-plan-bascule')?.getAttribute('aria-expanded') !== 'true';
        basculer(groupe, ouvert);
        if (ouvert && page()?.querySelector('.soudures-feuille[data-element="' + groupe.dataset.element + '"]')) {
            lecteurDe(groupe.dataset.element).catch(() => {});
        }
    });

    // « Actualiser », comme sur les autres listes : l'écran se recharge, sa recherche, son
    // onglet et ses plans déroulés avec.
    document.addEventListener('click', evenement => {
        if (!evenement.target.closest('.soudures-actualiser')) return;
        const racine = page();
        const recherche = racine?.querySelector('#soudures-recherche')?.value || '';
        const etat = racine?.querySelector('.soudures-onglet[aria-pressed="true"]')?.dataset.etat || '';
        const deroules = racine ? plansDeroules(racine) : new Set();
        const avantFiltre = racine?.deroulesAvantFiltre || null;
        const contenu = document.getElementById('content');
        if (!contenu) return;
        window.chargerDans(contenu, 'pages/soudures.php').then(({ ok }) => {
            if (!ok) {
                contenu.textContent = 'Impossible de recharger cet écran. Actualisez la page.';
                return;
            }
            const champ = document.getElementById('soudures-recherche');
            if (champ) champ.value = recherche;
            document.querySelectorAll('.soudures-onglet').forEach(o => o.setAttribute('aria-pressed', String(o.dataset.etat === etat)));
            if (!document.querySelector('.soudures-onglet[aria-pressed="true"]')) {
                document.querySelector('.soudures-onglet[data-etat=""]')?.setAttribute('aria-pressed', 'true');
            }
            const rechargee = page();
            if (rechargee) {
                rechargee.querySelectorAll('.soudures-plan').forEach(groupe => derouler(groupe, deroules.has(groupe.dataset.element)));
                rechargee.deroulesAvantFiltre = avantFiltre;
            }
            filtrer();
        });
    });

    // Le filtrage : tout est déjà rendu, on se contente de cacher ce qui ne correspond
    // pas. Un cahier porte quinze feuilles et on en a souvent trois : quarante lignes se
    // parcourent mal à l'œil, et un rechargement serveur à chaque frappe coûterait plus
    // qu'il ne rapporte. C'est la mécanique de « Commande matériel » : la recherche et
    // l'onglet se combinent, et les comptes des onglets suivent la recherche.
    //
    // « deroulerTrouves » : la recherche ou l'onglet vient de changer, et les plans suivent —
    // ceux où ils trouvent se déroulent, les autres se replient. Le premier filtre retient
    // comment on les avait laissés, et le dernier qu'on vide le rend.
    function filtrer({ deroulerTrouves = false } = {}) {
        const racine = page();
        if (!racine) return;
        const texte = (racine.querySelector('#soudures-recherche')?.value || '').trim().toLowerCase();
        const etat = racine.querySelector('.soudures-onglet[aria-pressed="true"]')?.dataset.etat || '';
        const filtre = Boolean(texte || etat);

        // Ce que la recherche et l'onglet retiennent, et les plans où ils le trouvent.
        const parEtat = {};
        const retenues = new Map();
        const plansTrouves = new Set();
        racine.querySelectorAll('.soudures-feuille').forEach(ligne => {
            const trouvee = !texte || (ligne.dataset.cherche || '').includes(texte);
            if (trouvee) parEtat[ligne.dataset.etat] = (parEtat[ligne.dataset.etat] || 0) + 1;
            const retenue = trouvee && (!etat || ligne.dataset.etat === etat);
            retenues.set(ligne, retenue);
            if (retenue) plansTrouves.add(ligne.dataset.element);
        });
        // Un plan sans feuille garde sa ligne d'attente tant qu'aucun état n'est choisi : c'est
        // là qu'on apprend qu'il attend son dessin. Elle ne compte dans aucun onglet.
        racine.querySelectorAll('.soudures-plan-vide').forEach(vide => {
            const retenue = !etat && (!texte || (vide.dataset.cherche || '').includes(texte));
            retenues.set(vide, retenue);
            if (retenue) plansTrouves.add(vide.dataset.element);
        });

        if (deroulerTrouves && filtre) {
            racine.deroulesAvantFiltre ??= plansDeroules(racine);
            racine.querySelectorAll('.soudures-plan').forEach(groupe => derouler(groupe, plansTrouves.has(groupe.dataset.element)));
        } else if (deroulerTrouves && racine.deroulesAvantFiltre) {
            const avant = racine.deroulesAvantFiltre;
            racine.querySelectorAll('.soudures-plan').forEach(groupe => derouler(groupe, avant.has(groupe.dataset.element)));
            racine.deroulesAvantFiltre = null;
        }

        // Une feuille paraît si elle est retenue et que son plan est déroulé. La ligne d'un plan
        // reste sans filtre, repliée ou non ; avec un filtre, seulement s'il y trouve quelque
        // chose.
        const deroules = plansDeroules(racine);
        retenues.forEach((retenue, ligne) => { ligne.hidden = !(retenue && deroules.has(ligne.dataset.element)); });
        let plans = 0;
        racine.querySelectorAll('.soudures-plan').forEach(groupe => {
            groupe.hidden = filtre && !plansTrouves.has(groupe.dataset.element);
            if (!groupe.hidden) plans++;
        });

        racine.querySelectorAll('.soudures-onglet').forEach(onglet => {
            const compte = onglet.querySelector('.prefa-onglet-compte');
            if (!compte) return;
            compte.textContent = onglet.dataset.etat
                ? (parEtat[onglet.dataset.etat] || 0)
                : Object.values(parEtat).reduce((a, b) => a + b, 0);
        });

        // Un filtre peut tout cacher : un mot le dit plutôt que de laisser un tableau sans
        // lignes sous ses en-têtes.
        const aucune = racine.querySelector('[data-aucune]');
        if (aucune) {
            aucune.hidden = plans > 0;
            aucune.querySelector('td').textContent = texte
                ? 'Aucune feuille ne correspond à cette recherche.'
                : 'Aucune feuille dans cet état.';
        }
    }

    document.addEventListener('input', evenement => {
        if (evenement.target.closest('#soudures-recherche')) filtrer({ deroulerTrouves: true });
    });
    document.addEventListener('click', evenement => {
        const onglet = evenement.target.closest('.soudures-onglet');
        if (!onglet) return;
        document.querySelectorAll('.soudures-onglet').forEach(o => o.setAttribute('aria-pressed', String(o === onglet)));
        filtrer({ deroulerTrouves: true });
    });
    // Entrée dans la recherche n'envoie rien : tout est déjà là.
    document.addEventListener('submit', evenement => {
        if (evenement.target.closest('.soudures-filtres')) evenement.preventDefault();
    });

    // ===== Signaler un manque =====
    //
    // La fenêtre de la maison, ouverte depuis l'en-tête, depuis la barre du dessin, ou en
    // cliquant sur le dessin la ligne de nomenclature du matériel qui manque. Le plan proposé
    // est celui qu'on regarde ; les articles suggérés sont ceux du bon de commande de sa
    // demande — on peut toujours en écrire un autre.
    const fenetreManque = () => document.getElementById('soudures-manque');

    /** La fenêtre du plan ouverte sur cet écran, s'il y en a une. */
    const dessinOuvert = () => document.querySelector('.soudures-lecteur .plan-dialog[open]')?.closest('.plan-viewer') || null;

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

    /**
     * Ouvre la fenêtre. Depuis une ligne de la nomenclature, elle arrive avec l'article de la
     * ligne écrit dans « Autre article… » — tel que le plan l'imprime —, le repère et la
     * feuille en précision, et la quantité du plan en invite. La quantité qui manque, elle,
     * n'est pas devinée : il en manque peut-être une partie seulement. Le motif non plus.
     *
     * @param {{article?: string, auPlan?: string, precision?: string}} proposition
     */
    function ouvrirManque(proposition = {}) {
        const fenetre = fenetreManque();
        if (!fenetre) return;
        const form = fenetre.querySelector('form');
        form.reset();
        // Le plan du dessin ouvert s'il y en a un. Sinon rien n'est choisi : l'écran montre tous
        // les plans, et n'en devine pas un — sauf s'il n'y en a qu'un, déjà posé.
        const plan = dessinOuvert()?.dataset.plan || '';
        if (fenetre.querySelector('[data-liste-filtrable="manque-plan"]')) choisirDans(fenetre, 'manque-plan', plan);
        else if (plan) form.querySelector('[name="element"]').value = plan;
        choisirDans(fenetre, 'manque-motif', '');
        choisirDans(fenetre, 'manque-unite', '');
        poserArticles(fenetre);

        if (proposition.article) {
            if (!fenetre.querySelector('[data-liste-filtrable="manque-article"]').hidden) choisirDans(fenetre, 'manque-article', 'autre');
            fenetre.querySelector('#manque-article-autre-field').hidden = false;
            fenetre.querySelector('#manque-article-autre').value = proposition.article;
        }
        const quantite = form.querySelector('[name="quantite"]');
        quantite.dataset.invite ??= quantite.placeholder;
        quantite.placeholder = proposition.auPlan ? 'Quantité (' + proposition.auPlan + ' au plan)' : quantite.dataset.invite;
        form.querySelector('[name="commentaire"]').value = proposition.precision || '';

        const message = form.querySelector('.fenetre-message');
        message.textContent = '';
        message.classList.remove('is-error');
        fenetre.showModal();
        const premier = proposition.article ? quantite
            : fenetre.querySelector('[data-liste-filtrable="manque-article"]:not([hidden]) .liste-filtrable-champ')
                || fenetre.querySelector('#manque-article-autre');
        premier?.focus();
    }

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('[data-signaler-manque]')) ouvrirManque();
    });

    // Une ligne de la nomenclature, sur le dessin : la fenêtre s'ouvre sur son article.
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.plan-ligne-materiel');
        const viewer = bouton && bouton.closest('.plan-viewer');
        if (!duSoudeur(viewer)) return;
        const ligne = viewer.cordons?.nomenclature?.lignes?.[Number(bouton.dataset.rang)];
        if (!ligne) return;
        const feuille = document.querySelector('#soudures-page .soudures-feuille[data-repere="'
            + repereDe(viewer, pageVue(viewer)) + '"]')?.dataset.feuille || '';
        ouvrirManque({
            article: ligne.designation || '',
            auPlan: ligne.quantite || '',
            precision: 'Repère ' + ligne.repere + (feuille ? ' · ' + feuille : ''),
        });
    });

    /** La date du jour comme le serveur l'écrit : « 02/11/2026 ». */
    function dateDuJour() {
        const jour = new Date();
        const deux = nombre => String(nombre).padStart(2, '0');
        return deux(jour.getDate()) + '/' + deux(jour.getMonth() + 1) + '/' + jour.getFullYear();
    }

    /**
     * Après un signalement, les lignes de la nomenclature ouverte qui portent cet article
     * prennent leur marque — la règle du serveur, qui les reconnaît à leur article : un
     * article réécrit dans la fenêtre ne marque aucune ligne, ni maintenant ni plus tard.
     */
    function marquerSignale(article) {
        const viewer = dessinOuvert();
        const nomenclature = viewer?.cordons?.nomenclature;
        if (!nomenclature || !article) return;
        const cherche = article.trim().toLowerCase();
        nomenclature.lignes.forEach(ligne => {
            if ((ligne.designation || '').trim().toLowerCase() === cherche) ligne.signale = dateDuJour();
        });
        viewer.querySelectorAll('.plan-ligne-materiel').forEach(bouton => {
            const ligne = nomenclature.lignes[Number(bouton.dataset.rang)];
            if (ligne) marquerMateriel(bouton, ligne);
        });
        // La légende gagne « Manque signalé » si c'est le premier de la page.
        dessinerEtat(viewer);
    }

    // Le composant prévient par un « change » sur son champ caché.
    document.addEventListener('change', evenement => {
        if (evenement.target.closest('#soudures-manque input[name="element"]')) poserArticles(fenetreManque());
    });

    // Un article du bon apporte son unité : « m » pour un tube, « u » pour une bride. On ne
    // l'écrase pas si la personne en a déjà choisi une.
    //
    // Quand l'unité se choisit dans la liste des paramètres, celle du bon n'est reprise que
    // si la liste la connaît — sans égard aux majuscules : « pce » lu sur le bon retient
    // « PCE ». Une unité que la liste n'a pas reste à choisir.
    document.addEventListener('change', evenement => {
        const champ = evenement.target.closest('#soudures-manque input[name="designation"]');
        if (!champ) return;
        const fenetre = fenetreManque();
        const unite = fenetre.querySelector('[name="unite"]');
        const option = [...fenetre.querySelectorAll('[data-liste-filtrable="manque-article"] [role="option"]')]
            .find(o => o.dataset.value === champ.value);
        const duBon = option?.dataset.unite || '';
        if (!duBon || unite.value) return;
        const liste = fenetre.querySelector('[data-liste-filtrable="manque-unite"]');
        if (!liste) {
            unite.value = duBon;
            return;
        }
        const connue = [...liste.querySelectorAll('[role="option"]')]
            .find(o => o.dataset.value.toLowerCase() === duBon.toLowerCase());
        if (connue) choisirDans(fenetre, 'manque-unite', connue.dataset.value);
    });

    document.addEventListener('click', evenement => {
        if (evenement.target.closest('[data-fermer-manque]')) fenetreManque()?.close();
    });

    document.addEventListener('submit', async event => {
        const form = event.target.closest('#soudures-manque-form');
        if (!form) return;
        event.preventDefault();
        const message = form.querySelector('.fenetre-message');
        const bouton = form.querySelector('.fenetre-valider');
        // Les listes de la maison écrivent dans des champs cachés, qu'aucun « required »
        // ne couvre : on dit ce qui manque avant d'envoyer.
        const article = form.querySelector('[name="designation"]').value;
        const libre = form.querySelector('#manque-article-autre').value.trim();
        const manque = !form.querySelector('[name="element"]').value ? 'Choisissez le plan.'
            : (!article || article === 'autre') && !libre ? 'Indiquez l’article qui manque.'
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
            // Signalé depuis le dessin : sa barre le dit, la ligne de l'article prend sa
            // marque, et il reste ouvert. Sinon, le tableau du matériel, sous les feuilles,
            // suffit.
            const dessin = dessinOuvert();
            if (dessin) {
                direDansLeDessin(dessin, corps.message, false);
                marquerSignale(!article || article === 'autre' ? libre : article);
            } else {
                racine?.querySelector('.soudures-materiel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        } catch {
            message.classList.add('is-error');
            message.textContent = 'Le serveur ne répond pas.';
        } finally {
            bouton.disabled = false;
        }
    });

    // Rien ne se lit à l'arrivée : aucune feuille n'est ouverte, et la première lecture
    // d'une page coûte quelques secondes — les dépenser pour une feuille que personne n'a
    // demandée, c'est faire attendre devant un tableau qu'on n'allait pas remplir.
})();
