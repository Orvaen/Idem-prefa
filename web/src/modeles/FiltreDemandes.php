<?php

/**
 * Ce qu'on demande à une liste de demandes : les filtres de l'écran, et la page voulue.
 *
 * Un petit objet plutôt que six paramètres recopiés : la même liste figurerait dans
 * Demande::visiblesPar() et dans Demande::combien(), et deux listes finissent toujours par
 * se désynchroniser — on ajoute un filtre d'un côté, le total continue de compter sans lui,
 * et l'écran annonce quatre pages pour deux.
 *
 * Rien ici ne vient du dehors sans avoir été vérifié : c'est la ressource qui lit la chaîne
 * de requête et refuse ce qui n'est pas un nombre. Le modèle, lui, reçoit des entiers.
 */
final class FiltreDemandes
{
    /** Au-delà, une liste ne se lit plus : elle se pagine. */
    public const PAR_PAGE_MAX = 200;

    public readonly int $page;
    public readonly int $parPage;

    public function __construct(
        /** @var list<int> les statuts retenus ; vide, on les prend tous */
        public readonly array $statuts = [],
        /** Les urgentes seules, les normales seules, ou les deux quand c'est null. */
        public readonly ?bool $urgentes = null,
        /** @var list<int> les demandeurs retenus ; vide, on les prend tous */
        public readonly array $demandeurs = [],
        /** Cherché dans la référence, le chantier, le statut, la passivation, le demandeur. */
        public readonly string $recherche = '',
        int $page = 1,
        int $parPage = 50,
    ) {
        // Bornés ici, et pas chez l'appelant : ces deux nombres partent dans LIMIT et
        // OFFSET, où « page 0 » et « par_page 10000 » ne veulent rien dire. Une ressource
        // peut donc passer ce qu'elle a lu sans avoir à y penser.
        $this->page = max(1, $page);
        $this->parPage = min(max(1, $parPage), self::PAR_PAGE_MAX);
    }

    /** Combien de lignes sauter pour arriver à la page demandée. */
    public function decalage(): int
    {
        return ($this->page - 1) * $this->parPage;
    }
}
