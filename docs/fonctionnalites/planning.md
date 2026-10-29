# Planning

Le Gantt des demandes validées, rangées par affaire.

**Écran :** « Planning », section « Atelier » — chargé de production (qui le modifie) et chef
d'atelier (qui le consulte).

## Ce qu'on y voit

- **Une barre par demande**, du début à la fin planifiés, et une barre fine par affaire qui
  couvre toutes les siennes. La ligne d'aujourd'hui traverse le calendrier.
- **Une période** de 1, 4, 8 ou 12 semaines ; « Précédent », « Suivant » et « Aujourd'hui » pour
  s'y déplacer. On fait aussi glisser le calendrier à la souris.
- **Des filtres** par référence et par demandeur.
- **« À planifier »** : les demandes validées qui n'ont pas encore de dates.
- **Le volet d'une affaire ou d'une demande** : la matrice d'avancement — les étapes de chaque
  plan — et les commentaires, exportables en CSV.

## Ce qu'on y fait

Le **chargé de production** modifie le début et la fin d'une demande — la fin se recalcule en
jours ouvrés —, l'enregistre, ou la **retire du planning** (ses dates sont effacées, après
confirmation). Le chef d'atelier voit le même écran en lecture seule.

| Règle | Détail |
| --- | --- |
| Demande validée | Seule une demande validée se planifie. Sa validation la planifie déjà d'office. |
| Conflit | Si quelqu'un d'autre a modifié la demande entre-temps, l'enregistrement est refusé (`409`) au lieu d'écraser son travail. |

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/planning.php` | Le Gantt. Les colonnes sont passées à la feuille en variables CSS (`--debut`, `--duree`). |
| `src/dashboard/includes/atelier/planning.php` | Le calcul des périodes et des jours ouvrés |
| `src/dashboard/actions/save_planning.php` | L'enregistrement — chargé de production seulement |
| `src/js/planning.js`, `src/css/dashboard/planning.css` | L'interaction et le dessin |
