# Lecture automatique des documents

L'application lit elle-même quatre sortes de documents, pour épargner la recopie : les
certificats matière, les bons de commande, les tableaux imprimés sur les plans, et le cartouche
des plans.

**Tout se passe sur le serveur**, avec deux outils libres installés dans l'image : **Poppler**
(`pdfinfo`, `pdftotext`, `pdftoppm`, `pdfimages`) et **Tesseract** (la reconnaissance de
caractères, en anglais et en français). Aucun document ne part vers un service externe, et aucun
modèle d'IA n'est appelé.

::: danger Une lecture est une proposition, jamais une vérité
Une coulée, une quantité, une date lue sur un scan peuvent être fausses — sur un vrai bon de
commande, deux quantités sur seize : « 3,00 » lu « 300 ». Chaque lecture est **relue et validée
par une personne** avant de servir : une coulée fausse est une non-conformité de traçabilité.
:::

## Le chemin d'une page

Pour chaque page, le lecteur choisit le chemin le plus sûr :

```
  la page porte-t-elle une grande image (≥ 1000 × 1000 px) ?   pdfimages -list
        │ non                                    │ oui : c'est un scan
        ▼                                        │
  pdftotext en donne-t-il au moins 30 mots ?     │
        │ oui                  │ non             │
        ▼                      ▼                 ▼
  lecture « texte »      pdftoppm -r 300 -gray, puis tesseract -l eng+fra --psm 6
  confiance 100          lecture « ocr », avec la confiance de chaque mot
```

Si un outil manque — l'image n'a pas été reconstruite depuis son ajout —, l'action répond `503`
et le dit. Pendant une lecture, la session est relâchée : les autres requêtes de la même
personne ne l'attendent pas.

## Les certificats matière (CCPU)

**Quoi :** le certificat EN 10204 type 3.1 du fournisseur de tubes, déposé en PDF dans la
demande. Il porte les **numéros de coulée**.

**Quand :** à la première ouverture de la fiche de la demande — pas au dépôt.

**Ce qu'il en tire**, par la position des mots sur la page (six pages au plus) :

| Donnée | Comment |
| --- | --- |
| Les coulées | Des mots de 4 à 12 caractères, dont au moins 3 chiffres, sous un en-tête *Heat*, *Coulée*, *Schmelze*, *Colata*, *Colada*, *Cast* ou *Charge* |
| `confirmee` | La coulée est retrouvée ailleurs sur la page, à deux caractères près |
| Le numéro du certificat, les dimensions, les nuances | `d × e × L`, `1.4404`, `TP` / `AISI`… |

**Où on le voit :** dans la fiche, sous chaque certificat — les champs lus, puis les coulées en
pastilles. Une coulée lue avec moins de 85 de confiance, ou non confirmée, porte un « ? ».

**Où on le valide :** une coulée n'est validée qu'une fois **choisie par l'encadrement** dans la
nomenclature d'un plan — parmi les coulées lues, ou saisie à la main. `confirmee` n'est **pas**
une validation humaine.

Un certificat déjà lu garde sa lecture : le lecteur est déterministe. Pour relire après une
amélioration du lecteur, remettre à `NULL` la colonne `date_lecture` du document, dans
`demande_ccpu_documents`.

## Les bons de commande

**Quoi :** le bon de commande IDEM, déposé sur la demande (famille « Commande matériel »). En
pratique **scanné** : sa couche texte est vide, la lecture passe par Tesseract.

**Ce qu'il en tire** (douze pages au plus) :

- l'en-tête : `COMMANDE <numéro> Du <date>` — le numéro est aussi celui qu'on saisit ailleurs ;
- chaque article, reconnu à sa forme : rang, référence, désignation, date de livraison, unité
  (PCE, ML, M, KG, U, UN, L, M2, M3, ENS), quantité, prix unitaire, total.

