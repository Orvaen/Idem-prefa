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
        scene.querySelectorAll('.plan-cordon, .plan-ligne-soudure, .plan-ligne-materiel, .plan-ligne-coulee').forEach(bouton => bouton.remove());
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

        // Les lignes de la nomenclature : on clique celle du matériel qui manque, comme on la
        // montrerait du doigt sur le tirage. Sa dernière case, « N° COULÉE », se touche à
        // part : elle ouvre le choix de la coulée de la ligne. Les deux se gênaient — la ligne
        // couvrait le tableau d'un bord à l'autre, et le choix de la coulée avait disparu
        // dessous. Une lecture qui n'a pas trouvé la colonne laisse la ligne entière au
        // signalement.
        const nomenclature = donnees.nomenclature;
        if (nomenclature && nomenclature.lignes) {
            const coulee = nomenclature.coulee;
            const finMateriel = coulee ? coulee.gauche : nomenclature.droite;
            nomenclature.lignes.forEach((ligne, rang) => {
                const bouton = boutonSurLeDessin(scene, 'plan-ligne-materiel', ligne.repere, nomenclature.gauche, ligne.y,
                    finMateriel - nomenclature.gauche, ligne.h);
                bouton.dataset.rang = rang;
                marquerMateriel(bouton, ligne);
                if (coulee) {
                    const caseCoulee = boutonSurLeDessin(scene, 'plan-ligne-coulee', ligne.repere, coulee.gauche, ligne.y,
                        coulee.droite - coulee.gauche, ligne.h);
                    caseCoulee.dataset.rang = rang;
                    marquerCoulee(caseCoulee, ligne, couleeDeLigne(donnees, ligne));
                }
            });
        }
        majNumeros(viewer);
    }

    /**
     * La coulée déjà posée sur une ligne : la case dont le milieu tombe dans la bande de la
     * ligne — la même règle que le serveur (nomenclatureCouleeDeLigne()).
     */
    function couleeDeLigne(donnees, ligne) {
        const posee = (donnees.reponse?.coulees_posees || []).find(caseCoulee => {
            const milieu = caseCoulee.y + caseCoulee.h / 2;
            return milieu >= ligne.y && milieu <= ligne.y + ligne.h;
        });
        return posee ? posee.coulee : '';
    }

    /** La case « N° COULÉE » d'une ligne : le numéro posé, et ce qu'elle dit au lecteur d'écran. */
    function marquerCoulee(bouton, ligne, coulee) {
        bouton.replaceChildren();
        const valeur = document.createElement('span');
        valeur.textContent = coulee;
        bouton.append(valeur);
        bouton.classList.toggle('est-vide', !coulee);
        const article = 'Repère ' + ligne.repere + (ligne.designation ? ' · ' + ligne.designation : '');
        bouton.setAttribute('aria-label', article + (coulee ? ' — coulée ' + coulee + ', la changer' : ' — choisir la coulée'));
        bouton.title = coulee ? 'Coulée ' + coulee + ' — toucher pour la changer' : 'Choisir la coulée de cette ligne';
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
     * La carte d'une feuille suit ce que le serveur vient de dire : son compte de soudures,
     * sa barre, et son état — la couleur de la barre, la coche d'une feuille finie, le mot
     * pour les lecteurs d'écran. Elle reste à sa place, dans l'ordre du cahier.
     *
     * Ils viennent du serveur et non de ce script : une feuille jamais ouverte ignorait son
     * total et vient de l'apprendre, et le libellé de l'état est celui de l'écran du chef.
     */
    function majFeuille(repere, reponse) {
        const carte = [...document.querySelectorAll('#soudures-page .soudures-feuille')]
            .find(candidate => candidate.dataset.repere === repere);
        const feuille = reponse.feuille;
        if (!carte || !feuille) return;

        const compte = carte.querySelector('[data-cordons]');
        if (compte && feuille.compte) compte.textContent = feuille.compte;
        if (feuille.etat) {
            const libelle = carte.querySelector('[data-etat-libelle]');
            if (libelle) libelle.textContent = feuille.etat.libelle;
            carte.dataset.etat = feuille.etat.cle;
        }
        const barre = carte.querySelector('.soudures-barre');
        if (barre && typeof feuille.part === 'number') {
            barre.firstElementChild.style.setProperty('--part', feuille.part + '%');
        }
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

    // Le doigt posé sur une feuille, le pointeur qui la survole, ou le clavier sur elle : la
    // fenêtre de son plan se prépare. Au doigt, il n'y a pas de survol : « pointerdown » gagne
    // le temps du toucher. Un échec ne dit rien ici ; le toucher le dira.
    ['pointerover', 'pointerdown', 'focusin'].forEach(type => document.addEventListener(type, evenement => {
        const carte = evenement.target.closest('#soudures-page .soudures-feuille');
        if (carte) lecteurDe(carte.dataset.element).catch(() => {});
    }));

    // La carte d'une feuille, touchée : la fenêtre du plan s'ouvre sur la feuille désignée,
    // l'élément, le cahier ET la page, pas sur la première page du cahier. La page est donnée
    // à l'ouverture (pageVoulue, plan-viewer.js) : une seule image, une seule lecture des
    // tableaux.
    //
    // La carte répond tout de suite — « Ouverture… » sur sa vignette : la fenêtre du plan peut
    // mettre une seconde à arriver la première fois, et une carte qui ne bouge pas sous le
    // doigt, c'est « il ne se passe rien ». Rien n'échoue plus en silence : un dessin
    // introuvable le dit, au lieu de laisser la carte sans réponse.
    document.addEventListener('click', async evenement => {
        const bouton = evenement.target.closest('#soudures-page .soudures-ouvrir-plan');
        if (!bouton || bouton.classList.contains('est-en-ouverture')) return;
        const [element, feuille, numeroPage] = String(bouton.dataset.repere || '').split(':');
        if (!element) return;
        bouton.classList.add('est-en-ouverture');
        bouton.setAttribute('aria-busy', 'true');
        const fini = () => {
            bouton.classList.remove('est-en-ouverture');
            bouton.removeAttribute('aria-busy');
        };
        let bloc;
        try {
            bloc = await lecteurDe(element);
        } catch (erreur) {
            fini();
            window.toast?.(erreur.message || 'Le plan n’a pas pu s’ouvrir. Réessayez.', { erreur: true });
            return;
        }
        const lecteur = bloc && bloc.querySelector('.plan-viewer[data-document="' + feuille + '"]');
        if (!lecteur) {
            fini();
            window.toast?.('Le dessin de cette feuille est introuvable. Touchez « Actualiser » ; si cela recommence, prévenez le chef d’atelier.', { erreur: true });
            return;
        }
        // Deux touchers pendant que la fenêtre arrive : elle ne s'ouvre qu'une fois.
        if (lecteur.querySelector('.plan-dialog')?.open) {
            fini();
            return;
        }
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
        fini();
    });

    // ===== Dans « Mes plans » : un plan se déplie sur ses feuilles =====
    //
    // « Mes soudures » vit dans « Mes plans » depuis le 6 novembre 2026 : chaque plan se
    // déplie d'un toucher — n'importe où sur sa ligne — sur ses feuilles en vignettes, et
    // toucher une feuille ouvre son dessin (plus haut). La flèche devant le nom est le
    // bouton, pour le clavier ; « Étapes » ouvre toujours le volet du plan.

    /** Déplie ou replie les feuilles d'un plan. Déplié, son dessin se prépare. */
    function deplier(plan, ouvert) {
        const bouton = plan.querySelector('.mes-plans-deplier');
        const feuilles = plan.querySelector('.mes-plans-feuilles');
        if (!bouton || !feuilles) return;
        bouton.setAttribute('aria-expanded', String(ouvert));
        feuilles.hidden = !ouvert;
        plan.classList.toggle('est-deplie', ouvert);
        const carte = ouvert && feuilles.querySelector('.soudures-feuille');
        if (carte) lecteurDe(carte.dataset.element).catch(() => {});
    }

    document.addEventListener('click', evenement => {
        const plan = evenement.target.closest('#mes-plans-page .mes-plans-plan');
        // Sa ligne seulement — pas celle de ses feuilles, ni son volet —, et pas ses boutons.
        if (!plan || evenement.target.closest('tr') !== plan.rows[0]) return;
        if (evenement.target.closest('a, input, label, button:not(.mes-plans-deplier)')) return;
        deplier(plan, plan.querySelector('.mes-plans-deplier')?.getAttribute('aria-expanded') !== 'true');
    });

    // « Renseigner mes soudures », dans le volet d'un plan : le volet se ferme, et le plan se
    // déplie sur ses feuilles. Il ouvrait un autre écran ; les feuilles sont ici, maintenant.
    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('#mes-plans-page .atelier-vers-soudures');
        if (!bouton) return;
        const plan = bouton.closest('.mes-plans-plan');
        bouton.closest('.prefa-detail-row')?.querySelector('.prefa-detail-close')?.click();
        if (!plan) return;
        deplier(plan, true);
        plan.rows[0].scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    // La recherche de « Mes plans » trouve aussi les feuilles, par leur numéro de ligne :
    // atelier.js cache les plans qui n'ont rien ; ici, les plans qui gardent des feuilles se
    // déplient dessus, et seules celles qui répondent restent. Vider la recherche rend les
    // plans comme on les avait laissés.
    document.addEventListener('input', evenement => {
        if (!evenement.target.closest('#mes-plans-recherche')) return;
        const racine = document.getElementById('mes-plans-page');
        if (!racine) return;
        const texte = evenement.target.value.trim().toLowerCase();
        if (texte && !racine.deplisAvantRecherche) {
            racine.deplisAvantRecherche = new Set(racine.querySelectorAll('.mes-plans-plan.est-deplie'));
        }
        racine.querySelectorAll('.mes-plans-plan').forEach(plan => {
            const cartes = [...plan.querySelectorAll('.soudures-feuille')];
            let gardees = 0;
            cartes.forEach(carte => {
                carte.hidden = Boolean(texte) && !(carte.dataset.cherche || '').includes(texte);
                if (!carte.hidden) gardees++;
            });
            if (texte && cartes.length) deplier(plan, gardees > 0);
        });
        if (!texte && racine.deplisAvantRecherche) {
            const avant = racine.deplisAvantRecherche;
            racine.querySelectorAll('.mes-plans-plan').forEach(plan => deplier(plan, avant.has(plan)));
            racine.deplisAvantRecherche = null;
        }
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

    // ===== La coulée d'une ligne de nomenclature =====
    //
    // La case « N° COULÉE » d'une ligne, sur le dessin, ouvre le choix de sa coulée : celles
    // lues sur les certificats de la demande, en gros boutons — un toucher suffit —, ou un
    // numéro tapé quand le certificat n'a pas été lu. L'enregistrement ne touche que cette
    // ligne (actions/coulee_soudeur.php) ; l'encadrement le voit dans sa visionneuse.
    //
    // Deux gestes, deux endroits — un seul champ qui faisait les deux (« Chercher dans la liste,
    // ou taper le numéro ») se comprenait mal, la liste étant déjà sous les yeux :
    //  - on touche une coulée de la liste. Longue — plus de douze —, la liste a un champ pour
    //    la chercher, en haut, qui ne fait que ça : trois chiffres tapés, elle se réduit aux
    //    coulées qui les contiennent ;
    //  - « Pas dans la liste ? », en bas : on tape le numéro, « Enregistrer » le garde mot pour
    //    mot — jamais la coulée qui lui ressemble : remplacer « 4681 » par « 468189 » serait une
    //    erreur de traçabilité (même règle que la visionneuse de l'encadrement). Une recherche
    //    qui ne trouve rien y reporte ce qu'on a tapé.
    //
    // Les coulées se rangent sous leur certificat, qui dit ses dimensions et sa nuance : on les
    // compare à la désignation de la ligne, écrite sous le titre. Celles déjà posées sur la
    // feuille viennent en tête quand la liste est longue, ou qu'elles n'y sont pas — un numéro
    // tapé à la main : le même tube revient d'une ligne à l'autre. La liste défile seule ; la
    // recherche, le bas et les boutons restent en place. Sur tablette, le clavier ne s'ouvre
    // pas de lui-même : on touche d'abord, on tape si besoin.
    let choixCoulee = null;
    // Au-delà, la liste ne se lit plus d'un coup d'œil (trois rangées) : elle se cherche.
    const LISTE_LONGUE = 12;
    // « 4681-B », « 4681 B » et « 4681b » se cherchent pareil.
    const normeCoulee = texte => String(texte).toUpperCase().replace(/[^0-9A-Z]/g, '');

    function fenetreCoulee() {
        if (choixCoulee) return choixCoulee;
        choixCoulee = document.createElement('dialog');
        choixCoulee.className = 'fenetre soudures-coulee';
        choixCoulee.setAttribute('aria-labelledby', 'soudures-coulee-titre');
        choixCoulee.innerHTML = `
            <form method="dialog" class="soudures-coulee-form">
                <div class="fenetre-entete">
                    <h2 id="soudures-coulee-titre">Coulée</h2>
                    <button type="button" class="modal-close" data-coulee-fermer aria-label="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
                <p class="fenetre-sous-titre soudures-coulee-article"></p>
                <div class="soudures-coulee-recherche" hidden>
                    <div class="soudures-coulee-chercher">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input class="fenetre-champ" name="cherche" type="search" autocomplete="off" spellcheck="false"
                               autocapitalize="characters" enterkeyhint="search" aria-label="Chercher une coulée dans la liste"
                               placeholder="Chercher dans la liste…">
                    </div>
                    <p class="fenetre-aide soudures-coulee-compte"></p>
                </div>
                <div class="fenetre-corps soudures-coulee-liste"></div>
                <div class="soudures-coulee-autre">
                    <label class="fenetre-etiquette" for="soudures-coulee-autre">Pas dans la liste ? Tapez le numéro</label>
                    <div class="soudures-coulee-autre-ligne">
                        <input id="soudures-coulee-autre" class="fenetre-champ" name="coulee" maxlength="20" autocomplete="off"
                               spellcheck="false" autocapitalize="characters" enterkeyhint="done" placeholder="N° de coulée">
                        <button type="submit" class="fenetre-valider">Enregistrer</button>
                    </div>
                    <p class="fenetre-message" role="status" aria-live="polite"></p>
                </div>
                <div class="fenetre-pied">
                    <button type="button" class="fenetre-annuler soudures-coulee-retirer" hidden>Retirer la coulée</button>
                    <button type="button" class="fenetre-annuler" data-coulee-fermer>Annuler</button>
                </div>
            </form>`;
        document.body.append(choixCoulee);

        choixCoulee.addEventListener('click', evenement => {
            if (evenement.target.closest('[data-coulee-fermer]')) choixCoulee.close();
            const option = evenement.target.closest('.soudures-coulee-option');
            if (option) enregistrerCoulee(option.dataset.numero);
            if (evenement.target.closest('.soudures-coulee-retirer')) enregistrerCoulee('');
        });
        const cherche = choixCoulee.querySelector('[name="cherche"]');
        cherche.addEventListener('input', () => filtrerCoulees(choixCoulee));
        // Entrée dans la recherche n'enregistre rien d'office : une seule coulée trouvée se
        // choisit, aucune passe la main au champ du bas, où le numéro tapé attend.
        cherche.addEventListener('keydown', evenement => {
            if (evenement.key !== 'Enter') return;
            evenement.preventDefault();
            const trouvees = [...choixCoulee.querySelectorAll('.soudures-coulee-groupe:not([hidden]):not([data-sorte]) .soudures-coulee-option:not([hidden])')];
            if (trouvees.length === 1) enregistrerCoulee(trouvees[0].dataset.numero);
            else if (!trouvees.length) choixCoulee.querySelector('[name="coulee"]').focus();
        });
        choixCoulee.querySelector('form').addEventListener('submit', evenement => {
            evenement.preventDefault();
            const saisie = choixCoulee.querySelector('[name="coulee"]').value.trim();
            if (!saisie) {
                choixCoulee.querySelector('.fenetre-message').textContent = 'Touchez une coulée de la liste, ou tapez son numéro.';
                return;
            }
            enregistrerCoulee(saisie);
        });
        return choixCoulee;
    }

    /** Un bouton de coulée : son numéro en gros, « à vérifier » dessous s'il le faut. */
    function boutonCoulee(numero, confirmee, actuelle) {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'soudures-coulee-option';
        option.dataset.numero = numero;
        option.dataset.cherche = normeCoulee(numero);
        option.setAttribute('aria-pressed', String(numero === actuelle));
        option.textContent = numero;
        if (!confirmee) {
            // Lue une seule fois, ou avec peu de certitude : à vérifier sur le certificat.
            const doute = document.createElement('small');
            doute.textContent = 'à vérifier';
            option.append(doute);
        }
        return option;
    }

    /** Un groupe de boutons sous son titre : un certificat, ou « Déjà sur cette feuille ». */
    function groupeCoulees(titre, boutons, sorte) {
        const groupe = document.createElement('section');
        groupe.className = 'soudures-coulee-groupe';
        if (sorte) groupe.dataset.sorte = sorte;
        const entete = document.createElement('h3');
        entete.className = 'soudures-coulee-groupe-titre';
        entete.textContent = titre;
        const options = document.createElement('div');
        options.className = 'soudures-coulee-options';
        options.setAttribute('role', 'group');
        options.setAttribute('aria-label', titre);
        options.append(...boutons);
        groupe.append(entete, options);
        return groupe;
    }

    /**
     * Ce que la recherche trouve : les boutons qui contiennent ce qu'on tape, les groupes qui en
     * gardent un, et une ligne qui dit combien. En cherchant, « Déjà sur cette feuille » se
     * retire : ses coulées sont aussi sous leur certificat, on les verrait deux fois. Rien de
     * trouvé : ce qu'on a tapé descend dans « Pas dans la liste ? », prêt à enregistrer.
     */
    function filtrerCoulees(fenetre) {
        const saisie = fenetre.querySelector('[name="cherche"]').value.trim();
        const cherche = normeCoulee(saisie);
        const liste = fenetre.querySelector('.soudures-coulee-liste');
        const total = Number(liste.dataset.total || 0);
        let visibles = 0;
        liste.querySelectorAll('.soudures-coulee-groupe').forEach(groupe => {
            if (groupe.dataset.sorte === 'feuille') {
                groupe.hidden = cherche !== '';
                return;
            }
            let gardes = 0;
            groupe.querySelectorAll('.soudures-coulee-option').forEach(option => {
                option.hidden = cherche !== '' && !option.dataset.cherche.includes(cherche);
                if (!option.hidden) gardes++;
            });
            groupe.hidden = gardes === 0;
            visibles += gardes;
        });

        const compte = fenetre.querySelector('.soudures-coulee-compte');
        const aucune = liste.querySelector('.soudures-coulee-aucune');
        const autre = fenetre.querySelector('[name="coulee"]');
        if (!total) {
            aucune.textContent = 'Aucune coulée n’a été lue sur les certificats de cette demande : tapez le numéro en bas.';
            aucune.hidden = false;
        } else if (!cherche) {
            compte.textContent = total + (total > 1 ? ' coulées lues' : ' coulée lue') + ' sur les certificats';
            aucune.hidden = true;
        } else {
            compte.textContent = visibles + ' sur ' + total;
            aucune.textContent = 'Aucune coulée de la liste ne contient « ' + saisie + ' ». Si c’est le bon numéro, touchez « Enregistrer » en bas.';
            aucune.hidden = visibles > 0;
        }
        // Ce qu'on a cherché sans le trouver descend en bas — sauf si l'on y a déjà tapé autre
        // chose soi-même.
        if (total && cherche && !visibles && (!autre.value || autre.dataset.repris === '1')) {
            autre.value = saisie;
            autre.dataset.repris = '1';
        } else if (autre.dataset.repris === '1' && (visibles || !cherche)) {
            autre.value = '';
            autre.dataset.repris = '';
        }
        fenetre.querySelector('.fenetre-message').textContent = '';
    }

    function ouvrirChoixCoulee(viewer, bouton) {
        const donnees = viewer.cordons;
        const nomenclature = donnees?.nomenclature;
        const ligne = nomenclature?.lignes?.[Number(bouton.dataset.rang)];
        if (!ligne || !nomenclature.coulee) return;
        const actuelle = couleeDeLigne(donnees, ligne);
        const fenetre = fenetreCoulee();
        fenetre.contexte = { viewer, bouton, ligne, page: donnees.page, coulee: nomenclature.coulee };

        fenetre.querySelector('#soudures-coulee-titre').textContent = 'Coulée du repère ' + ligne.repere;
        // La désignation, à comparer aux certificats ; et la coulée que porte déjà la ligne.
        fenetre.querySelector('.soudures-coulee-article').textContent =
            [ligne.designation || '', actuelle ? 'actuelle : ' + actuelle : ''].filter(Boolean).join(' · ');

        const coulees = donnees.reponse?.coulees || [];
        const connues = new Map(coulees.map(coulee => [coulee.numero, coulee]));
        const groupes = [];

        // Celles déjà posées sur la feuille, les plus posées d'abord.
        const posees = new Map();
        (donnees.reponse?.coulees_posees || []).forEach(caseCoulee => {
            const numero = String(caseCoulee.coulee || '').trim();
            if (numero) posees.set(numero, (posees.get(numero) || 0) + 1);
        });
        const dejaPosees = [...posees.entries()].sort((a, b) => b[1] - a[1]).map(([numero]) => numero);
        if (dejaPosees.length && (coulees.length > LISTE_LONGUE || dejaPosees.some(numero => !connues.has(numero)))) {
            groupes.push(groupeCoulees('Déjà sur cette feuille', dejaPosees.map(numero =>
                boutonCoulee(numero, connues.get(numero)?.confirmee ?? true, actuelle)), 'feuille'));
        }

        // Puis sous leur certificat, dans l'ordre où les certificats arrivent.
        const parCertificat = new Map();
        coulees.forEach(coulee => {
            const titre = coulee.certificat || 'Certificats';
            if (!parCertificat.has(titre)) parCertificat.set(titre, []);
            parCertificat.get(titre).push(boutonCoulee(coulee.numero, coulee.confirmee, actuelle));
        });
        parCertificat.forEach((boutons, titre) => groupes.push(groupeCoulees(titre, boutons)));

        const aucune = document.createElement('p');
        aucune.className = 'fenetre-aide soudures-coulee-aucune';
        aucune.hidden = true;
        const liste = fenetre.querySelector('.soudures-coulee-liste');
        liste.dataset.total = String(coulees.length);
        liste.replaceChildren(...groupes, aucune);
        const longue = coulees.length > LISTE_LONGUE;
        fenetre.classList.toggle('est-longue', longue);
        fenetre.classList.toggle('est-vide', coulees.length === 0);

        // La recherche n'existe que pour une longue liste ; le champ du bas, toujours. Une
        // coulée tapée à la main, absente des certificats, y revient pour qu'on la corrige.
        const recherche = fenetre.querySelector('.soudures-coulee-recherche');
        recherche.hidden = !longue;
        const cherche = fenetre.querySelector('[name="cherche"]');
        cherche.value = '';
        const autre = fenetre.querySelector('[name="coulee"]');
        autre.value = actuelle && !connues.has(actuelle) ? actuelle : '';
        autre.dataset.repris = '';
        filtrerCoulees(fenetre);
        fenetre.querySelector('.soudures-coulee-retirer').hidden = !actuelle;
        fenetre.showModal();
        liste.scrollTop = 0;
        // À la souris, une longue liste se cherche en tapant : la recherche prend la main. Au
        // doigt, le clavier couvrirait la moitié de la liste avant qu'on ait regardé : rien ne
        // s'ouvre, et la coulée actuelle est montrée.
        const choisie = liste.querySelector('.soudures-coulee-option[aria-pressed="true"]');
        if (!matchMedia('(pointer: coarse)').matches) (longue ? cherche : (coulees.length ? null : autre))?.focus();
        else if (choisie) choisie.scrollIntoView({ block: 'nearest' });
    }

    async function enregistrerCoulee(numero) {
        const fenetre = fenetreCoulee();
        const { viewer, bouton, ligne, page, coulee } = fenetre.contexte || {};
        const bloc = viewer?.closest('.soudures-lecteur');
        if (!bloc) return;
        const message = fenetre.querySelector('.fenetre-message');
        message.textContent = 'Enregistrement…';
        fenetre.querySelectorAll('button').forEach(b => { b.disabled = true; });

        const corps = new URLSearchParams();
        corps.set('csrf', bloc.dataset.csrf);
        corps.set('element', viewer.dataset.plan);
        corps.set('document', viewer.dataset.document);
        corps.set('page', page);
        corps.set('x', coulee.gauche);
        corps.set('y', ligne.y);
        corps.set('w', coulee.droite - coulee.gauche);
        corps.set('h', ligne.h);
        corps.set('coulee', numero);
        corps.set('description', ligne.designation || '');
        // Toutes les lignes de la page : sur une page encore sans case, le serveur en pose une
        // à chacune, comme la pose d'office de l'encadrement.
        const lignes = (viewer.lectures?.[page] || viewer.cordons)?.nomenclature?.lignes || [];
        corps.set('lignes', JSON.stringify(lignes.map(l => ({ y: l.y, h: l.h }))));
        try {
            const reponse = await fetch('actions/coulee_soudeur.php', {
                method: 'POST', body: corps,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const retour = await reponse.json().catch(() => null);
            if (!reponse.ok || !retour?.success) {
                message.textContent = retour?.message || 'Enregistrement impossible. Réessayez.';
                return;
            }
            // La case de la ligne prend son numéro tout de suite, et la page le retient : la
            // relire n'est pas nécessaire pour le voir.
            const donnees = viewer.lectures?.[page] || viewer.cordons;
            const posees = (donnees.reponse.coulees_posees ||= []).filter(caseCoulee => {
                const milieu = caseCoulee.y + caseCoulee.h / 2;
                return !(milieu >= ligne.y && milieu <= ligne.y + ligne.h);
            });
            posees.push({ y: ligne.y, h: ligne.h, coulee: retour.coulee });
            donnees.reponse.coulees_posees = posees;
            if (bouton.isConnected) marquerCoulee(bouton, ligne, retour.coulee);
            fenetre.close();
            window.toast?.(retour.message);
        } catch {
            message.textContent = 'Le serveur ne répond pas. Réessayez.';
        } finally {
            fenetre.querySelectorAll('button').forEach(b => { b.disabled = false; });
        }
    }

    document.addEventListener('click', evenement => {
        const bouton = evenement.target.closest('.plan-ligne-coulee');
        const viewer = bouton && bouton.closest('.plan-viewer');
        if (duSoudeur(viewer)) ouvrirChoixCoulee(viewer, bouton);
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
