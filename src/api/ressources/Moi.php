<?php

/**
 * GET /api/moi — qui est connecté, et ce qu'il a le droit de faire.
 *
 * Le JavaScript devinait les droits à ce que la page lui avait affiché ou non : un bouton
 * absent valait « interdit ». Il peut maintenant les demander.
 */
final class Moi
{
    public static function repondre(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        if ($requete->methode !== 'GET') return Reponse::methodeRefusee(['GET']);

        return Reponse::succes([
            'identifiant' => $utilisateur->identifiant,
            'prenom' => $utilisateur->prenom,
            'nom' => $utilisateur->nom,
            'email' => $utilisateur->email,
            'role' => [
                // Le code ne bouge pas, le libellé peut être renommé en base et le métier
                // est le mot du client : les trois servent, à trois endroits différents.
                'code' => $utilisateur->role?->name,
                'libelle' => $utilisateur->roleEnBase,
                'metier' => $utilisateur->role?->metier(),
            ],
            'droits' => [
                'voitToutesLesDemandes' => $utilisateur->voitToutesLesDemandes(),
                'deposeDesDemandes' => $utilisateur->deposeDesDemandes(),
                'gereLAtelier' => $utilisateur->gereLAtelier(),
                'remplitLesPv' => $utilisateur->remplitLesPv(),
                'consulteLesPv' => $utilisateur->consulteLesPv(),
                'pvLimitesASesDemandes' => $utilisateur->pvLimitesASesDemandes(),
            ],
        ]);
    }
}
