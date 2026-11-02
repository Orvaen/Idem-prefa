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

### Tout est lu au dépôt, en arrière-plan

Un plan se lit **dès qu'il arrive**, page après page, en arrière-plan : les deux tableaux, les
numéros des soudures, la nomenclature, l'image que la fenêtre du plan affiche et sa vignette de
sommaire (`includes/lecture/plan_index.php`, lancé par `outils/lire_feuilles.php`). Ouvrir une
feuille ensuite ne fait plus attendre.

| Quand | Ce qui se lit |
| --- | --- |
| Un plan déposé sur la demande — à sa création, à sa modification, ou par « Déposer » dans sa fiche | Les plans de la demande, avant même que le chef d'atelier en fasse un plan d'atelier |
| Un plan créé ou déposé dans Plans / ISO | Ses feuilles |
| Une feuille ouverte sur un plan qui n'est pas à jour | Les autres feuilles, en fond ; celle qu'on ouvre se lit sur place |

Mesuré sur le cahier d'essai de 15 feuilles : 1 min 12 s pour tout lire en fond. Une feuille
jamais lue mettait 3,7 s à s'ouvrir ; lue, elle s'ouvre en 0,25 s, et une autre page du cahier
en 0,2 s.

Ouvrir une feuille lance d'un coup sa page, ses quinze vignettes, sa liste soudure et ses
tableaux. Ces actions rendent la session dès qu'elles savent qui demande (`Session::liberer()`) :
PHP la verrouille sinon jusqu'à la fin de chaque requête, et les dix-huit passaient l'une après
l'autre — 1,1 s au lieu de 0,25. La fenêtre s'ouvre aussi directement sur la page demandée, sans
lire la première d'abord.

**Un repère par document** (`index-<…>.json`, à côté du PDF) dit qu'il a été lu en entier, et
pour quelles versions des lectures. Il garde aussi le nombre de soudures de chaque page : le
total du cahier s'y lit d'un coup, au lieu de rouvrir quinze fichiers à chaque feuille ouverte
(0,27 s, à travers le montage depuis Windows). Une lecture qui change de version fait relire
chaque plan une fois, à sa prochaine ouverture ; un PDF remplacé aussi. Un verrou par document
évite deux lectures du même en même temps. Une page qui résiste n'arrête pas les suivantes :
elle se lira à son ouverture.

Pour tout relire d'un coup après une mise à jour qui change une lecture : `run lire-plans`
(`terminal.json`). Il lance `outils/lire_feuilles.php --tous` dans le conteneur web, sous
`www-data` — sinon les fichiers gardés appartiendraient à `root` et l'application ne pourrait
plus les refaire —, et dit plan par plan s'il l'a lu ou s'il était à jour. À la main, depuis le
terminal Orvaen, c'est `docker.exe compose …` : `docker` y est le moteur léger du terminal, où
le projet ne tourne pas (« service "php" is not running »).

## Les numéros des soudures, sur le dessin

**Quoi :** où chaque numéro de soudure est dessiné sur l'isométrique — c'est là que le soudeur
clique pour renseigner sa soudure ([Mes soudures](/fonctionnalites/soudures)).

**Comment :** AutoCAD Plant 3D trace tout son texte en courbes, et `pdftotext` ne voit sur une
feuille que les valeurs du cartouche. Le numéro se cherche donc sur l'image, en deux temps :

| Étape | Ce qui se passe |
| --- | --- |
| Les rectangles | Sur la page en noir et blanc à 200 dpi — la même que celle des tableaux —, chaque petit trait horizontal sert de départ ; on vérifie l'encre des quatre côtés. Les traits des tableaux et du cadre courent d'un bord à l'autre : ils s'écartent d'eux-mêmes. Les cercles sont les repères de la nomenclature, pas des soudures. |
| Le chiffre | Chaque rectangle est relu à 600 dpi, et son chiffre comparé à ceux de la colonne N° de la liste soudure, imprimés dans la même police. Ce qui traverse le rectangle de part en part — le tube, une ligne de cote — est écarté. Chaque numéro va à un seul rectangle ; quand il reste un rectangle et un numéro, c'est lui (« déduit »). |

