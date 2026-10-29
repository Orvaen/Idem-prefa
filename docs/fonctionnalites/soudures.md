# Soudures

Chaque plan ISO porte, imprimé, le **tableau de ses soudures**. Le soudeur le remplit cordon par
cordon ; l'encadrement le suit et le valide.

## Mes soudures — le personnel d'atelier

**Écran :** « Mes soudures & Suivi matériel », section « Atelier » — personnel d'atelier.

1. **Choisir le plan** — une liste avec recherche ; le choix est retenu le temps de la session.
   Rien n'est présélectionné : on arrive avec son papier en main, le logiciel ne devine pas.
2. **Choisir la feuille** — une ligne par page du cahier ISO, avec ses cordons faits sur les
   cordons prévus au plan, et une barre. Une recherche par numéro de ligne et un filtre d'état
   (À faire, En cours, Soudées, Pas encore ouvertes) aident à la trouver. « Voir le dessin »
   l'ouvre, en lecture.
3. **Remplir les cordons** : une ligne par repère imprimé sur le plan, avec la **date** et le
   **procédé** — des suggestions TIG, MIG, MAG, 111, 135, 141. Le soudeur, c'est toujours soi :
   le serveur pose son nom.

| Règle | Détail |
| --- | --- |
| D'où vient la liste | Du dessin : le tableau de soudures est **reconnu sur le PDF**, page par page. Le dénominateur est celui du plan, pas un compte de ce qu'on a saisi. |
| Vider une ligne | La remet « à faire ». |
| Les lignes d'un autre soudeur | Verrouillées. |
| Les contrôles RT, VT, PT et le visa | Réservés à l'encadrement : ils ne s'affichent pas ici. |
| L'avancement | Enregistrer ses cordons **déclare** l'avancement du suivi soudage, et le chef d'atelier valide. Il suffit d'être affecté au plan, à n'importe quel travail : si le plan n'a pas encore de suivi soudage, il s'ouvre au nom du soudeur, « affecté par » celui qui lui avait confié le plan. Le chiffre des cordons ne va jamais sur le Plan / ISO, qui se compte en étapes. |

Ouvrir une feuille déclenche la reconnaissance du tableau sur le PDF : quelques secondes. C'est
une des raisons pour lesquelles rien n'est choisi d'office.

### Signaler un manque

Depuis le même écran, « Signaler un manque » ouvre une fenêtre : le plan, l'article — tiré du bon
de commande de la demande, ou saisi librement —, la quantité et l'unité, le motif (oubli, erreur
de livraison, casse ou rebut, autre) et une précision. Le chef d'atelier et le chargé de
production sont prévenus aussitôt, nommément.

L'**unité** se choisit dans la liste que tient le chargé de production (Paramètres → Matériel) :
on tape pour chercher, on choisit — comme l'article. Un article du bon apporte la sienne si la
liste la connaît, sans égard aux majuscules (« pce » retient « PCE »). Le serveur refuse une unité
hors de la liste. Tant que la liste n'existe pas sur un serveur (migration
`2026-10-29_unites_materiel` pas encore passée), l'unité reste un champ libre.

Le tableau **« Matériel signalé »** suit ses manques : ceux en cours, et ceux traités depuis moins
de trente jours. Voir [Commande matériel](/fonctionnalites/commande-materiel#les-manques).

## Soudures — le suivi de l'encadrement

**Écran :** « Soudures », section « Atelier » — chargé de production et chef d'atelier.

- **Les plans** qui ont des cordons relevés ou une liste de soudures reconnue : demande,
  affaire, plan, personnes affectées, cordons « x / y », avancement validé, et un état — À
  valider, En cours, Toutes soudées, Pas commencé.
- **Des filtres** : recherche, état, affaire.
- **« Examiner »** ouvre le plan :
  - les **déclarations** à valider ou refuser ;
  - des cordons relevés **sans déclaration** — enregistrés avant que la liste du plan soit
    lue, ou avant que le suivi soudage s'ouvre tout seul — : un bouton « Valider » pose le
    chiffre calculé. Sans suivi soudage, le chef n'a **rien à affecter** avant : « Valider »
    l'ouvre au nom du soudeur, déjà affecté au plan (un bouton par soudeur s'ils sont
    plusieurs). Seul cas sans bouton : plus personne de l'atelier n'est affecté au plan ;
  - le tableau des cordons — numéro, feuille, soudeur, date, type, RT, VT, PT, visa —, en
    lecture.

