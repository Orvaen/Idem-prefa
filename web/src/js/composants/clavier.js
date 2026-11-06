// Le clavier d'une tablette.
//
// Il prend la moitié de l'écran, et la page n'a plus que l'autre moitié : l'appli Android
// réduit la vue à ce qui reste, et Chrome fait de même (interactive-widget=resizes-content,
// dans la balise viewport). Mais la page ne le savait pas — la barre du haut, les marges et
// les fenêtres calées sur la hauteur de l'écran gardaient leur place, et le champ qu'on
// remplissait finissait sous le clavier ou contre son bord.
//
// Ce composant le dit à la page : « clavier-ouvert » sur <html>, tant qu'un champ de saisie a
// le focus et que la hauteur visible a fondu. Les feuilles rendent alors la place — la barre
// du haut se retire, les fenêtres prennent toute la hauteur qui reste —, et le champ revient
// au milieu de ce qu'on voit.
//
// Au doigt seulement : à la souris, il n'y a pas de clavier à l'écran.
(() => {
    'use strict';

    if (!window.matchMedia?.('(pointer: coarse)').matches) return;

    const racine = document.documentElement;
    const vue = window.visualViewport;
    const hauteur = () => (vue ? vue.height : window.innerHeight);

    // Ce qui ouvre un clavier : un champ où l'on tape, et qu'on peut modifier.
    const SAISIE = 'input:not([type="checkbox"]):not([type="radio"]):not([type="button"])'
        + ':not([type="submit"]):not([type="reset"]):not([type="file"]):not([type="range"])'
        + ':not([type="color"]), textarea, [contenteditable=""], [contenteditable="true"]';
    const estUneSaisie = element => Boolean(element?.matches?.(SAISIE))
        && !element.readOnly && !element.disabled;

    // La hauteur sans clavier, retenue par orientation de l'appareil : tourner la tablette
    // change tout, et la hauteur du paysage n'a rien à dire du portrait. Par orientation et
    // non par largeur exacte : sur l'appareil, la barre de navigation d'Android revient avec
    // le clavier et peut rogner la largeur de quelques pixels — la page croyait alors voir un
    // nouvel écran, sans hauteur de référence, et ne reconnaissait plus le clavier.
    const pleines = new Map();
    const orientation = () => (window.screen?.orientation?.type
        || (window.screen && window.screen.width > window.screen.height ? 'landscape' : 'portrait')).split('-')[0];

    // Moins de 80 % de la hauteur pleine, avec un champ actif : c'est le clavier. Une barre
    // d'adresse qui se replie ne fait pas 20 %.
    const SEUIL = 0.8;

    // L'appli Android sait, elle, quand le clavier est là : elle l'annonce (« clavier:natif »,
    // MainActivity). Dès qu'elle a parlé, sa parole remplace le calcul des hauteurs.
    let natif = null;

    let rappel;

    function verifier() {
        const sens = orientation();
        const visible = hauteur();
        const pleine = Math.max(pleines.get(sens) || 0, visible);
        pleines.set(sens, pleine);

        const champ = document.activeElement;
        const ouvert = estUneSaisie(champ) && (natif !== null ? natif : visible < pleine * SEUIL);
        racine.classList.toggle('clavier-ouvert', ouvert);
        if (!ouvert) return;

        // Après que les feuilles ont rendu la place : sinon le champ se range au milieu d'une
        // page qui va encore bouger. « nearest » d'abord pour les conteneurs qui défilent,
        // puis le milieu de l'écran.
        clearTimeout(rappel);
        rappel = setTimeout(() => {
            if (document.activeElement === champ) champ.scrollIntoView({ block: 'center', inline: 'nearest' });
        }, 80);
    }

    window.addEventListener('clavier:natif', evenement => {
        natif = Boolean(evenement.detail);
        // La vue se redimensionne au même moment : on laisse la page prendre sa nouvelle
        // taille avant de ranger le champ.
        setTimeout(verifier, 50);
    });

    (vue || window).addEventListener('resize', verifier);
    window.screen?.orientation?.addEventListener?.('change', () => setTimeout(verifier, 300));
    // Le clavier met quelques centièmes à monter, et le focus passe d'un champ à l'autre sans
    // qu'il redescende : on revérifie un peu après chaque changement de focus.
    document.addEventListener('focusin', () => setTimeout(verifier, 250));
    document.addEventListener('focusout', () => setTimeout(verifier, 120));
    verifier();
})();