**Le garde-fou :** l'arithmétique du bon lui-même. Si quantité × prix unitaire s'écarte du total
de plus de 1 %, la ligne est marquée **douteuse** et la quantité recalculée.

**Où on le valide :** dans « Commande matériel » — on relit, on corrige, puis « Valider la
commande » la fige. Voir [Commande matériel](/fonctionnalites/commande-materiel).

Dans le PV « Réception matériel », « Lire le bon » propose les articles d'une commande
fournisseur sans rien écrire en base : le chef corrige, et tout s'enregistre avec le PV. Si rien
n'y est reconnu, le lecteur essaie de dire s'il s'agit en fait d'un certificat déposé à la place
du bon.

## Les tableaux des plans

**Quoi :** les plans ISO portent, imprimés, deux tableaux — la **nomenclature de matériel** et la
**liste des soudures**.

**Comment :** sans reconnaissance de caractères. La page est rendue en noir et blanc
(`pdftoppm -mono -r 200`), et le lecteur, écrit en PHP, en suit les traits :

| Tableau | Ce qu'il en tire |
| --- | --- |
| Nomenclature (en haut à droite) | Une case par ligne, dans la colonne « N° COULÉE » — c'est là que se posent les cases de coulée |
| Liste des soudures | Ses huit colonnes, les cases déjà écrites, le pas des lignes et le nombre de rangs libres — c'est le dénominateur des soudures |

Sur un scan, ou sur un gabarit de plan différent, rien n'est reconnu : les cases se posent à la
main. Le résultat est gardé en cache par page (`lignes-<…>.json`, à côté du PDF), et refait si le
PDF est plus récent.

La lecture d'un cahier entier peut partir **en arrière-plan** au dépôt d'un plan
(`outils/lire_feuilles.php`, verrouillé pour ne pas tourner deux fois) : ouvrir une feuille
ensuite ne fait plus attendre.

## Les numéros de ligne

Au rattachement d'un plan, un seul `pdftotext -bbox` relève le numéro de ligne de chaque feuille,
dans le cartouche ; `pdfinfo` donne le nombre de pages. Les deux sont enregistrés avec le
document.

## Le plan final

« Télécharger » le plan d'une feuille produit le PDF d'origine **intact et toujours vectoriel**,
avec par-dessus les coulées posées et la liste des soudures validée. Le lecteur ajoute ses
objets par une mise à jour incrémentale du PDF, sans le réécrire.

| Limite | Effet |
| --- | --- |
| Rien à écrire | Réponse `409` : aucune coulée posée, aucun cordon validé |
| PDF à flux d'objets compressés (`/ObjStm`) | Refusé, `422` |

## Essayer la lecture d'un certificat

`scripts/ccpu_lire.php` lit un certificat en ligne de commande et affiche ce qu'il reconnaît —
pour éprouver un nouveau modèle de certificat avant de s'y fier.

::: warning Ce script est cassé
Il inclut `dashboard/includes/ccpu.php`, qui n'existe plus : le lecteur vit maintenant dans
`dashboard/includes/lecture/ccpu.php`. Le corriger avant de s'en servir.
:::

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/includes/lecture/ccpu.php` | Le chemin d'une page, et le lecteur des certificats |
| `src/dashboard/includes/lecture/commandes_lignes.php` | Le lecteur des bons de commande, et le garde-fou arithmétique |
| `src/dashboard/includes/lecture/plan_table.php` | Les tableaux des plans |
| `src/dashboard/includes/lecture/plan_lignes.php` | Le cache des tableaux, le rangement des soudures |
| `src/dashboard/includes/lecture/plan_numeros.php` | Les numéros de ligne |
| `src/dashboard/includes/lecture/plan_final.php` | Le plan final |
| `src/dashboard/actions/lire_ccpu.php`, `lire_bon_materiel.php`, `lire_bon_commande.php`, `detecter_lignes.php` | Les actions qui lancent les lectures |