### Les contrôles, posés en validant

Chaque « Valider » qui fait passer des cordons sur le plan les montre d'abord : ceux du soudeur
concerné, saisis depuis sa dernière validation. Une ligne par cordon, une case par contrôle
— **RT**, **VT**, **PT** — et, s'il y en a plusieurs, une ligne « Tous les cordons » qui coche
une colonne entière. Une case cochée est une **croix** sur le plan : le contrôle est OK. Une case
vide n'écrit rien.

| Règle | Détail |
| --- | --- |
| Où | La carte « À valider » du volet Soudures, celle de la fiche d'une demande, celle de Plans / ISO, et le bloc « Cordons relevés, pas encore validés ». |
| Refuser | Ne pose rien. |
| Une liste périmée | Un cordon saisi entre l'affichage et le clic fait refuser la validation (`409`) : on ne valide pas un cordon qu'on n'a pas vu. |
| Les cordons déjà validés | N'y reviennent pas. Leurs contrôles se corrigent dans la visionneuse. |
| La saisie d'un pourcentage | La fenêtre « Saisir l'avancement » de Plans / ISO ne montre pas les contrôles : c'est une reprise en main du chiffre, pas une validation de cordons. |

« VT » et non « VI » : c'est l'en-tête que le bureau d'études imprime sur ses listes soudure. La
colonne garde son nom en base, `plan_soudure.vi`.

### Le visa, posé en validant

Valider des cordons, c'est les **viser**. La case **VISA** de chacun reçoit le **prénom et le nom
de celui qui valide**, tirés de son compte, écrits comme une signature — dans une écriture
manuscrite, sur le plan final comme dans la fenêtre du plan. Rien à dessiner, rien à taper : le
bloc des contrôles le dit avant le clic (« Valider » les vise à votre nom, …).

| Règle | Détail |
| --- | --- |
| Qui vise | Celui qui valide : chef d'atelier ou chargé de production. |
| Ce qui est rangé | `plan_soudure.vise_par` et `date_visa` : qui, et quand. Le nom s'écrit à l'affichage, depuis le compte. |
| Refuser | Ne vise rien. |
| Les cordons validés autrement | « Saisir l'avancement » et les étapes couvrent des cordons sans les viser : leur case VISA reste vide. |
| Sur le plan final | Le nom est du **texte**, pas une image : net à tous les zooms, et retrouvé par une recherche dans le PDF. Il rétrécit pour tenir dans la case. |

L'écriture est **Great Vibes** (licence OFL, `src/assets/fonts/`), réduite aux caractères latins
d'Europe de l'Ouest : 36 Ko en `.ttf`, que le plan final **embarque une fois** pour tout le
document — il s'affiche et s'imprime pareil partout, sans dépendre des polices du poste —, et
20 Ko en `.woff2` pour l'écran. Les deux sont produits par fontTools, sans crénage ni ligature :
le PDF écrit lettre après lettre, et l'écran doit montrer la même chose. Changer d'écriture,
c'est remplacer ces deux fichiers (voir l'en-tête de `ecriture_visa.php`).

## La visionneuse

Dans « Plans / ISO & Affectations », la visionneuse plein écran d'une feuille porte deux choses
de plus que le dessin.

### Les cases de coulée

Une case posée sur une pièce du dessin dit de quelle **coulée** elle vient — la traçabilité de la
matière.

- Elles se posent **d'elles-mêmes** quand la « Nomenclature de matériel » du dessin est reconnue.
- On les **déplace** en les glissant, on les **redimensionne** par leurs huit poignées.
- Un **double-clic** choisit la coulée, parmi celles lues sur les certificats CCPU de la demande.
- Au clavier : `Ctrl D` duplique, `Ctrl C` / `Ctrl V` copient, `Suppr` retire, les flèches
  déplacent.
