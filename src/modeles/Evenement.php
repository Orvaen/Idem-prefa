<?php

/**
 * Un fait qui vient d'arriver, et qu'il faut annoncer aux écrans ouverts.
 *
 * Une action qui change quelque chose de visible le note ici, en une ligne. Le flux —
 * api/flux.php — lit le journal et pousse ce que chacun a le droit de voir. L'action n'a
 * donc à connaître ni qui est connecté, ni ce que chacun verra : elle dit ce qui s'est
 * passé, le flux décide à qui ça s'adresse.
 */
final class Evenement
{
    /** Une demande vient d'être déposée et attend une décision. */
    public const DEMANDE_DEPOSEE = 'demande.deposee';
    /** Une demande a été validée : l'atelier peut la prendre. */
    public const DEMANDE_VALIDEE = 'demande.validee';
    /** Une demande a été refusée, avec son motif. */
    public const DEMANDE_REFUSEE = 'demande.refusee';
    /** Une affaire a été confiée à un chargé d'affaire. Elle ne regarde que lui. */
    public const AFFAIRE_CONFIEE = 'affaire.confiee';
    /** L'atelier signale un manque de matériel. Il s'adresse à l'encadrement, nommément. */
    public const MANQUE_SIGNALE = 'manque.signale';
    /** Un manque signalé est traité : celui qui l'a signalé l'apprend. */
    public const MANQUE_TRAITE = 'manque.traite';

    /**
     * Note un événement.
     *
     * Jamais dans une transaction qu'on peut encore annuler : un événement annoncé pour
     * une demande qui n'a pas été enregistrée est pire que pas d'événement du tout. Les
     * appels se font donc après le commit.
     *
     * Il n'échoue pas bruyamment : qu'une annonce manque ne doit pas faire croire à
     * l'utilisateur que son enregistrement a échoué.
     *
     * Un événement s'adresse soit à tous ceux qui ont le droit de voir la demande qu'il
     * concerne — c'est le cas par défaut, destinataire laissé vide — soit à une personne
     * précise. Une affaire confiée ne regarde que celui à qui on la confie.
     *
     * @param array<string, mixed> $donnees ce qu'il faut pour écrire l'alerte
     */
    public static function noter(
        PDO $db,
        string $type,
        ?int $idDemande,
        ?int $idActeur,
        array $donnees = [],
        ?int $idDestinataire = null
    ): void {
        try {
            $db->prepare('INSERT INTO evenement (type, id_demande, id_acteur, id_destinataire, donnees)
                          VALUES (?, ?, ?, ?, ?)')
               ->execute([
                   $type,
                   $idDemande,
                   $idActeur,
                   $idDestinataire,
                   $donnees ? json_encode($donnees, JSON_UNESCAPED_UNICODE) : null,
               ]);
            // Les mêmes faits dans le journal du conteneur. La table evenement dit aux
            // écrans ouverts ce qui vient de changer ; elle ne se lit pas quand on cherche,
            // le lendemain, qui a validé quoi et dans quel ordre.
            Journal::noter(self::resume($type, $idDemande, $donnees));
        } catch (PDOException $erreur) {
            Journal::erreur('Événement non noté (' . $type . ')', $erreur);
        }
    }

    /**
     * L'événement en une ligne de journal : « demande.validee demande 42 affaire=Pont 3 ».
     *
     * @param array<string, mixed> $donnees
     */
    private static function resume(string $type, ?int $idDemande, array $donnees): string
    {
        $ligne = $type . ($idDemande !== null ? ' demande ' . $idDemande : '');
        foreach ($donnees as $cle => $valeur) {
            if (!is_scalar($valeur) || $valeur === '' || $valeur === false) continue;
            $ligne .= ' ' . $cle . '=' . ($valeur === true ? 'oui' : $valeur);
        }
        return $ligne;
    }
}
