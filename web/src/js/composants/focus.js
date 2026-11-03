(() => {
    'use strict';

    // L'anneau de focus, pour qui se sert du clavier, et pour lui seul.
    //
    // Chrome allume l'anneau d'un élément dès qu'une touche est pressée pendant qu'il a le
    // focus — Échap compris. Or un clic dans un tableau donne le focus à son cadre, qui porte
    // un tabindex pour défiler au clavier : refermer ensuite une fenêtre par Échap dessinait
    // l'anneau du navigateur autour du tableau entier. Et la fenêtre refermée rend le focus au
    // bouton qui l'avait ouverte, qui s'allumait à son tour.
    //
    // On retient donc comment le focus est arrivé. Par Tab, l'anneau reste ; par un clic ou un
    // doigt, l'élément porte data-focus-pointeur, et dashboard-base.css l'éteint. Échap,
    // Entrée ou les flèches ne disent rien de la façon dont on est arrivé là.
    let auPointeur = false;
    document.addEventListener('pointerdown', () => { auPointeur = true; }, true);
    document.addEventListener('keydown', evenement => {
        if (evenement.key === 'Tab') auPointeur = false;
    }, true);
    document.addEventListener('focusin', evenement => {
        evenement.target.toggleAttribute?.('data-focus-pointeur', auPointeur);
    });
})();
