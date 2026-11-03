<?php

function userRoleLabel(array $user): string
{
    return $user['role_name'] ?? 'Non renseigné';
}

/**
 * Intitulé métier accolé au rôle. Utilisé uniquement dans le pied de la barre
 * latérale et dans le menu des comptes, pas dans la liste des utilisateurs.
 */
function roleComplement(int $idRole): string
{
    return match ($idRole) {
        1 => 'Chargé de production',
        2 => "Chef d'atelier",
        3 => 'Personnel atelier',
        5 => "Chargé d'affaire",
        default => '',
    };
}

function userCreationLabel(array $user): string
{
    if (empty($user['date_creation'])) return 'Non renseignée';
    $date = new DateTimeImmutable($user['date_creation'], new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');
}
