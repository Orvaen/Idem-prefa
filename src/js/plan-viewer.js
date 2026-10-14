// Pose des numéros de coulée sur le plan joint.
//
// Le plan porte sa nomenclature imprimée, avec la colonne « N° COULÉE » laissée en
// blanc. La lecture du tableau y pose les cases ; le chef d'atelier ouvre le plan en
// grand et les manipule comme des éléments de mise en page : on clique pour
// sélectionner, on glisse depuis n'importe où pour déplacer, huit poignées
// redimensionnent, et le double-clic ouvre le choix de la coulée.
//
// Un clic sur le plan, lui, ne pose rien. On regarde un plan bien plus souvent qu'on le
// corrige, et chaque clic de lecture y semait une case vide. Une case qui manque se
// duplique depuis sa voisine, au clavier : c'est le seul geste qui en ajoute.
//
// Tout est stocké en fractions de l'image, jamais en pixels : une case posée à 400 %
// retrouve exactement sa place à 50 %.
(() => {
    const ZOOMS = [50, 75, 100, 150, 200, 300, 400];
    const ZOOM_MIN = 25;
    const ZOOM_MAX = 600;
    const LARGEUR_MINI = 1.5;
    const HAUTEUR_MINI = 0.6;

    // Ce qu'on écrit sur le plan doit avoir la taille de ce qu'y a imprimé le bureau
    // d'études. Mesuré sur les quinze plans du cahier : ses lettres font 0,556 du pas
    // d'une ligne. Une majuscule valant environ 70 % de la taille de police, il faut 0,795
    // du pas — soit 1,24 fois la hauteur d'une case, posée en retrait des traits et qui
    // n'occupe que 0,64 du pas. Vaut pour les numéros de coulée comme pour la liste
    // soudure : écrire plus petit que l'imprimé à côté se voit.
    const POLICE_PLAN = 1.24;

    // Un seul geste à la fois : déplacer une case ou la redimensionner. Un état unique
    // évite que deux gestionnaires se marchent dessus.
    let geste = null;
    let presse = null;

    /**
     * Les cases de la page affichée. Une case appartient à la page sur laquelle elle a
     * été posée : ses coordonnées sont des fractions de CE dessin.
     */
    const reperes = viewer => Array.from(viewer.querySelectorAll('.plan-coulee'))
        .filter(repere => Number(repere.dataset.page || 1) === pageVue(viewer));

    /** Toutes les cases de la feuille : ce que son retrait ou son remplacement emporte. */
    const tousReperes = viewer => Array.from(viewer.querySelectorAll('.plan-coulee'));
    const borner = (valeur, mini, maxi) => Math.min(maxi, Math.max(mini, valeur));

    function message(viewer, texte, erreur) {
        const zone = viewer.querySelector('.plan-viewer-message');
        if (!zone) return;
        zone.textContent = texte;
        zone.classList.toggle('is-error', !!erreur);
    }

    // Seul le numéro suit l'échelle du plan : il doit remplir sa case comme s'il y avait
    // été écrit. Les poignées gardent une taille fixe, sinon elles deviennent invisibles.
    function ajusterPolice(repere, facteur) {
        const hauteur = repere.getBoundingClientRect().height;
        if (hauteur <= 0) return;
        // À la taille des lettres imprimées. Le chiffre dépasse un peu la case en hauteur :
        // elle est posée en retrait des traits, lui s'étend jusqu'à eux sans les toucher.
        //
        // Aucun plancher : le numéro est du dessin, pas de l'interface. Un minimum en
        // pixels le faisait grossir par rapport au plan dès qu'on dézoomait — il devenait
        // alors plus gros que les lettres voisines, et ne montrait plus ce qui sortirait
        // à l'impression. Trop petit pour se lire, c'est au zoom de le régler.
        const taille = hauteur * (facteur || POLICE_PLAN);
        repere.style.fontSize = taille.toFixed(2) + 'px';

        // La hauteur ne suffit pas quand la case est large d'un seul chiffre et qu'on y
        // écrit un nom : « ROUSSEAU Nathan » dans la colonne du soudeur sortirait de la
        // cellule, et le bord la couperait net. On mesure donc ce que le texte occupe
        // vraiment et on resserre d'autant — le texte entier vaut mieux qu'un texte
        // grand mais tronqué.
        //
        // La mesure se prend sur l'élément qui porte le texte, jamais sur la case : celle-ci
        // centre son contenu, et ce qui déborde à gauche d'un contenu centré n'entre pas
        // dans scrollWidth. On sous-estimerait le débordement de moitié.
        const texte = repere.querySelector('.plan-coulee-valeur, .plan-soudure-valeur') || repere;
        const utile = texte.clientWidth;
        if (utile > 0 && texte.scrollWidth > utile) {
            // Pas de plancher ici non plus : il ferait déborder sur la colonne voisine
            // le texte même qu'on cherche à y faire tenir.
            repere.style.fontSize = (taille * utile / texte.scrollWidth).toFixed(2) + 'px';
        }
    }

    // ---------------------------------------------------------------- sommaire

    /**
     * Ne garde au sommaire que les feuilles dont le numéro contient ce qu'on tape.
     *
     * Le numéro de ligne est ce que l'atelier lit sur la feuille qu'il a en main —
     * « YU-PRE-108A-1 » — et un cahier en porte parfois trente. On accepte aussi « page 7 »
     * pour qui raisonne encore en rangs. L'espace et le tiret sont ignorés : on tape
     * rarement « 120-3 » comme il est écrit.
     */
    function filtrerSommaire(nav, saisie) {
        const normaliser = texte => texte.toLowerCase().replace(/[\s-]+/g, '');
        const cherche = normaliser(saisie);
        let visibles = 0;

        nav.querySelectorAll('.plan-dialog-sommaire-corps > li').forEach(item => {
            const trouve = cherche === '' || normaliser(item.dataset.recherche || '').includes(cherche);
            item.hidden = !trouve;
            if (trouve) visibles++;
        });

        const vide = nav.querySelector('.plan-sommaire-vide');
        if (vide) vide.hidden = visibles > 0;
    }

    $(document).on('input', '.plan-sommaire-recherche', function () {
        const nav = this.closest('.plan-dialog-sommaire');
        if (nav) filtrerSommaire(nav, this.value);
    });

    // Échap vide le champ plutôt que de fermer la fenêtre : on cherche une feuille, on se
    // trompe de numéro, on veut revoir la liste entière sans perdre le plan ouvert.
    $(document).on('keydown', '.plan-sommaire-recherche', function (evenement) {
        if (evenement.key !== 'Escape' || this.value === '') return;
        evenement.stopPropagation();
        evenement.preventDefault();
        this.value = '';
        const nav = this.closest('.plan-dialog-sommaire');
        if (nav) filtrerSommaire(nav, '');
    });

    // ---------------------------------------------------------------- zoom

    function appliquerZoom(viewer, pourcent, ancre) {
        const scene = viewer.querySelector('.plan-viewer-scene');
        const corps = viewer.querySelector('.plan-dialog-corps');
        const image = scene && scene.querySelector('img');
        if (!scene || !corps || !image || !image.naturalWidth) return;

        pourcent = Math.round(borner(pourcent, ZOOM_MIN, ZOOM_MAX));
        viewer.dataset.zoom = pourcent;

        // 100 % montre le plan ENTIER : le tableau à remplir est en bas de la feuille,
        // caler sur la largeur le mettrait hors écran.
        //
        // La place disponible est celle du corps, moins ce qu'il faut à ses marges — rien
        // de plus. L'ancienne réserve de 32 px rognait le plan sans raison : elle venait
        // d'un temps où le corps avait un padding d'un rem, et personne ne l'avait revue.
        const style = getComputedStyle(corps);
        const marge = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
        const base = Math.min(
            corps.clientWidth - marge,
            (corps.clientHeight - marge) * image.naturalWidth / image.naturalHeight
        );
        const avant = scene.getBoundingClientRect();
        const nouvelle = Math.round(base * pourcent / 100);
        scene.style.width = nouvelle + 'px';

        if (ancre && avant.width > 0) {
            const fx = (ancre.x - avant.left) / avant.width;
            const fy = (ancre.y - avant.top) / avant.height;
            corps.scrollLeft += fx * (nouvelle - avant.width);
            corps.scrollTop += fy * (scene.getBoundingClientRect().height - avant.height);
        }
        // L'épaisseur des traits qu'on ajoute au tableau suit celle des traits imprimés :
        // un pixel de l'image rendue, quel que soit le zoom. Un trait d'épaisseur fixe
        // paraîtrait gras sur un plan dézoomé et filiforme sur un plan agrandi.
        scene.style.setProperty('--plan-trait', Math.max(
            1, scene.getBoundingClientRect().height / image.naturalHeight).toFixed(2) + 'px');

        viewer.querySelector('.plan-zoom-valeur').textContent = pourcent + ' %';
        // Cases de coulée et valeurs de soudure portent une police en pixels : elle se
        // recalcule à chaque zoom, sinon le texte garde la taille du zoom précédent et
        // devient minuscule en grossissant le plan.
        //
        // La fonction est enveloppée, et non passée nue : forEach donne l'index en second
        // argument, qu'ajusterPolice prenait pour son facteur de police. La septième case
        // s'écrivait donc sept fois trop grande — le tiret imprimé devenait une barre
        // noire — et seul un clic dessus la remettait d'aplomb, puisqu'il rappelle
        // ajusterPolice sans ce second argument.
        scene.querySelectorAll('.plan-coulee, .plan-soudure').forEach(element => ajusterPolice(element));
    }

    // Ctrl + molette zoome le plan, pas la page. Écouteur non passif : un gestionnaire
    // passif ne peut pas annuler le zoom du navigateur.
    document.addEventListener('wheel', function (evenement) {
        const corps = evenement.target.closest && evenement.target.closest('.plan-dialog-corps');
        if (!corps || !evenement.ctrlKey) return;
        evenement.preventDefault();
        const viewer = corps.closest('.plan-viewer');
        const actuel = Number(viewer.dataset.zoom) || 100;
        appliquerZoom(viewer, actuel * (evenement.deltaY < 0 ? 1.12 : 1 / 1.12),
            { x: evenement.clientX, y: evenement.clientY });
    }, { passive: false });

    $(document).on('click', '.plan-zoom', function () {
        const viewer = this.closest('.plan-viewer');
        const actuel = Number(viewer.dataset.zoom) || 100;
        const suivant = Number(this.dataset.zoom) > 0
            ? ZOOMS.find(z => z > actuel) ?? ZOOMS[ZOOMS.length - 1]
            : [...ZOOMS].reverse().find(z => z < actuel) ?? ZOOMS[0];
        appliquerZoom(viewer, suivant);
    });

    // ---------------------------------------------------------------- la fenêtre

    $(document).on('click', '.plan-viewer-ouvrir', function () {
        const viewer = this.closest('.plan-viewer');
        const image = viewer.querySelector('.plan-viewer-scene img');
        // L'image n'est demandée qu'ici : inutile de charger un plan qu'on n'ouvre pas.
        if (image && !image.src && image.dataset.src) image.src = image.dataset.src;
        // Les vignettes du sommaire suivent la même règle, et ne partent qu'une fois.
        viewer.querySelectorAll('.plan-sommaire-page img').forEach(vignette => {
            if (!vignette.src && vignette.dataset.src) vignette.src = vignette.dataset.src;
        });
        viewer.querySelector('.plan-dialog').showModal();
        const caler = () => {
            appliquerZoom(viewer, Number(viewer.dataset.zoom) || 100);
            // À chaque ouverture, le plan va relire le tableau soudure de la page
            // affichée : la liste change sans lui. En revanche les cases de coulée ne se
            // posent qu'une fois, et seulement sur la première page — un plan déjà
            // travaillé les a, et les reposer en ferait des doublons par-dessus celles
            // du chef.
            if (viewer.dataset.csrf) detecter(viewer, true, pageVue(viewer) === 1 && reperes(viewer).length === 0);
        };
        if (image && image.complete) caler();
        else if (image) image.addEventListener('load', caler, { once: true });
    });

    /**
     * Lit les deux tableaux du plan : la nomenclature, où se posent les cases de coulée,
     * et la liste soudure, qu'on remplit avec ce qui est déjà saisi.
     *
     * $automatique vaut vrai à l'ouverture : l'échec est alors silencieux, car tous les
     * plans ne sont pas des fichiers de CAO et un scan n'a rien de reconnaissable.
     * $poserCases dit s'il faut placer les cases de coulée ; les soudures, elles, se
     * réaffichent toujours, puisqu'elles ne font que montrer l'état de la base.
     */
    function detecter(viewer, automatique, poserCases) {
        const scene = viewer.querySelector('.plan-viewer-scene');
        if (!scene || !scene.dataset.posable) return;
        if (!automatique) message(viewer, 'Lecture du tableau…', false);

        const donnees = new URLSearchParams();
        donnees.set('csrf', viewer.dataset.csrf || '');
        donnees.set('plan', viewer.dataset.plan);
        // La feuille ISO et sa page : un élément porte plusieurs feuilles, un cahier
        // plusieurs pages, et chaque page a ses propres tableaux imprimés.
        donnees.set('document', viewer.dataset.document || '');
        donnees.set('page', pageVue(viewer));

        fetch('actions/detecter_lignes.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                if (!ok) {
                    if (!automatique) message(viewer, corps.message || 'Détection impossible.', true);
                    return;
                }
                if ((!corps.cases || !corps.cases.length) && !corps.soudures) {
                    if (!automatique) message(viewer, corps.message || 'Détection impossible.', true);
                    return;
                }
                // La détection complète, elle ne remplace pas : les cases déjà posées
                // restent, et on n'écrase jamais un placement fait à la main.
                //
                // Compléter veut dire combler les trous, pas réécrire par-dessus : une
                // cellule qui porte déjà une case est laissée tranquille. Sans ce tri,
                // relancer la détection empilait un deuxième jeu de cases exactement sur
                // le premier — invisible à l'écran, mais bien enregistré en double.
                const occupee = c => reperes(viewer).some(repere => {
                    const centreX = (parseFloat(repere.style.left) + parseFloat(repere.style.width) / 2) / 100;
                    const centreY = (parseFloat(repere.style.top) + parseFloat(repere.style.height) / 2) / 100;
                    return centreX > c.x && centreX < c.x + c.w
                        && centreY > c.y && centreY < c.y + c.h;
                });

                // La géométrie du tableau de cette page resservira à chaque frappe dans
                // la liste : on la garde sur la fenêtre plutôt que de la redemander.
                viewer.tableauxSoudures = viewer.tableauxSoudures || {};
                viewer.tableauxSoudures[pageVue(viewer)] = corps.soudures;

                const proposees = poserCases ? corps.cases || [] : [];
                const posees = proposees.filter(c => !occupee(c));
                posees.forEach(c => {
                    const repere = nouvelleCase(scene);
                    repere.style.left = (c.x * 100).toFixed(4) + '%';
                    repere.style.top = (c.y * 100).toFixed(4) + '%';
                    repere.style.width = (c.w * 100).toFixed(4) + '%';
                    repere.style.height = (c.h * 100).toFixed(4) + '%';
                    ajusterPolice(repere);
                });
                remplirSoudures(scene, corps.soudures, corps.valeurs || []);
                completerListe(viewer, corps.soudures);

                // On ne raconte pas ce que le plan montre déjà. Seul ce qui ne s'y voit
                // pas mérite un mot : une soudure sans ligne où s'écrire, un tableau non
                // reconnu. Le nombre de pages, lui, se lit dans la barre — le répéter en
                // message ne faisait qu'encombrer.
                const souci = corps.avertissement || corps.message || '';
                if (souci) message(viewer, souci, true);
            })
            .catch(() => { if (!automatique) message(viewer, 'Détection impossible. Réessayez.', true); });
    }

    // Sur la page Pointage, la feuille se choisit dans une liste et s'ouvre d'un bouton :
    // le lecteur correspondant vit dans la ligne, masqué, et c'est sa fenêtre qu'on ouvre.
    $(document).on('click', '.pointage-iso-ouvrir', function () {
        const cellule = this.closest('.pointage-iso-cell');
        const ligne = this.closest('tr');
        const choisi = cellule && cellule.querySelector('.pointage-iso');
        if (!choisi || !ligne) return;
        // Le choix dit la feuille ET la page : la fenêtre s'ouvre donc sur l'isométrique
        // qu'on vient de désigner, pas sur la première page de son cahier.
        const [feuille, page] = String(choisi.value).split(':');
        const lecteur = ligne.querySelector('.pointage-iso-lecteurs .plan-viewer[data-document="' + feuille + '"]');
        if (!lecteur) return;
        lecteur.querySelector('.plan-viewer-ouvrir').click();
        allerPage(lecteur, Number(page) || 1);
    });

    $(document).on('click', '.plan-dialog-fermer', function () {
        this.closest('dialog').close();
    });

    // ---------------------------------------------------------------- l'aperçu suit la saisie

    /**
     * Ce que la liste d'une page écrirait sur le plan, lu dans le tableau tel qu'il est à
     * l'écran — et non tel qu'il est en base. Même rangement que le serveur
     * (planLignesSoudures) : la soudure n° 3 va sur la troisième ligne du tableau
     * imprimé, et les numéros au-delà descendent sous le tableau.
     */
    function valeursSaisies(bloc, tableau) {
        const imprimees = tableau.lignes.length;
        const capacite = imprimees + (tableau.libres || 0);
        const valeurs = {};

        bloc.querySelectorAll('tbody tr.tableau-ligne').forEach(tr => {
            const champ = nom => tr.querySelector('[name="' + nom + '"]');
            const lu = nom => {
                const element = champ(nom);
                if (!element) return '';
                // Un contrôle est une case à cocher : cochée, elle vaut sa croix.
                return element.type === 'checkbox'
                    ? (element.checked ? element.value : '')
                    : element.value.trim();
            };
            const numero = lu('numero');
            if (!/^\d+$/.test(numero)) return;
            const rang = Number(numero) - 1;
            if (rang < 0 || rang >= capacite) return;

            const soudeur = champ('soudeur');
            // Le soudeur est soit à choisir, soit déjà nommé par le pointage.
            const nomme = tr.querySelector('.soudure-soudeur');
            const date = lu('date');
            const visa = tr.querySelector('.soudure-visa');
            const vise = visa ? visa.textContent.trim() : '';
            valeurs[rang] = [
                // Le plan imprime lui-même les numéros de ses lignes : seuls ceux des
                // rangs ajoutés sous le tableau s'écrivent.
                rang < imprimees ? '' : numero,
                soudeur && soudeur.value ? soudeur.options[soudeur.selectedIndex].textContent.trim()
                    : (nomme ? nomme.textContent.trim().replace(/^—$/, '') : ''),
                // Le champ donne la date en aaaa-mm-jj, le plan la porte en jj/mm/aa.
                date ? date.slice(8, 10) + '/' + date.slice(5, 7) + '/' + date.slice(2, 4) : '',
                lu('type'), lu('rt'), lu('vi'), lu('pt'),
                vise === '—' ? '' : vise,
            ];
        });
        return valeurs;
    }

    /**
     * Le plan suit la saisie : ce qu'on tape dans la liste s'écrit aussitôt dessus, à sa
     * place sur le dessin. Sans cela il fallait enregistrer et rouvrir la feuille pour
     * voir où tombait un RT.
     */
    function redessinerSoudures(viewer) {
        const tableau = (viewer.tableauxSoudures || {})[pageVue(viewer)];
        const bloc = viewer.querySelector('.plan-dialog-liste:not([hidden]) .tableau-editable');
        const scene = viewer.querySelector('.plan-viewer-scene');
        if (!tableau || !bloc || !scene) return;
        remplirSoudures(scene, tableau, valeursSaisies(bloc, tableau));
    }

    $(document).on('input change', '.plan-dialog-liste .tableau-editable [name]', function () {
        redessinerSoudures(this.closest('.plan-viewer'));
    });

    // Ajouter ou retirer une ligne change aussi ce que le plan porte. Le redessin attend
    // que le gestionnaire du tableau ait fait son travail sur la ligne.
    //
    // On ne remonte pas depuis le bouton : retirer une ligne l'emporte avec elle, et il
    // n'est déjà plus dans la page quand on arrive ici. C'est la fenêtre ouverte qu'on
    // redessine — il n'y en a jamais deux.
    $(document).on('click', '.plan-dialog-liste .tableau-ajouter, .plan-dialog-liste .tableau-retirer', function () {
        const dialogue = document.querySelector('.plan-dialog[open]');
        const viewer = dialogue && dialogue.closest('.plan-viewer');
        if (viewer) setTimeout(() => redessinerSoudures(viewer), 0);
    });

    // ---------------------------------------------------------------- aller à une page

    /**
     * Double-clic sur le numéro : on tape la page voulue. Avec quinze isométriques,
     * atteindre la douzième à coups de flèche est une corvée.
     */
    $(document).on('dblclick', '.plan-page-valeur', function () {
        const valeur = this;
        const viewer = valeur.closest('.plan-viewer');
        const total = Math.max(1, Number(viewer.dataset.pages) || 1);
        if (total < 2 || valeur.querySelector('input')) return;

        const champ = document.createElement('input');
        champ.type = 'number';
        champ.className = 'plan-page-saisie';
        champ.min = 1;
        champ.max = total;
        champ.value = pageVue(viewer);
        champ.setAttribute('aria-label', 'Numéro de page');
        valeur.textContent = '';
        valeur.appendChild(champ);
        champ.focus();
        champ.select();

        let fini = false;
        const finir = aller => {
            if (fini) return;
            fini = true;
            const demandee = Number(champ.value);
            champ.remove();
            if (aller && demandee) allerPage(viewer, demandee);
            // allerPage réécrit le libellé quand la page change ; quand on retombe sur la
            // même, c'est à nous de le remettre.
            valeur.textContent = 'Page ' + pageVue(viewer) + ' / ' + total;
        };

        champ.addEventListener('keydown', evenement => {
            if (evenement.key === 'Enter') { evenement.preventDefault(); finir(true); }
            if (evenement.key === 'Escape') { evenement.preventDefault(); finir(false); }
        });
        champ.addEventListener('blur', () => finir(true));
    });

    // ---------------------------------------------------------------- pages du cahier

    /** La page regardée dans cette fenêtre. Une feuille d'une seule page vaut toujours 1. */
    function pageVue(viewer) {
        return Math.max(1, Number(viewer.dataset.page) || 1);
    }

    /**
     * Tourne la page du cahier : le dessin, sa liste soudure et les tableaux reconnus
     * dessus vont ensemble.
     *
     * Les cases de coulée, elles, ne valent que pour la première page : leurs positions y
     * ont été relevées. Ailleurs on les cache, on interdit d'en poser et l'enregistrement
     * est fermé — sinon il repartirait avec zéro case et effacerait le travail de la
     * première page.
     */
    function allerPage(viewer, voulue) {
        const total = Math.max(1, Number(viewer.dataset.pages) || 1);
        const demandee = borner(voulue, 1, total);
        if (demandee === pageVue(viewer)) return;
        viewer.dataset.page = demandee;

        const scene = viewer.querySelector('.plan-viewer-scene');
        const image = scene.querySelector('img');
        // Chaque page a sa nomenclature, donc ses cases : on montre les siennes et on
        // range les autres. Elles restent dans la page, prêtes à revenir.
        viewer.querySelectorAll('.plan-coulee').forEach(repere => {
            repere.hidden = Number(repere.dataset.page || 1) !== demandee;
        });

        viewer.querySelectorAll('.plan-dialog-liste').forEach(liste => {
            liste.hidden = Number(liste.dataset.page) !== demandee;
        });
        const valeur = viewer.querySelector('.plan-page-valeur');
        if (valeur) valeur.textContent = 'Page ' + demandee + ' / ' + total;
        marquerSommaire(viewer, demandee);

        message(viewer, '', false);
        image.addEventListener('load', () => {
            appliquerZoom(viewer, Number(viewer.dataset.zoom) || 100);
            // Une page jamais ouverte n'a pas encore ses cases : la lecture du tableau les
            // pose. Une page déjà travaillée garde les siennes — on ne les double pas.
            if (viewer.dataset.csrf) detecter(viewer, true, reperes(viewer).length === 0);
        }, { once: true });
        image.src = image.dataset.src + '&page=' + demandee;
    }

    $(document).on('click', '.plan-page', function () {
        const viewer = this.closest('.plan-viewer');
        allerPage(viewer, pageVue(viewer) + Number(this.dataset.saut));
    });

    /**
     * Marque la page affichée dans le sommaire et la ramène sous les yeux : un cahier de
     * quinze pages défile, et la vignette courante sortait du champ dès qu'on avançait
     * aux flèches.
     */
    function marquerSommaire(viewer, page) {
        viewer.querySelectorAll('.plan-sommaire-page').forEach(entree => {
            const sienne = Number(entree.dataset.page) === page;
            entree.classList.toggle('est-vue', sienne);
            if (!sienne) return entree.removeAttribute('aria-current');
            entree.setAttribute('aria-current', 'page');
            entree.scrollIntoView({ block: 'nearest' });
        });
    }

    $(document).on('click', '.plan-sommaire-page', function () {
        allerPage(this.closest('.plan-viewer'), Number(this.dataset.page));
    });

    // Le sommaire se range comme la liste des soudures : son propre bouton le replie, et
    // celui de la barre d'outils le rappelle.
    $(document).on('click', '.plan-dialog-sommaire-replier', function () {
        const dialogue = this.closest('.plan-dialog');
        dialogue.querySelector('.plan-dialog-sommaire').hidden = true;
        dialogue.querySelector('.plan-dialog-sommaire-ouvrir').hidden = false;
    });

    $(document).on('click', '.plan-dialog-sommaire-ouvrir', function () {
        this.closest('.plan-dialog').querySelector('.plan-dialog-sommaire').hidden = false;
        this.hidden = true;
    });

    // ---------------------------------------------------------------- dépôt d'un plan

    // Le dépôt vit hors des blocs de feuille : il en crée un. Rien à mettre à jour sur
    // place, donc — on recharge le volet, qui redessine la liste des ISO.
    $(document).on('change', '.plan-viewer-deposer input[type="file"]', function () {
        const champ = this;
        const zone = champ.closest('.plan-viewer-deposer');
        const section = champ.closest('.plan-viewer-plans');
        const fichier = champ.files && champ.files[0];
        if (!fichier) return;

        const donnees = new FormData();
        donnees.append('csrf', zone.dataset.csrf || '');
        donnees.append('plan', zone.dataset.plan);
        donnees.append('fichier', fichier);
        champ.value = '';
        annoncer(section, 'Dépôt du plan…', false);

        fetch('actions/upload_plan.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                annoncer(section, corps.message || (ok ? 'Plan déposé.' : 'Dépôt impossible.'), !ok);
                if (ok) rechargerVolet();
            })
            .catch(() => annoncer(section, 'Dépôt impossible. Réessayez.', true));
    });

    // Remplacer une feuille : même point d'entrée que le dépôt, à qui l'on dit cette fois
    // quelle feuille céder la place. Elle emporte ce qui a été posé dessus, donc on le dit
    // avant d'envoyer quoi que ce soit.
    $(document).on('change', '.plan-viewer-remplacer input[type="file"]', function () {
        const champ = this;
        const viewer = champ.closest('.plan-viewer');
        const section = champ.closest('.plan-viewer-plans');
        const fichier = champ.files && champ.files[0];
        champ.value = '';
        if (!fichier) return;

        const nom = viewer.querySelector('.plan-viewer-nom').textContent.trim();
        const posees = tousReperes(viewer).length;
        const pluriel = posees > 1 ? 's' : '';
        if (!window.confirm('Remplacer ' + nom + ' par ' + fichier.name + ' ?'
            + (posees ? '\n\nLes ' + posees + ' case' + pluriel + ' de coulée posée' + pluriel
                + ' sur cette feuille et sa liste de soudures seront effacées.' : ''))) return;

        const donnees = new FormData();
        donnees.append('csrf', viewer.dataset.csrf || '');
        donnees.append('plan', viewer.dataset.plan);
        donnees.append('document', viewer.dataset.document);
        donnees.append('fichier', fichier);
        annoncer(section, 'Remplacement de la feuille…', false);

        fetch('actions/upload_plan.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                annoncer(section, corps.message || (ok ? 'Feuille remplacée.' : 'Remplacement impossible.'), !ok);
                if (ok) rechargerVolet();
            })
            .catch(() => annoncer(section, 'Remplacement impossible. Réessayez.', true));
    });

    // Retirer une feuille : ce qui a été relevé dessus s'en va avec elle, donc on le dit
    // avant d'envoyer quoi que ce soit. La liste des soudures n'est pas comptée ici — elle
    // vit dans le volet, pas dans la fenêtre — mais la réponse du serveur la nomme.
    $(document).on('click', '.plan-viewer-supprimer', function () {
        const bouton = this;
        const viewer = bouton.closest('.plan-viewer');
        const section = bouton.closest('.plan-viewer-plans');
        if (bouton.disabled) return;

        const nom = viewer.querySelector('.plan-viewer-nom').textContent.trim();
        const posees = tousReperes(viewer).length;
        const pluriel = posees > 1 ? 's' : '';
        if (!window.confirm('Retirer la feuille ' + nom + ' de ce plan ?'
            + (posees ? '\n\nLes ' + posees + ' case' + pluriel + ' de coulée posée' + pluriel
                + ' dessus et sa liste de soudures seront supprimées.'
                : '\n\nSa liste de soudures sera supprimée.')
            + '\nLe PDF quitte aussi les fichiers de la demande s’il ne sert à aucun autre plan.')) return;

        bouton.disabled = true;
        const donnees = new URLSearchParams();
        donnees.set('csrf', viewer.dataset.csrf || '');
        donnees.set('plan', viewer.dataset.plan);
        donnees.set('document', viewer.dataset.document);
        annoncer(section, 'Retrait de la feuille…', false);

        fetch('actions/delete_plan.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                annoncer(section, corps.message || (ok ? 'Feuille retirée.' : 'Retrait impossible.'), !ok);
                if (ok) rechargerVolet();
                else bouton.disabled = false;
            })
            .catch(() => {
                annoncer(section, 'Retrait impossible. Réessayez.', true);
                bouton.disabled = false;
            });
    });

    // ---------------------------------------------------------------- sélection de feuilles

    /** Les feuilles cochées d'une section, dans l'ordre de la liste. */
    function choisies(section) {
        return Array.from(section.querySelectorAll('.plan-viewer-choix:checked'))
            .map(case_ => case_.closest('.plan-viewer'));
    }

    /** La barre ne montre son bouton qu'à partir d'une feuille cochée. */
    function majSelection(section) {
        const barre = section.querySelector('.plan-viewer-selection');
        if (!barre) return;
        const lot = choisies(section);
        const total = section.querySelectorAll('.plan-viewer-choix').length;
        const compte = barre.querySelector('.plan-viewer-selection-compte');
        const bouton = barre.querySelector('.plan-viewer-supprimer-lot');
        compte.textContent = lot.length
            ? lot.length + (lot.length > 1 ? ' feuilles sélectionnées' : ' feuille sélectionnée')
            : '';
        bouton.hidden = lot.length === 0;
        const tout = barre.querySelector('.plan-viewer-tout');
        tout.checked = lot.length > 0 && lot.length === total;
        tout.indeterminate = lot.length > 0 && lot.length < total;
    }

    $(document).on('change', '.plan-viewer-choix', function () {
        majSelection(this.closest('.plan-viewer-plans'));
    });

    $(document).on('change', '.plan-viewer-tout', function () {
        const section = this.closest('.plan-viewer-plans');
        section.querySelectorAll('.plan-viewer-choix').forEach(case_ => { case_.checked = this.checked; });
        majSelection(section);
    });

    $(document).on('click', '.plan-viewer-supprimer-lot', function () {
        const bouton = this;
        const barre = bouton.closest('.plan-viewer-selection');
        const section = bouton.closest('.plan-viewer-plans');
        if (bouton.disabled) return;

        const lot = choisies(section);
        if (!lot.length) return;
        // Les cases de coulée vivent dans la fenêtre de chaque feuille, ouverte ou non :
        // on peut donc dire ce que le lot emporte avant de l'envoyer.
        const posees = lot.reduce((total, viewer) => total + tousReperes(viewer).length, 0);
        if (!window.confirm('Retirer ' + lot.length + ' feuille' + (lot.length > 1 ? 's' : '')
            + ' de ce plan ?'
            + (posees ? '\n\nLes ' + posees + ' case' + (posees > 1 ? 's' : '') + ' de coulée posée'
                + (posees > 1 ? 's' : '') + ' dessus et leurs listes de soudures seront supprimées.'
                : '\n\nLeurs listes de soudures seront supprimées.')
            + '\nLes PDF quittent aussi les fichiers de la demande s’ils ne servent à aucun autre plan.')) return;

        bouton.disabled = true;
        const donnees = new URLSearchParams();
        donnees.set('csrf', barre.dataset.csrf || '');
        donnees.set('plan', barre.dataset.plan);
        lot.forEach(viewer => donnees.append('documents[]', viewer.dataset.document));
        annoncer(section, 'Retrait des feuilles…', false);

        fetch('actions/delete_plan.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                annoncer(section, corps.message || (ok ? 'Feuilles retirées.' : 'Retrait impossible.'), !ok);
                if (ok) rechargerVolet();
                else bouton.disabled = false;
            })
            .catch(() => {
                annoncer(section, 'Retrait impossible. Réessayez.', true);
                bouton.disabled = false;
            });
    });

    /** Un mot sous le bouton de dépôt : il n'appartient à aucune feuille. */
    function annoncer(section, texte, erreur) {
        if (!section) return;
        let zone = section.querySelector('.plan-viewer-depot-message');
        if (!zone) {
            zone = document.createElement('p');
            zone.className = 'plan-viewer-depot-message';
            zone.setAttribute('role', 'status');
            zone.setAttribute('aria-live', 'polite');
            section.querySelector('.plan-viewer-entete').after(zone);
        }
        zone.textContent = texte;
        zone.classList.toggle('is-error', !!erreur);
    }

    /** Le volet se redessine depuis le serveur : c'est lui qui connaît la liste des ISO. */
    function rechargerVolet() {
        const page = document.getElementById('pointage-page') ? 'pages/pointage.php'
            : (document.querySelector('.atelier-plan-list') ? 'pages/plans_iso.php' : null);
        if (page) $('#content').load(page);
    }

    // ---------------------------------------------------------------- les cases

    function selectionner(repere) {
        const scene = repere.closest('.plan-viewer-scene');
        scene.querySelectorAll('.plan-coulee.est-choisie')
            .forEach(autre => { if (autre !== repere) quitterEdition(autre); autre.classList.remove('est-choisie'); });
        repere.classList.add('est-choisie');
    }

    function deselectionner(scene) {
        scene.querySelectorAll('.plan-coulee.est-choisie').forEach(repere => {
            quitterEdition(repere);
            repere.classList.remove('est-choisie');
        });
    }

    // ---------------------------------------------------------------- le numéro de coulée

    /** Les coulées des certificats, posées par le serveur sur le lecteur. */
    function couleesProposees(viewer) {
        try {
            const liste = JSON.parse(viewer.dataset.coulees || '[]');
            return Array.isArray(liste) ? liste : [];
        } catch (erreur) {
            return [];
        }
    }

    /**
     * Le choix du numéro est une action à part : il n'apparaît qu'au double-clic.
     *
     * Un seul champ, avec la liste des coulées dessous : on choisit dedans, ou on tape un
     * numéro qui n'y est pas — et on peut passer de l'un à l'autre à tout moment. À
     * l'ouverture la liste montre tout, même quand la case porte déjà un numéro : la
     * filtrer sur lui ne laisserait que lui, et on ne pourrait plus changer d'avis. Elle
     * ne se resserre qu'une fois qu'on tape.
     */
    function entrerEnEdition(repere) {
        if (repere.classList.contains('est-en-edition')) return;
        repere.classList.add('est-en-edition');
        const champ = repere.querySelector('input[name="coulee"]');
        // Ce qu'Échap rendra : la case telle qu'elle était avant le double-clic.
        champ.dataset.avant = champ.value;
        champ.hidden = false;
        champ.focus();
        champ.select();
        afficherChoix(repere, '');
    }

    function quitterEdition(repere) {
        repere.classList.remove('est-en-edition');
        const champ = repere.querySelector('input[name="coulee"]');
        // Masqué au repos, toujours. Laissé visible, son fond blanc recouvrait la case :
        // le plan montrait une zone de saisie là où il doit montrer un numéro. Sa valeur
        // part quand même à l'enregistrement, qui lit le champ et non ce qui s'affiche.
        champ.hidden = true;
        champ.value = champ.value.trim();
        champ.setAttribute('aria-expanded', 'false');
        champ.removeAttribute('aria-activedescendant');
        const choix = repere.querySelector('.plan-coulee-choix');
        if (choix) choix.remove();
        repere.querySelector('.plan-coulee-valeur').textContent = champ.value || '—';
        // Un numéro plus long que le précédent doit encore tenir dans la case.
        ajusterPolice(repere);
    }

    /** Garnit la liste sous le champ : toutes les coulées, ou celles qui contiennent le filtre. */
    function afficherChoix(repere, filtre) {
        const champ = repere.querySelector('input[name="coulee"]');
        let choix = repere.querySelector('.plan-coulee-choix');
        if (!choix) {
            choix = document.createElement('ul');
            choix.className = 'plan-coulee-choix';
            choix.setAttribute('role', 'listbox');
            choix.id = 'plan-choix-' + Math.random().toString(36).slice(2, 10);
            champ.setAttribute('aria-controls', choix.id);
            repere.appendChild(choix);
        }
        choix.innerHTML = '';

        const toutes = couleesProposees(repere.closest('.plan-viewer'));
        const cherche = filtre.trim().toLowerCase();
        const retenues = cherche === ''
            ? toutes
            : toutes.filter(coulee => String(coulee.numero).toLowerCase().includes(cherche));

        retenues.forEach((coulee, rang) => {
            const option = document.createElement('li');
            option.setAttribute('role', 'option');
            option.id = choix.id + '-' + rang;
            option.dataset.numero = coulee.numero;
            option.appendChild(document.createTextNode(coulee.numero));
            if (!coulee.confirmee) {
                const note = document.createElement('span');
                note.className = 'plan-coulee-choix-note';
                note.textContent = 'lue une seule fois';
                option.appendChild(note);
            }
            choix.appendChild(option);
        });

        // Une liste vide n'est pas un silence : on dit pourquoi, et ce que fera Entrée.
        if (!retenues.length) {
            const vide = document.createElement('li');
            vide.className = 'plan-coulee-choix-vide';
            vide.textContent = toutes.length
                ? 'Aucune coulée ne correspond. Entrée garde « ' + filtre.trim() + ' ».'
                : 'Aucune coulée lue dans les certificats : tapez le numéro.';
            choix.appendChild(vide);
        }

        champ.setAttribute('aria-expanded', 'true');
        // La coulée que porte déjà la case est désignée d'emblée. En cours de frappe, rien
        // ne l'est tant qu'on ne descend pas dans la liste : Entrée garde alors ce qui est
        // tapé, mot pour mot. Désigner d'office la première coulée qui ressemble ferait
        // remplacer « 4681 » par « 468189 » sans qu'on l'ait demandé — une erreur de
        // traçabilité, pas un raccourci.
        const actuelle = Array.from(choix.querySelectorAll('li[role="option"]'))
            .find(option => option.dataset.numero === champ.value.trim());
        marquerActive(repere, actuelle || null);
    }

    function marquerActive(repere, option) {
        const champ = repere.querySelector('input[name="coulee"]');
        const choix = repere.querySelector('.plan-coulee-choix');
        if (!choix) return;
        choix.querySelectorAll('li.est-active').forEach(autre => autre.classList.remove('est-active'));
        if (!option) {
            champ.removeAttribute('aria-activedescendant');
            return;
        }
        option.classList.add('est-active');
        champ.setAttribute('aria-activedescendant', option.id);
        // On fait défiler la liste seule : scrollIntoView emmènerait aussi le plan.
        const haut = option.offsetTop;
        const bas = haut + option.offsetHeight;
        if (haut < choix.scrollTop) choix.scrollTop = haut;
        else if (bas > choix.scrollTop + choix.clientHeight) choix.scrollTop = bas - choix.clientHeight;
    }

    function choisir(repere, numero) {
        repere.querySelector('input[name="coulee"]').value = numero;
        quitterEdition(repere);
        // La main revient à la case : les flèches la déplacent, Suppr la retire.
        repere.focus();
    }

    $(document).on('dblclick', '.plan-coulee', function (evenement) {
        evenement.preventDefault();
        selectionner(this);
        entrerEnEdition(this);
    });

    $(document).on('input', '.plan-coulee input[name="coulee"]', function () {
        afficherChoix(this.closest('.plan-coulee'), this.value);
    });

    $(document).on('keydown', '.plan-coulee input[name="coulee"]', function (evenement) {
        const repere = this.closest('.plan-coulee');
        const options = Array.from(repere.querySelectorAll('.plan-coulee-choix li[role="option"]'));
        const active = repere.querySelector('.plan-coulee-choix li.est-active');
        const rang = options.indexOf(active);

        if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
            evenement.preventDefault();
            if (!options.length) return;
            const suivant = evenement.key === 'ArrowDown'
                ? (rang + 1) % options.length
                : (rang <= 0 ? options.length - 1 : rang - 1);
            marquerActive(repere, options[suivant]);
            return;
        }
        if (evenement.key === 'Enter') {
            evenement.preventDefault();
            // Une coulée désignée dans la liste l'emporte ; sinon, ce qui est tapé reste.
            choisir(repere, active ? active.dataset.numero : this.value);
            return;
        }
        if (evenement.key === 'Escape') {
            // Échap annule la saisie sans fermer la fenêtre du plan.
            evenement.preventDefault();
            choisir(repere, this.dataset.avant || '');
        }
    });

    // Le champ ne doit pas perdre la main quand on pointe la liste : la perdre vaut
    // validation de ce qui est tapé, et la liste se fermerait avant que le clic arrive.
    $(document).on('mousedown', '.plan-coulee-choix', function (evenement) {
        evenement.preventDefault();
    });

    $(document).on('click', '.plan-coulee-choix li[role="option"]', function () {
        choisir(this.closest('.plan-coulee'), this.dataset.numero);
    });

    // Cliquer ailleurs vaut validation de ce qui est tapé, comme dans un tableur.
    $(document).on('blur', '.plan-coulee input[name="coulee"]', function () {
        const repere = this.closest('.plan-coulee');
        if (repere && repere.classList.contains('est-en-edition')) quitterEdition(repere);
    });

    // La poignée du bandeau : elle partage la fenêtre entre le dessin et la liste. Même
    // état de geste que les cases — un seul à la fois, quel qu'il soit.
    document.addEventListener('mousedown', function (evenement) {
        const glissiere = evenement.target.closest('.plan-dialog-glissiere');
        if (!glissiere) return;
        const panneau = glissiere.closest('.plan-dialog-sommaire, .plan-dialog-listes');
        if (!panneau) return;
        const sommaire = panneau.classList.contains('plan-dialog-sommaire');
        const cadre = panneau.getBoundingClientRect();
        evenement.preventDefault();
        geste = {
            quoi: 'liste',
            cible: panneau,
            // À droite on tire en largeur, dessous en hauteur. L'orientation se lit sur la
            // mise en page plutôt que de redire ici le seuil de la feuille de style.
            colonne: getComputedStyle(panneau.parentElement).flexDirection === 'row',
            // Le sommaire borde le dessin à gauche : la main qui s'en éloigne l'élargit.
            // La liste des soudures le borde à droite, et c'est donc l'inverse.
            sens: sommaire ? 1 : -1,
            // Ce qu'il faut garder à chacun pour qu'il serve encore à quelque chose : une
            // vignette reconnaissable d'un côté, une ligne de soudure lisible de l'autre.
            mini: sommaire ? { largeur: 96, hauteur: 72 } : { largeur: 240, hauteur: 80 },
            maxi: sommaire ? { largeur: 0.4, hauteur: 0.5 } : { largeur: 0.7, hauteur: 0.8 },
            x: evenement.clientX,
            y: evenement.clientY,
            largeur: cadre.width,
            hauteur: cadre.height,
        };
    });

    // Replier rend toute la fenêtre au dessin ; le bouton de la barre d'outils la rappelle.
    $(document).on('click', '.plan-dialog-replier', function () {
        const dialogue = this.closest('.plan-dialog');
        dialogue.querySelector('.plan-dialog-listes').hidden = true;
        const rouvrir = dialogue.querySelector('.plan-dialog-liste-ouvrir');
        if (rouvrir) rouvrir.hidden = false;
    });

    $(document).on('click', '.plan-dialog-liste-ouvrir', function () {
        const dialogue = this.closest('.plan-dialog');
        dialogue.querySelector('.plan-dialog-listes').hidden = false;
        this.hidden = true;
    });

    // Glisser depuis n'importe où sur la case la déplace ; depuis une poignée, la
    // redimensionne. Le coin opposé sert alors de point fixe.
    document.addEventListener('mousedown', function (evenement) {
        const scene = evenement.target.closest('.plan-viewer-scene[data-posable]');
        if (!scene) return;
        const image = scene.querySelector('img');
        if (!image || !image.clientWidth) return;
        const cadre = image.getBoundingClientRect();

        // La liste des coulées appartient au champ : la pointer ne saisit pas la case.
        if (evenement.target.closest('.plan-coulee-choix')) return;

        const repere = evenement.target.closest('.plan-coulee');
        if (repere) {
            // En édition, le champ et sa liste doivent rester utilisables.
            if (repere.classList.contains('est-en-edition')) return;
            evenement.preventDefault();
            selectionner(repere);
            repere.focus();
            geste = {
                quoi: 'case',
                coin: (evenement.target.dataset || {}).coin || null,
                cible: repere,
                cadre: cadre,
                x: evenement.clientX,
                y: evenement.clientY,
                gauche: parseFloat(repere.style.left) || 0,
                haut: parseFloat(repere.style.top) || 0,
                largeur: parseFloat(repere.style.width) || 9,
                hauteur: parseFloat(repere.style.height) || 1.8,
            };
            return;
        }

        // Un clic hors d'une case ne fait que désélectionner.
        deselectionner(scene);
    });

    // Se déplacer dans le plan en l'attrapant, comme une carte. Les barres de défilement
    // restent, mais personne ne vise une barre pour suivre une ligne de tuyauterie.
    //
    // Ce geste-ci vaut sur toutes les feuilles, y compris celles qu'on ne fait que
    // consulter : regarder n'est pas modifier. Il s'enregistre APRÈS celui des cases, qui
    // a donc la main quand on saisit une case ou une poignée.
    document.addEventListener('mousedown', function (evenement) {
        if (evenement.button !== 0 || geste) return;
        const scene = evenement.target.closest('.plan-viewer-scene');
        if (!scene || evenement.target.closest('.plan-coulee, .plan-coulee-choix')) return;
        // C'est le corps de la fenêtre qui défile : c'est donc lui qu'on pousse.
        const corps = scene.closest('.plan-dialog-corps');
        if (!corps) return;

        evenement.preventDefault();
        corps.classList.add('est-attrape');
        geste = {
            quoi: 'vue',
            cible: corps,
            x: evenement.clientX,
            y: evenement.clientY,
            gauche: corps.scrollLeft,
            haut: corps.scrollTop,
        };
    });

    document.addEventListener('mousemove', function (evenement) {
        if (!geste) return;

        if (geste.quoi === 'vue') {
            // Le plan suit la main : on tire vers la gauche, on découvre la droite.
            geste.cible.scrollLeft = geste.gauche - (evenement.clientX - geste.x);
            geste.cible.scrollTop = geste.haut - (evenement.clientY - geste.y);
            return;
        }

        if (geste.quoi === 'liste') {
            // La souris s'éloigne du dessin, le panneau grandit. Jamais au point de le
            // chasser : on garde de quoi voir ce sur quoi on travaille.
            if (geste.colonne) {
                const largeur = borner(geste.largeur + geste.sens * (evenement.clientX - geste.x),
                    geste.mini.largeur, window.innerWidth * geste.maxi.largeur);
                geste.cible.style.width = largeur + 'px';
            } else {
                const hauteur = borner(geste.hauteur + geste.sens * (evenement.clientY - geste.y),
                    geste.mini.hauteur, window.innerHeight * geste.maxi.hauteur);
                geste.cible.style.height = hauteur + 'px';
            }
            return;
        }

        const dx = (evenement.clientX - geste.x) / geste.cadre.width * 100;
        const dy = (evenement.clientY - geste.y) / geste.cadre.height * 100;
        const style = geste.cible.style;

        if (!geste.coin) {
            style.left = borner(geste.gauche + dx, 0, 100 - geste.largeur).toFixed(4) + '%';
            style.top = borner(geste.haut + dy, 0, 100 - geste.hauteur).toFixed(4) + '%';
            return;
        }

        // Une poignée de bord ne bouge qu'un axe ; une poignée de coin, les deux.
        const horizontal = geste.coin.includes('e') || geste.coin.includes('w');
        const vertical = geste.coin.includes('n') || geste.coin.includes('s');
        const versLaDroite = geste.coin.includes('e');
        const versLeBas = geste.coin.includes('s');

        if (horizontal) {
            const largeur = Math.max(LARGEUR_MINI, versLaDroite ? geste.largeur + dx : geste.largeur - dx);
            style.width = borner(largeur, LARGEUR_MINI, 100).toFixed(4) + '%';
            if (!versLaDroite) style.left = borner(geste.gauche + geste.largeur - largeur, 0, 100).toFixed(4) + '%';
        }
        if (vertical) {
            const hauteur = Math.max(HAUTEUR_MINI, versLeBas ? geste.hauteur + dy : geste.hauteur - dy);
            style.height = borner(hauteur, HAUTEUR_MINI, 100).toFixed(4) + '%';
            if (!versLeBas) style.top = borner(geste.haut + geste.hauteur - hauteur, 0, 100).toFixed(4) + '%';
        }
        ajusterPolice(geste.cible);
    });

    document.addEventListener('mouseup', function () {
        if (geste && geste.quoi === 'vue') geste.cible.classList.remove('est-attrape');
        geste = null;
    });

    function nouvelleCase(scene) {
        const repere = scene.querySelector('.plan-viewer-modele').content.firstElementChild.cloneNode(true);
        // Elle naît sur la page qu'on regarde : c'est le dessin qu'on vise.
        repere.dataset.page = pageVue(scene.closest('.plan-viewer'));
        scene.appendChild(repere);
        ajusterPolice(repere);
        return repere;
    }

    /**
     * Autant de lignes dans la liste du volet que le plan en a imprimées.
     *
     * Le serveur ne peut pas le savoir : ce nombre vient de la lecture du dessin, qui
     * n'a lieu qu'à l'ouverture de la page. On complète donc ici, et jamais on ne retire —
     * une ligne déjà saisie reste, même si le tableau imprimé est plus court qu'elle.
     *
     * On remplit une liste en face d'un tableau : avoir cinq lignes sur la feuille et une
     * seule à l'écran oblige à cliquer « Ajouter » quatre fois pour rien.
     */
    function completerListe(viewer, tableau) {
        if (!tableau || !tableau.lignes) return;
        const bloc = viewer.querySelector('.plan-dialog-liste:not([hidden]) .tableau-editable');
        const moule = bloc && bloc.querySelector('template.soudure-modele');
        if (!moule) return;

        const corps = bloc.querySelector('tbody');
        const vide = corps.querySelector('.nomenclature-vide');
        if (vide) vide.closest('tr').remove();

        const numeros = () => Array.from(corps.querySelectorAll('tr.tableau-ligne [name="numero"]'));
        const pris = new Set(numeros().map(champ => champ.value.trim()).filter(valeur => valeur !== ''));

        // Le plan imprime ses numéros : la ligne 3 de son tableau EST la soudure 3. Les
        // lignes offertes portent donc le numéro qui leur revient, et celles déjà
        // numérotées gardent le leur — on ne redistribue rien.
        for (let numero = 1; numero <= tableau.lignes.length; numero++) {
            if (pris.has(String(numero))) continue;
            let champ = numeros().find(candidat => candidat.value.trim() === '');
            if (!champ) {
                corps.appendChild(moule.content.firstElementChild.cloneNode(true));
                champ = numeros().find(candidat => candidat.value.trim() === '');
            }
            if (!champ) return;
            champ.value = numero;
        }
    }

    /**
     * Reporte la liste des soudures dans le tableau du plan.
     *
     * Ces valeurs ne se modifient pas ici : elles se saisissent dans le tableau « Liste
     * des soudures » du volet, qui porte les contrôles et le visa. Le plan les montre,
     * il ne les édite pas — sans quoi la même donnée aurait deux endroits où changer.
     */
    function remplirSoudures(scene, tableau, valeurs) {
        scene.querySelectorAll('.plan-soudure, .plan-soudure-trait, .plan-soudure-bord')
            .forEach(element => element.remove());
        if (!tableau || !tableau.lignes || !tableau.colonnes) return;

        const imprimees = tableau.lignes.length;
        const rangs = Object.keys(valeurs).map(Number).filter(r => !isNaN(r));
        // Les rangs qui manquent au tableau imprimé, dans la limite de la place mesurée
        // comme libre sous lui : au-delà, on écrirait sur le dessin ou le cartouche.
        const ajoutees = rangs.length
            ? Math.min(Math.max(0, Math.max(...rangs) + 1 - imprimees), tableau.libres || 0)
            : 0;
        if (ajoutees > 0) tracerLignes(scene, tableau, ajoutees);

        // La géométrie d'un rang : celle relevée sur le plan pour les lignes imprimées,
        // celle du pas pour celles qu'on vient de tracer.
        const place = rang => rang < imprimees
            ? tableau.lignes[rang]
            : {
                y: tableau.bas + (rang - imprimees) * tableau.pas + tableau.pas * 0.18,
                h: tableau.pas * 0.64,
            };

        rangs.sort((a, b) => a - b).forEach(rang => {
            if (rang >= imprimees + ajoutees) return;
            const donnees = valeurs[rang];
            const ligne = place(rang);
            // Ce que la feuille porte déjà à cet endroit : l'écran montre ce que le plan
            // final écrira, donc il saute les mêmes cases.
            const ecrites = rang < imprimees ? (tableau.lignes[rang].cellules || []) : [];
            tableau.colonnes.forEach((colonne, index) => {
                const texte = (donnees[index] || '').trim();
                if (texte === '' || ecrites[index]) return;
                const cellule = document.createElement('div');
                cellule.className = 'plan-soudure';
                // Le texte vit dans son propre élément : c'est lui qu'on mesure pour
                // ajuster la police, et lui qui coupe si la mention reste trop longue.
                const valeur = document.createElement('span');
                valeur.className = 'plan-soudure-valeur';
                valeur.textContent = texte;
                cellule.appendChild(valeur);
                cellule.style.left = (colonne.x * 100).toFixed(4) + '%';
                cellule.style.top = (ligne.y * 100).toFixed(4) + '%';
                cellule.style.width = (colonne.w * 100).toFixed(4) + '%';
                cellule.style.height = (ligne.h * 100).toFixed(4) + '%';
                scene.appendChild(cellule);
                ajusterPolice(cellule);
            });
        });
    }

    /**
     * Prolonge le tableau du plan de quelques lignes.
     *
     * On reprend le pas et les bords relevés sur le tableau imprimé, pour que les rangs
     * ajoutés aient exactement la même allure que les siens : un tableau à deux mains se
     * verrait, et donnerait à douter du reste de la feuille.
     */
    function tracerLignes(scene, tableau, combien) {
        const bords = tableau.bords || [];
        if (bords.length < 2) return;
        const gauche = bords[0];
        const largeur = bords[bords.length - 1] - gauche;

        // Un trait par rang ajouté, posé sous lui — celui du haut existe déjà, c'est le
        // trait du bas du tableau imprimé.
        for (let rang = 1; rang <= combien; rang++) {
            const trait = document.createElement('div');
            trait.className = 'plan-soudure-trait';
            trait.style.left = (gauche * 100).toFixed(4) + '%';
            trait.style.width = (largeur * 100).toFixed(4) + '%';
            trait.style.top = ((tableau.bas + rang * tableau.pas) * 100).toFixed(4) + '%';
            scene.appendChild(trait);
        }

        // Les séparateurs de colonnes descendent d'un seul tenant sur toute la hauteur
        // ajoutée : les tracer rang par rang multiplierait les éléments pour rien.
        bords.forEach(x => {
            const bord = document.createElement('div');
            bord.className = 'plan-soudure-bord';
            bord.style.left = (x * 100).toFixed(4) + '%';
            bord.style.top = (tableau.bas * 100).toFixed(4) + '%';
            bord.style.height = (combien * tableau.pas * 100).toFixed(4) + '%';
            scene.appendChild(bord);
        });
    }

    // ---------------------------------------------------------------- clavier

    document.addEventListener('keydown', function (evenement) {
        const dialogue = document.querySelector('.plan-dialog[open]');
        if (!dialogue) return;
        const viewer = dialogue.closest('.plan-viewer');
        const scene = viewer.querySelector('.plan-viewer-scene');
        if (!scene.dataset.posable) return;

        // Pendant la saisie d'un numéro, le clavier appartient au champ. Une touche qu'il a
        // déjà traitée — Entrée ou Échap, qui lui rendent la main — ne doit pas servir une
        // seconde fois à la case : Échap la désélectionnerait dans la foulée.
        if (evenement.defaultPrevented) return;
        const actif = document.activeElement;
        if (actif && (actif.tagName === 'INPUT' && actif.type === 'text' && !actif.hidden)) return;

        const choisie = scene.querySelector('.plan-coulee.est-choisie');

        if (evenement.key === 'Escape' && choisie) {
            evenement.preventDefault();
            deselectionner(scene);
            return;
        }
        if ((evenement.key === 'Delete' || evenement.key === 'Backspace') && choisie) {
            evenement.preventDefault();
            choisie.remove();
            message(viewer, 'Case retirée.', false);
            return;
        }
        if (evenement.key.startsWith('Arrow') && choisie) {
            evenement.preventDefault();
            // Le pas fin place au pixel près ; Maj déplace d'un cran visible.
            const pas = evenement.shiftKey ? 1 : 0.1;
            const gauche = parseFloat(choisie.style.left) || 0;
            const haut = parseFloat(choisie.style.top) || 0;
            if (evenement.key === 'ArrowLeft') choisie.style.left = borner(gauche - pas, 0, 100).toFixed(4) + '%';
            if (evenement.key === 'ArrowRight') choisie.style.left = borner(gauche + pas, 0, 100).toFixed(4) + '%';
            if (evenement.key === 'ArrowUp') choisie.style.top = borner(haut - pas, 0, 100).toFixed(4) + '%';
            if (evenement.key === 'ArrowDown') choisie.style.top = borner(haut + pas, 0, 100).toFixed(4) + '%';
            return;
        }
        if (evenement.key === 'Enter' && choisie && !choisie.classList.contains('est-en-edition')) {
            evenement.preventDefault();
            entrerEnEdition(choisie);
            return;
        }

        if (!evenement.ctrlKey && !evenement.metaKey) return;
        const touche = evenement.key.toLowerCase();

        if (touche === 'c' && choisie) {
            evenement.preventDefault();
            presse = {
                gauche: parseFloat(choisie.style.left) || 0,
                haut: parseFloat(choisie.style.top) || 0,
                largeur: parseFloat(choisie.style.width) || 9,
                hauteur: parseFloat(choisie.style.height) || 1.8,
            };
            message(viewer, 'Case copiée. Ctrl+V la colle sous la précédente.', false);
            return;
        }
        if (touche === 'd' && choisie) {
            evenement.preventDefault();
            collerCase(scene, viewer, {
                gauche: parseFloat(choisie.style.left) || 0,
                haut: parseFloat(choisie.style.top) || 0,
                largeur: parseFloat(choisie.style.width) || 9,
                hauteur: parseFloat(choisie.style.height) || 1.8,
            });
            return;
        }
        if (touche === 'v') {
            if (!presse) {
                message(viewer, 'Rien à coller : copiez d’abord une case avec Ctrl+C.', true);
                return;
            }
            evenement.preventDefault();
            presse.haut = Math.min(100 - presse.hauteur, presse.haut + presse.hauteur);
            collerCase(scene, viewer, presse);
        }
    });

    /** Le numéro n'est jamais recopié : dupliquer une coulée en silence serait une
     *  erreur de traçabilité. Seule la géométrie se reproduit. */
    function collerCase(scene, viewer, source) {
        const copie = nouvelleCase(scene);
        copie.style.left = source.gauche.toFixed(4) + '%';
        copie.style.top = source.haut.toFixed(4) + '%';
        copie.style.width = source.largeur.toFixed(4) + '%';
        copie.style.height = source.hauteur.toFixed(4) + '%';
        ajusterPolice(copie);
        selectionner(copie);
        message(viewer, 'Case ajoutée. Double-cliquez pour choisir sa coulée.', false);
    }

    // ---------------------------------------------------------------- enregistrer

    /** Les empreintes, une par page : chacune dit l'état sur lequel sa page a été bâtie. */
    function empreintes(viewer) {
        try {
            return JSON.parse(viewer.dataset.empreintes || '{}') || {};
        } catch (erreur) {
            return {};
        }
    }

    $(document).on('click', '.plan-viewer-enregistrer', function () {
        const bouton = this;
        const viewer = bouton.closest('.plan-viewer');
        if (bouton.disabled) return;
        bouton.disabled = true;
        message(viewer, 'Enregistrement…', false);

        const page = pageVue(viewer);
        const connues = empreintes(viewer);
        const donnees = new URLSearchParams();
        donnees.set('csrf', viewer.dataset.csrf || '');
        donnees.set('plan', viewer.dataset.plan);
        donnees.set('document', viewer.dataset.document || '');
        donnees.set('page', page);
        donnees.set('empreinte', connues[page] || '');
        reperes(viewer).forEach((repere, index) => {
            donnees.set(`lignes[${index}][coulee]`, repere.querySelector('input[name="coulee"]').value.trim());
            donnees.set(`lignes[${index}][x]`, (parseFloat(repere.style.left) / 100).toFixed(6));
            donnees.set(`lignes[${index}][y]`, (parseFloat(repere.style.top) / 100).toFixed(6));
            donnees.set(`lignes[${index}][w]`, (parseFloat(repere.style.width) / 100).toFixed(6));
            donnees.set(`lignes[${index}][h]`, (parseFloat(repere.style.height) / 100).toFixed(6));
        });

        fetch('actions/save_nomenclature.php', { method: 'POST', body: donnees })
            .then(reponse => reponse.json().then(corps => ({ ok: reponse.ok, corps })))
            .then(({ ok, corps }) => {
                if (ok && corps.empreinte) {
                    connues[page] = corps.empreinte;
                    viewer.dataset.empreintes = JSON.stringify(connues);
                }
                message(viewer, corps.message || (ok ? 'Enregistré.' : 'Enregistrement impossible.'), !ok);
            })
            .catch(() => message(viewer, 'Enregistrement impossible. Réessayez.', true))
            .finally(() => { bouton.disabled = false; });
    });
})();