- L'enregistrement est protégé : si quelqu'un d'autre a modifié la feuille entre-temps, il est
  refusé (`409`) au lieu d'écraser son travail.

### La liste des soudures

L'encadrement y édite la liste : numéro, soudeur — parmi ceux qui ont travaillé sur ce plan —,
date, type, et les contrôles RT, VT, PT cochés en croix.

### Le plan final

Le **plan final**, c'est le plan d'origine avec par-dessus les coulées, la liste des soudures,
ses croix et ses visas. N'y figurent que les cordons couverts par une validation. Il se
**regarde dans la visionneuse** — c'est ce qu'elle montre, rangé par le même code que le PDF —
et se **télécharge en PDF** depuis sa barre : « Télécharger le PDF ». Le PDF est composé à la
demande, en quelques secondes pour un cahier ; un refus du serveur (rien à écrire) s'affiche
dans un toast.

Il y en a un par feuille ISO, pas un par demande. Le chargé de production et le chargé
d'affaire les retrouvent aussi depuis la liste des demandes : le menu **⋯** d'une ligne porte un
sous-menu « Plan final », qui range les feuilles sous leur plan et ouvre celle qu'on choisit dans
la visionneuse, **en consultation** — rien ne s'y modifie. Une feuille où rien n'est encore
posé le dit (« rien de posé ») : on y verrait le plan nu.

La visionneuse est servie à la demande (`plan_final_lecteur.php`), et son script et sa feuille
de style arrivent avec elle quand la page ne les a pas — c'est le cas du chargé d'affaire, qui
n'a pas d'écran du plan ISO. Pour qu'elle y montre la liste soudure, il lit les tableaux du
plan (`detecter_lignes.php`) **de ses propres demandes** ; lire ne pose rien.

Le sous-menu est montré au chargé de production et au **chargé d'affaire**, qui ne le voit que
sur ses propres demandes — chaque adresse refait la vérification. Le chef d'atelier a le droit,
mais pas l'entrée : il ouvre la visionneuse dans Plans / ISO. L'ouvrir à lui, c'est élargir la
condition dans `prefa_list_data.php`.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/soudures.php`, `src/js/soudures.js` | Mes soudures, et la fenêtre « Signaler un manque » |
| `src/dashboard/pages/suivi_soudures.php`, `src/js/suivi-soudures.js` | Le suivi de l'encadrement |
| `src/dashboard/includes/atelier/soudure_atelier.php` | Les règles de saisie du soudeur |
| `src/dashboard/actions/soudures_feuille.php`, `save_soudures_atelier.php` | La feuille et son enregistrement — personnel affecté au plan |
| `src/dashboard/actions/save_soudure.php`, `save_nomenclature.php` | La liste des soudures et les cases de coulée — encadrement |
| `src/dashboard/actions/detecter_lignes.php` | La reconnaissance du tableau sur le PDF |
| `src/dashboard/actions/download_plan_final.php` | Le plan final |
| `planFinalParDemandes()` dans `src/dashboard/includes/atelier/plan_document.php`, `src/dashboard/partials/prefa_list_row.php` | Le sous-menu « Plan final » de la liste des demandes |
| `src/dashboard/actions/plan_final_lecteur.php`, `ouvrirLecteurPlanFinal()` dans `src/js/prefa-interactions.js` | La visionneuse en consultation, servie à la demande pour le sous-menu |
| `src/js/plan-viewer.js` | La visionneuse et ses cases |
| `avancementViserCordons()` dans `src/dashboard/includes/atelier/avancement.php` | Les contrôles et le visa posés en validant |
| `src/dashboard/includes/lecture/ecriture_visa.php`, `planFinalEcritureVisa()` dans `plan_final.php` | L'écriture du visa : ses mesures, lues dans la police, et son embarquement dans le PDF |