Mesuré sur un cahier de 15 feuilles : 69 rectangles trouvés sur 69 soudures, 69 numéros justes,
1 à 2,5 s par page. Un numéro qu'on ne place pas avec certitude n'est pas deviné : il se
renseigne hors du dessin. Le résultat est gardé par page (`reperes-<…>-p<page>.json`, à côté
du PDF) ; une lecture interrompue n'est pas gardée. La lecture en arrière-plan d'un cahier le
calcule avec les tableaux.

::: warning Les morceaux à 600 dpi se rendent en gris
Au-delà de 300 dpi, `pdftoppm -mono` trame les couleurs au hasard : les traits rouges et verts
sortaient en pointillés différents à chaque appel, et un chiffre qui touche un trait s'y collait
une fois sur deux — le même plan donnait un numéro, puis aucun. Les morceaux se rendent donc en
gris (`-gray`), et le seuil est appliqué en PHP. La page entière, à 200 dpi, reste en noir et
blanc : sa trame est fixe.
:::

## La nomenclature, sur le dessin

**Quoi :** chaque ligne de la NOMENCLATURE DE MATÉRIEL — où elle est, et ce qu'elle dit :
repère, quantité, DN, épaisseur, désignation. Le soudeur clique la ligne du matériel qui lui
manque, et la fenêtre « Signaler un manque » arrive remplie
([Mes soudures](/fonctionnalites/soudures#signaler-un-manque)).

**Comment :** la grille est celle que la détection des tableaux trouve déjà pour poser les
coulées. Le texte, lui, est en courbes : il se lit à l'**OCR, en local** (Tesseract), sur le
seul corps du tableau, rendu en gris à 400 dpi.

| Précaution | Pourquoi |
| --- | --- |
| Le quadrillage est effacé | Tesseract lisait chaque trait comme un « \| », un « [ » ou un « J » collé au mot voisin — « JCOLLERETTE ». |
| Les colonnes sont écartées d'un blanc | Sans trait entre elles, le repère « 1 » et la quantité « 2.0M » ne faisaient qu'un mot, « 12.0M ». |
| Les chiffres se lisent à part | Repère, quantité, DN et épaisseur, sans autre lettre que le X d'une dimension et le M d'une longueur. Lue avec le texte, la police du plan faisait de « 3.3M » « 315M », et de « 5D » « SD ». |

Mesuré sur un cahier de 15 feuilles : toutes les cases justes, une seconde par page. Un repère
illisible prend le rang de sa ligne. Le résultat est gardé par page
(`nomenclature-<…>-p<page>.json`, à côté du PDF) ; une lecture interrompue n'est pas gardée.
La lecture en arrière-plan d'un cahier le calcule avec le reste.

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
| Rien à écrire | Réponse `409` : aucune coulée posée, aucune soudure validée |
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
| `src/dashboard/includes/lecture/plan_reperes.php` | Les numéros des soudures sur le dessin, et leur cache |
| `src/dashboard/includes/lecture/plan_nomenclature.php` | La nomenclature lue sur le dessin, l'article d'une ligne, et leur cache |
| `src/dashboard/includes/lecture/plan_numeros.php` | Les numéros de ligne |
| `src/dashboard/includes/lecture/plan_index.php`, `src/dashboard/outils/lire_feuilles.php` | La lecture d'un plan entier au dépôt, son repère, les images des pages |
| `src/dashboard/includes/lecture/plan_final.php` | Le plan final |
| `src/dashboard/actions/lire_ccpu.php`, `lire_bon_materiel.php`, `lire_bon_commande.php`, `detecter_lignes.php` | Les actions qui lancent les lectures |
