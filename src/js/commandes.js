(() => {
    'use strict';

    // Le tableau des commandes du PV « Réception matériel », et sous chaque commande les
    // articles du bon. L'enregistrement part avec le formulaire du PV : rien ici n'écrit
    // en base, sauf la lecture du bon qui ne fait que proposer.
    //
    // Délégation sur le document : le volet des PV arrive par AJAX et se referme.

    const bloc = cible => cible.closest('[data-commandes]');

    /** Le message « aucune commande » ne s'affiche que quand il n'y en a vraiment aucune. */
    function compter(zone) {
        const lignes = zone.querySelectorAll('.commande-ligne').length;
        const vide = zone.querySelector('.commandes-vide');
        if (vide) vide.hidden = lignes > 0;
        zone.querySelector('.commandes-scroll').hidden = lignes === 0;
    }

    $(document).on('click', '.commandes-ajouter', function () {
        const zone = bloc(this);
        const gabarit = zone.querySelector('.commande-gabarit');
        // Le gabarit porte deux rangées — la commande et ses articles — et elles doivent
        // rester voisines : on clone le contenu entier, pas sa première ligne.
        const neuf = gabarit.content.cloneNode(true);
        const ligne = neuf.querySelector('.commande-ligne');
        zone.querySelector('.commandes-lignes').appendChild(neuf);
        compter(zone);
        // Le curseur va dans le numéro : c'est par là qu'on commence à recopier le bon.
        ligne.querySelector('input').focus();
    });

    $(document).on('click', '.commande-retirer', function () {
        const zone = bloc(this);
        const ligne = this.closest('.commande-ligne');
        const numero = ligne.querySelector('[name="commande[numero][]"]').value.trim();
        // On ne demande confirmation que pour une ligne déjà remplie : retirer une ligne
        // qu'on vient d'ajouter par erreur ne mérite pas une question.
        if (numero && !window.confirm('Retirer la commande « ' + numero + ' » ? Elle sera supprimée à l’enregistrement.')) return;
        // La rangée des articles suit la sienne : laissée seule, elle rattacherait ses
        // articles à la commande du dessus.
        const articles = ligne.nextElementSibling;
        if (articles && articles.classList.contains('commande-articles-rang')) articles.remove();
        ligne.remove();
        compter(zone);
    });

    // La seconde échéance se déplie à la demande : une commande arrive rarement en deux
    // fois, et le champ resterait vide sur toutes les lignes le reste du temps.
    $(document).on('click', '.commande-plier-date', function () {
        const cellule = this.closest('.commande-livraison');
        cellule.classList.add('est-depliee');
        cellule.querySelector('.commande-date-2e').focus();
    });

    // Une commande marquée reçue sans date de livraison laisse le suivi aveugle : on
    // propose celle du jour plutôt que de refuser l'enregistrement.
    $(document).on('change', '[name="commande[statut][]"]', function () {
        if (this.value !== 'complete') return;
        const ligne = this.closest('.commande-ligne');
        const date = ligne.querySelector('[name="commande[date_bl][]"]');
        if (date && !date.value) date.value = new Date().toISOString().slice(0, 10);
    });

    // ================================================================ LES ARTICLES DU BON

    const champArticles = rang => rang.querySelector('[name="commande[articles][]"]');

    /** Ce que porte le champ caché, toujours sous forme de tableau. */
    function articles(rang) {
        try {
            const lu = JSON.parse(champArticles(rang).value || '[]');
            return Array.isArray(lu) ? lu : [];
        } catch (erreur) {
            return [];
        }
    }

    /** Écrit la liste dans le champ caché : c'est lui, et lui seul, qui part au serveur. */
    function poser(rang, liste) {
        champArticles(rang).value = JSON.stringify(liste);
        rang.querySelector('.commande-articles-compte').textContent = liste.length;
    }

    const nombre = valeur => {
        const propre = String(valeur ?? '').replace(/\s/g, '').replace(',', '.');
        return propre === '' ? '' : propre;
    };

    /**
     * La quantité telle qu'on la lit sur un bon.
     *
     * La base rend « 4.000 » pour quatre brides : trois décimales sur chacune des quarante
     * lignes, c'est du bruit qui cache les quantités qui en ont vraiment, comme 2,5 m.
     */
    const quantiteLisible = valeur => {
        const texte = String(valeur ?? '');
        return /^\d+\.\d+$/.test(texte) ? texte.replace(/\.?0+$/, '') : texte;
    };

    /** Redessine le tableau des articles à partir de ce que porte le champ caché. */
    function rendre(rang) {
        const zone = rang.querySelector('.commande-articles');
        const liste = articles(rang);

        const lignes = liste.map((article, index) => `
            <tr class="commande-article" data-index="${index}">
                <td><input class="commande-article-champ" data-champ="reference_article" value="${attribut(article.reference_article)}" maxlength="80" aria-label="Référence"></td>
                <td><input class="commande-article-champ" data-champ="designation" value="${attribut(article.designation)}" maxlength="180" aria-label="Désignation"></td>
                <td><input class="commande-article-champ" data-champ="unite" value="${attribut(article.unite)}" maxlength="16" aria-label="Unité"></td>
                <td><input class="commande-article-champ commande-article-nombre" data-champ="quantite_attendue" value="${attribut(quantiteLisible(article.quantite_attendue))}" inputmode="decimal" aria-label="Quantité attendue"></td>
                <td><input class="commande-article-champ commande-article-nombre" data-champ="quantite_recue" value="${attribut(quantiteLisible(article.quantite_recue))}" inputmode="decimal" aria-label="Quantité reçue"></td>
                <td><button type="button" class="commande-article-retirer bouton-discret" aria-label="Retirer cet article" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button></td>
            </tr>`).join('');

        zone.innerHTML = `
            <div class="commande-articles-outils">
                <button type="button" class="commande-lire-bon bouton-discret">Lire le bon déposé</button>
                <button type="button" class="commande-article-ajouter bouton-discret">Ajouter un article</button>
                <span class="commande-articles-mot" role="status" aria-live="polite"></span>
            </div>
            ${liste.length ? `
            <div class="commande-articles-scroll">
                <table class="commande-articles-table">
                    <thead><tr>
                        <th scope="col">Référence</th><th scope="col">Désignation</th><th scope="col">Unité</th>
                        <th scope="col">Attendu</th><th scope="col">Reçu</th><th scope="col"><span class="sr-only">Retirer</span></th>
                    </tr></thead>
                    <tbody>${lignes}</tbody>
                </table>
            </div>` : '<p class="commande-articles-vide">Aucun article. Lisez le bon déposé, ou ajoutez-les à la main.</p>'}`;
    }

    /** Échappe ce qui part dans un attribut HTML : une désignation peut porter un guillemet. */
    function attribut(valeur) {
        return String(valeur ?? '')
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    $(document).on('click', '.commande-articles-bascule', function () {
        const rang = this.closest('.commande-articles-rang');
        const zone = rang.querySelector('.commande-articles');
        const ouvert = this.getAttribute('aria-expanded') === 'true';
        if (!ouvert) rendre(rang);
        zone.hidden = ouvert;
        this.setAttribute('aria-expanded', ouvert ? 'false' : 'true');
    });

    $(document).on('input', '.commande-article-champ', function () {
        const rang = this.closest('.commande-articles-rang');
        const index = Number(this.closest('.commande-article').dataset.index);
        const liste = articles(rang);
        if (!liste[index]) return;
        liste[index][this.dataset.champ] = this.classList.contains('commande-article-nombre')
            ? nombre(this.value)
            : this.value;
        // Sans redessiner : le curseur du champ en cours de frappe repartirait au début.
        champArticles(rang).value = JSON.stringify(liste);
    });

    $(document).on('click', '.commande-article-ajouter', function () {
        const rang = this.closest('.commande-articles-rang');
        const liste = articles(rang);
        liste.push({reference_article: '', designation: '', unite: 'u', quantite_attendue: '', quantite_recue: ''});
        poser(rang, liste);
        rendre(rang);
        const champs = rang.querySelectorAll('.commande-article');
        champs[champs.length - 1].querySelector('input').focus();
    });

    $(document).on('click', '.commande-article-retirer', function () {
        const rang = this.closest('.commande-articles-rang');
        const index = Number(this.closest('.commande-article').dataset.index);
        const liste = articles(rang);
        liste.splice(index, 1);
        poser(rang, liste);
        rendre(rang);
    });

    // ---------------------------------------------------------------- la lecture du bon

    /** Les PDF déposés sur ce PV, tels que la liste des documents les montre. */
    function documentsDuPv(rang) {
        const formulaire = rang.closest('.pv-form');
        if (!formulaire) return [];
        return [...formulaire.querySelectorAll('.pv-document[data-pdf="1"]')]
            .map(li => ({id: li.dataset.document, nom: li.dataset.nom}));
    }

    $(document).on('click', '.commande-lire-bon', function () {
        const rang = this.closest('.commande-articles-rang');
        const mot = rang.querySelector('.commande-articles-mot');
        const pdfs = documentsDuPv(rang);

        if (!pdfs.length) {
            mot.textContent = 'Déposez d’abord le bon en PDF, puis enregistrez le PV.';
            return;
        }
        // Plusieurs bons sur le même PV : on demande lequel plutôt que d'en choisir un.
        let choisi = pdfs[0];
        if (pdfs.length > 1) {
            const noms = pdfs.map((p, i) => (i + 1) + ' — ' + p.nom).join('\n');
            const reponse = window.prompt('Quel bon lire ?\n' + noms, '1');
            const index = Number(reponse) - 1;
            if (!(index >= 0 && index < pdfs.length)) return;
            choisi = pdfs[index];
        }

        const bouton = this;
        bouton.disabled = true;
        mot.textContent = 'Lecture de ' + choisi.nom + '…';

        const donnees = new FormData();
        donnees.set('csrf', rang.closest('.pv-form').elements.csrf.value);
        donnees.set('document', choisi.id);

        fetch('actions/lire_bon_commande.php', {method: 'POST', body: donnees})
            .then(reponse => reponse.json().then(corps => ({ok: reponse.ok, corps})))
            .then(({ok, corps}) => {
                if (!ok) { mot.textContent = corps.message || 'Lecture impossible.'; return; }
                if (corps.lignes.length) {
                    // La lecture remplace ce qui était là : on relit un bon pour corriger
                    // une première lecture, pas pour en empiler une seconde.
                    poser(rang, corps.lignes.map(l => ({
                        reference_article: l.reference,
                        designation: l.designation,
                        unite: l.unite,
                        quantite_attendue: l.quantite,
                        quantite_recue: '',
                    })));
                    rendre(rang);
                    rang.querySelector('.commande-articles').hidden = false;
                    rang.querySelector('.commande-articles-bascule').setAttribute('aria-expanded', 'true');
                }
                rang.querySelector('.commande-articles-mot').textContent = corps.message;
            })
            .catch(() => { mot.textContent = 'Lecture impossible. Réessayez.'; })
            .finally(() => { bouton.disabled = false; });
    });
})();
