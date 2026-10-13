(() => {
    // Le flux d'événements : la page écoute, le serveur parle. Rien ici ne redemande
    // périodiquement au serveur s'il y a du nouveau — c'est lui qui annonce.
    //
    // EventSource gère seul la reconnexion et se souvient du dernier identifiant reçu : le
    // serveur raccroche au bout d'une minute pour rendre son processus, et la reprise se
    // fait toute seule, à l'endroit exact où l'on s'était arrêté.
    if (!window.EventSource) return;

    const PILE_MAX = 3;          // au-delà, les plus anciennes alertes cèdent la place
    const DUREE = 9000;          // combien de temps une alerte reste à l'écran

    let pile = document.getElementById('flux-alertes');
    if (!pile) {
        pile = document.createElement('div');
        pile.id = 'flux-alertes';
        pile.className = 'flux-alertes';
        // Les alertes sont annoncées sans voler le focus : on est peut-être en train de
        // remplir un formulaire quand elles arrivent.
        pile.setAttribute('role', 'status');
        pile.setAttribute('aria-live', 'polite');
        document.body.appendChild(pile);
    }

    function retirer(alerte) {
        if (!alerte.isConnected) return;
        alerte.classList.add('est-sortante');
        alerte.addEventListener('transitionend', () => alerte.remove(), { once: true });
        // Si la transition ne joue pas — onglet en arrière-plan, mouvement réduit — la
        // carte resterait indéfiniment : on la retire quand même.
        setTimeout(() => alerte.remove(), 400);
    }

    function alerter({ titre, detail, urgente, ouvre }) {
        const alerte = document.createElement('div');
        alerte.className = 'flux-alerte' + (urgente ? ' est-urgente' : '');
        alerte.innerHTML =
            '<div class="flux-alerte-texte">'
            + '<strong></strong>'
            + '<span></span>'
            + '</div>'
            + '<button type="button" class="flux-alerte-fermer" aria-label="Fermer">'
            + '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round">'
            + '<path d="m6 6 12 12M18 6 6 18" /></svg></button>';
        // textContent et non innerHTML : le titre et le détail viennent de la base, donc
        // d'un nom d'affaire que quelqu'un a saisi.
        alerte.querySelector('strong').textContent = titre;
        alerte.querySelector('span').textContent = detail;

        alerte.querySelector('.flux-alerte-fermer').addEventListener('click', event => {
            event.stopPropagation();
            retirer(alerte);
        });

        if (ouvre) {
            alerte.classList.add('est-cliquable');
            alerte.addEventListener('click', () => {
                document.getElementById(ouvre)?.click();
                retirer(alerte);
            });
        }

        pile.appendChild(alerte);
        while (pile.children.length > PILE_MAX) pile.firstElementChild.remove();
        setTimeout(() => retirer(alerte), DUREE);
    }

    // Les compteurs sont posés, jamais incrémentés : deux onglets ouverts, une alerte
    // ratée pendant une reconnexion, et un compteur incrémenté dérive sans qu'on le voie.
    function poserCompteurs({ enAttente, urgentes }) {
        if (typeof urgentes === 'number' && typeof window.updateUrgentBadge === 'function') {
            window.updateUrgentBadge(urgentes);
        }
        const tuile = (cle, valeur) => {
            const cible = document.querySelector('.accueil-tuile[data-tuile="' + cle + '"]');
            if (!cible || typeof valeur !== 'number') return;
            cible.querySelector('.accueil-tuile-valeur').textContent = valeur;
            cible.classList.toggle('est-en-alerte', valeur > 0);
        };
        tuile('en-attente', enAttente);
        tuile('urgentes', urgentes);
    }

    // Ce que chaque sorte d'événement raconte. Le serveur dit ce qui s'est passé ; c'est
    // ici qu'on décide des mots, parce qu'ils dépendent de qui lit — le demandeur apprend
    // que SA demande est passée, le chargé de production qu'il en a une de plus à voir.
    const RECITS = {
        'demande.deposee': e => ({
            titre: e.urgente ? 'Nouvelle demande urgente' : 'Nouvelle demande',
            detail: [e.reference, e.affaire, e.demandeur].filter(Boolean).join(' · '),
            urgente: e.urgente,
        }),
        'demande.validee': e => ({
            titre: 'Demande validée',
            detail: [e.reference, e.affaire].filter(Boolean).join(' · '),
        }),
        'affaire.confiee': e => ({
            titre: 'Une affaire vous est confiée',
            detail: e.affaire,
            // Le chargé d'affaire n'a rien à examiner : il a une demande à déposer.
            ouvre: 'btn-prefa',
        }),
        'demande.refusee': e => ({
            titre: 'Demande refusée',
            // Le motif est la seule chose qui compte dans un refus : sans lui, il faut
            // ouvrir la demande pour savoir quoi corriger.
            detail: [[e.reference, e.affaire].filter(Boolean).join(' · '), e.motif]
                .filter(Boolean).join(' — '),
            urgente: true,
        }),
    };

    const flux = new EventSource('/api/flux.php');

    flux.addEventListener('compteurs', evenement => {
        poserCompteurs(JSON.parse(evenement.data));
    });

    Object.keys(RECITS).forEach(type => {
        flux.addEventListener(type, evenement => {
            const donnees = JSON.parse(evenement.data);
            poserCompteurs(donnees);
            // Chaque récit peut dire quel écran il ouvre ; la liste des demandes par défaut.
            alerter({ ouvre: 'btn-list-prefa', ...RECITS[type](donnees) });
        });
    });
})();
