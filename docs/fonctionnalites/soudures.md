# Soudures

Chaque plan ISO porte, imprimé, le **tableau de ses soudures**. Le soudeur le remplit soudure par
soudure ; l'encadrement le suit et le valide.

L'application dit « soudure » partout, jamais « cordon » : c'est le mot de la liste soudure
imprimée sur le plan. Le code, lui, garde « cordon » dans ses noms (`plan-cordon`,
`avancementCordonsAValider()`…).

## Mes soudures — le personnel d'atelier

**Écran :** « Mes soudures & Suivi matériel », section « Atelier » — personnel d'atelier.

L'écran a la forme des autres listes : en haut, la densité des lignes, « Actualiser » et
« Signaler un manque » ; dessous, la recherche ; puis les onglets d'état et le tableau des
feuilles, **rangées sous leur plan**.

1. **Trouver la feuille** — tous les plans confiés sont là, **repliés** : une ligne par plan,
   la ligne de groupe des autres listes, avec une flèche devant son nom, son nombre de
   feuilles, sa demande et son affaire, et dans la colonne des soudures, celles du plan entier.
   Un clic sur la ligne — ou Entrée sur sa flèche — **déroule ses feuilles** dessous, un autre
   les replie. La ligne s'éclaire au survol ; ses feuilles entrent l'une après l'autre, et ce qui
   est dessous glisse au lieu de sauter. Rien ne bouge quand le système demande moins
   d'animations, ni quand c'est la recherche qui déroule. Une ligne par page du cahier ISO : le point de son état, son numéro de ligne, sa
   page, ses soudures faites sur les soudures prévues au plan avec une barre, et
   « Renseigner ». Les **onglets** — Toutes, À faire, En cours, Soudées, et Pas encore lues ou
   Sans liste quand il y en a — filtrent par état, et leurs points sont la légende de ceux des
   lignes : **cercle vide** à faire, **azur** en cours, **vert** soudée. À droite des onglets,
   les soudures faites de tous les plans sur celles qu'ils prévoient. La recherche trouve un
   numéro de ligne, un plan, une demande ou une affaire, dans tous les plans à la fois : on
   cherche la feuille qu'on a en main sans savoir de quel plan elle est. Une recherche ou un
   onglet **déroule de lui-même** les plans où il trouve des feuilles ; les vider rend les plans
   comme on les avait laissés, et « Actualiser » les garde. Un plan sans feuille le dit sous son
   nom, déroulé — c'est au chef d'atelier de les ajouter. Cliquer la ligne d'une feuille — ou
   « Renseigner » — **ouvre son dessin**, à sa page. Rouverte, une feuille déjà vue revient
   aussitôt telle qu'on l'a laissée, et se relit sans bruit derrière ; une autre reste blanche
   le temps que son image arrive, plutôt que de montrer la précédente. Rien n'est déroulé ni
   ouvert d'office : on arrive avec son papier en main, le logiciel ne devine pas.
2. **Cliquer une ligne de la LISTE SOUDURE, sur le dessin** — le tableau imprimé du plan, comme
   on le remplirait sur le tirage. Le numéro de la soudure le long du tube se clique aussi :
   c'est la même soudure, et la ligne et le numéro s'allument ensemble. La **fenêtre de la
   soudure** s'ouvre par-dessus le dessin — celle de la maison, comme « Signaler un manque » :
   la **date**, déjà au jour, et le **procédé**, choisi dans la liste (TIG, MIG, MAG, 111, 135,
   141) ou écrit dans « Autre procédé… » ; celui de la soudure précédente est déjà proposé, et
   il ne reste qu'à enregistrer. « Enregistrer » envoie cette seule soudure, qui s'écrit aussitôt
   dans sa ligne ; « Effacer » la remet à faire ; Échap ou « Annuler » referment la fenêtre, et
   le dessin reste ouvert pour la soudure suivante. Le soudeur, c'est toujours soi : le serveur
   pose son nom.

Chaque ligne porte un point, et chaque numéro un cadre, de la couleur qui dit où en est la
soudure : **azur** à faire, **ambre** renseignée et en attente du chef, **vert** validée,
**gris** soudée par quelqu'un d'autre ; la fenêtre de la soudure le redit en pastille. Une
**légende**, sous la consigne de la fenêtre du plan, rappelle les couleurs : les trois de la
liste soudure toujours, le gris et le rouge d'un manque signalé quand la page en porte. Ce qui
attend le chef s'écrit dans sa ligne en ambre ; le validé s'y écrit comme sur le plan final, qui
ne porte que lui. Le haut de la fenêtre du plan ne dit que ce qu'on y fait : rien ne s'y annonce
après un enregistrement, la ligne qui change suffit. « Signaler un manque » est dans sa barre,
avec les autres commandes, et **« Imprimer »** y remplace « Télécharger le PDF » : la feuille
affichée ou tout le cahier — la fenêtre de choix de la maison le demande quand il en porte
plusieurs —, une feuille par page de papier, à sa taille et dans son sens. Le soudeur vient chercher sa feuille
sur papier, pas un fichier — et le plan final que le lien téléchargeait refuse tant que rien
n'y est validé. Le chef garde « Télécharger le PDF ». Les soudures qu'aucune ligne ne porte — ajoutées hors de la liste
imprimée, ou toute une page dont la liste n'a pas été reconnue (un scan) — s'affichent sous le
titre, et s'y renseignent de la même façon.

| Règle | Détail |
| --- | --- |
| D'où vient la liste | Du dessin : le tableau de soudures est **reconnu sur le PDF**, page par page. Le dénominateur est celui du plan, pas un compte de ce qu'on a saisi. |
| Où sont les numéros | Reconnus sur l'image du dessin — leur rectangle, puis leur chiffre, comparé à la colonne N° de la liste ([Lecture automatique](/fonctionnalites/lecture-automatique#les-numeros-des-soudures-sur-le-dessin)). Une à deux secondes à la première ouverture d'une page, puis c'est gardé. |
| La fenêtre du plan | Rendue à la première feuille qu'on ouvre de ce plan (`actions/soudures_lecteur.php`), et préparée dès que le pointeur passe sur l'une d'elles : l'écran montre tous les plans, il ne construit pas d'avance des fenêtres qu'on n'ouvrira pas. Le compte des soudures de chaque feuille vient du repère de l'indexation, un fichier par cahier ([Lecture automatique](/fonctionnalites/lecture-automatique#tout-est-lu-au-depot-en-arriere-plan)). |
| « Signaler un manque » depuis l'en-tête | Le plan se choisit — l'écran les montre tous et n'en devine pas un —, sauf s'il n'y en a qu'un. Depuis le dessin, c'est le plan ouvert. |
| Effacer une soudure | La remet « à faire ». |
| Les soudures d'un autre soudeur | Verrouillées : leur fenêtre se lit, sans se reprendre. |
| Une feuille close | Tout est soudé et validé : les fenêtres se lisent, sans se reprendre. |
| Les contrôles RT, VT, PT et le visa | Réservés à l'encadrement : ils ne s'affichent pas ici. |
| L'avancement | Enregistrer ses soudures **déclare** l'avancement du suivi soudage, et le chef d'atelier valide. Il suffit d'être affecté au plan, à n'importe quel travail : si le plan n'a pas encore de suivi soudage, il s'ouvre au nom du soudeur, « affecté par » celui qui lui avait confié le plan. Le chiffre des soudures ne va jamais sur le Plan / ISO, qui se compte en étapes. |

Ouvrir une feuille déclenche la reconnaissance du tableau et des numéros sur le PDF : quelques
secondes la première fois. C'est une des raisons pour lesquelles rien n'est choisi d'office.

### Signaler un manque

Depuis le même écran, « Signaler un manque » ouvre une fenêtre : le plan, l'article — tiré du bon
de commande de la demande, ou saisi librement —, la quantité et l'unité, le motif (oubli, erreur
de livraison, casse ou rebut, autre) et une précision. Le chef d'atelier et le chargé de
production sont prévenus aussitôt, nommément.

**Depuis le dessin**, on clique la ligne de la **NOMENCLATURE DE MATÉRIEL** du matériel qui
manque, comme on la montrerait sur le tirage. La même fenêtre s'ouvre avec :
- l'**article** de la ligne, tel que le plan l'imprime, avec son DN et son épaisseur —
  « BRIDE TOURNANTE EMBOUTIE - 316L DN200 » —, écrit dans « Autre article… » ;
- la **quantité du plan en invite** — « Quantité (1 au plan) » —, mais pas dans le champ : il
  n'en manque peut-être qu'une partie, et c'est au soudeur de le dire ;
- le **repère et la feuille** en précision — « Repère 5 · YU-PRE-107B ».

Le motif reste à choisir. Une ligne dont l'article a un manque ouvert — signalé par n'importe
qui, pas encore traité — est teintée de rouge, avec un point à sa gauche, et son infobulle dit
depuis quand. C'est le rouge des manques à traiter de « Commande matériel » ; l'ambre est
celui des soudures en attente du chef. La ligne reconnaît son manque à son article : un article réécrit dans la fenêtre
ne marque plus la ligne.

La nomenclature est lue sur le PDF ([Lecture automatique](/fonctionnalites/lecture-automatique#la-nomenclature-sur-le-dessin)),
dès le dépôt du plan, en arrière-plan, avec le reste de la feuille. Ce qui est lu est une
proposition, corrigeable dans la fenêtre avant d'envoyer.

L'**unité** se choisit dans la liste que tient le chargé de production (Paramètres → Matériel) :
on tape pour chercher, on choisit — comme l'article. Un article du bon apporte la sienne si la
liste la connaît, sans égard aux majuscules (« pce » retient « PCE »). Le serveur refuse une unité
hors de la liste. Tant que la liste n'existe pas sur un serveur (migration
`2026-10-29_unites_materiel` pas encore passée), l'unité reste un champ libre.

Le tableau **« Matériel signalé »** suit ses manques : ceux en cours, et ceux traités depuis moins
de trente jours. Voir [Commande matériel](/fonctionnalites/commande-materiel#les-manques).

## Soudures — le suivi de l'encadrement

**Écran :** « Soudures », section « Atelier » — chargé de production et chef d'atelier.

L'ossature des autres listes : la densité et « Actualiser » en tête, la recherche, puis un
onglet par état avec son compte.

- **Les plans** qui ont des soudures relevées ou une liste de soudures reconnue, ceux qui
  attendent une décision en tête. Chaque ligne s'ouvre par le **point de son état** — les
  onglets en sont la légende : À valider (couleur de l'attente), En cours (accent), Pas
  commencé (cercle vide), Toutes soudées (vert).
- **La ligne** : le plan et sa description, la demande, l'affaire, qui y travaille (au
  monogramme), les soudures « x / y » sur une barre — pleine pour ce qui est validé, pâle
  pour ce qui est relevé et attend encore —, et l'avancement validé, suivi de « → y % »
  quand une déclaration du suivi soudage l'attend.
- **La recherche** porte sur le plan, la demande, l'affaire et les personnes ; à droite des
  onglets, les soudures relevées sur le total prévu, et les avancements à valider.
- **« Examiner »** — ou un clic n'importe où sur la ligne — ouvre le plan :
  - les **déclarations** à valider ou refuser ;
  - des soudures relevées **sans déclaration** — enregistrées avant que la liste du plan soit
    lue, ou avant que le suivi soudage s'ouvre tout seul — : un bouton « Valider » pose le
    chiffre calculé. Sans suivi soudage, le chef n'a **rien à affecter** avant : « Valider »
    l'ouvre au nom du soudeur, déjà affecté au plan (un bouton par soudeur s'ils sont
    plusieurs). Seul cas sans bouton : plus personne de l'atelier n'est affecté au plan ;
  - le tableau des soudures — numéro, feuille, soudeur, date, type, RT, VT, PT, visa —, en
    lecture.

### Les contrôles, posés en validant

Chaque « Valider » qui fait passer des soudures sur le plan les montre d'abord : celles du
soudeur concerné, saisies depuis sa dernière validation. Une ligne par soudure, une case par
contrôle — **RT**, **VT**, **PT** — et, s'il y en a plusieurs, une ligne « Toutes les soudures »
qui coche une colonne entière. Une case cochée est une **croix** sur le plan : le contrôle est OK. Une case
vide n'écrit rien.

| Règle | Détail |
| --- | --- |
| Où | La carte « À valider » — la même partout (`includes/atelier/decision.php`) : volet Soudures, fiche d'une demande, Plans / ISO, et « Soudures relevées, pas encore validées ». |
| Refuser | Ne pose rien. |
| Une liste périmée | Une soudure saisie entre l'affichage et le clic fait refuser la validation (`409`) : on ne valide pas une soudure qu'on n'a pas vue. |
| Les soudures déjà validées | N'y reviennent pas. Leurs contrôles se corrigent dans la visionneuse. |
| La saisie d'un pourcentage | La fenêtre « Saisir l'avancement » de Plans / ISO ne montre pas les contrôles : c'est une reprise en main du chiffre, pas une validation de soudures. |

« VT » et non « VI » : c'est l'en-tête que le bureau d'études imprime sur ses listes soudure. La
colonne garde son nom en base, `plan_soudure.vi`.

### Le visa, posé en validant {#visa}

Valider des soudures, c'est les **viser**. La case **VISA** de chacun reçoit la **signature de
celui qui valide** — tracée une fois dans ses paramètres (rubrique **Compte**, section
**Signature**) —, sur le plan final comme dans la fenêtre du plan. Qui n'en a pas tracé vise de
son **prénom et son nom**, tirés de son compte, écrits dans une écriture manuscrite — jusqu'à ce
qu'il en trace une : elle remplace alors son nom sur ces soudures. Le bloc des contrôles le dit
avant le clic : « Valider » les vise de votre signature, ou à votre nom — avec alors un lien pour
tracer la sienne.

| Règle | Détail |
| --- | --- |
| Qui vise | Celui qui valide : chef d'atelier ou chargé de production. |
| Ce qui est rangé | `plan_soudure.vise_par` et `date_visa` : qui, et quand ; `plan_soudure.id_signature` : la signature posée ce jour-là, s'il en avait une. Le nom s'écrit à l'affichage, depuis le compte. |
| Les visas posés au nom | Ceux d'avant la signature — ou d'une période sans — prennent la première que la personne trace, puis la gardent. La validation dit combien de soudures elle vient de signer. |
| Validée une fois | Tracée et validée, la signature ne se change plus de soi-même : une signature qu'on referait à sa guise ne vaudrait pas visa. Seul le **chargé de production** la réinitialise, depuis « Utilisateurs » ([le détail](/fonctionnalites/utilisateurs#la-signature)) ; la personne en trace alors une nouvelle, qu'elle valide à son tour. Lui garde la main sur la sienne : il la refait ou la supprime dans ses paramètres. |
| Une signature remplacée | Les soudures déjà signées gardent celle du jour : comme sur le papier, changer de signature ne retouche pas ce qui est signé. Seuls les visas suivants prennent la nouvelle. |
| Une signature retirée | Réinitialisée, ou supprimée par le chargé de production pour la sienne : les visas suivants reprennent le nom jusqu'à la nouvelle. Les soudures déjà visées gardent leur signature ; une signature qui ne vise aucune soudure est effacée. |
| Refuser | Ne vise rien. |
| Les soudures validées autrement | « Saisir l'avancement » et les étapes couvrent des soudures sans les viser : leur case VISA reste vide. |
| Sur le plan final | La signature est faite de **traits** du PDF, le nom de **texte** : ni l'un ni l'autre n'est une image, tous deux restent nets à tous les zooms. Le visa occupe la ligne à une fine marge près, et rétrécit pour tenir dans la case. |

Dans les paramètres, la signature s'affiche comme au bas d'un document : au-dessus d'un trait,
le nom et « Validée le … » dessous, et en dessous du cadre « Pour la changer, demandez à un
administrateur. » — le chargé de production, lui, a « Refaire » et « Supprimer » au survol.
Elle se trace à la souris, au doigt ou au stylet, dans un cadre de la même forme qui prend sa
place — on signe là où elle s'affichera —, avec de quoi défaire le dernier trait ou tout
effacer ([signature_pad](https://github.com/szimek/signature_pad), chargé à l'ouverture du
cadre). « Valider » reste éteint tant que le cadre est vide, et Échap abandonne sans fermer la
fenêtre. La règle se lit avant : sur le cadre vide (« Une fois validée, seul un administrateur
pourra la changer »), puis dans la confirmation que « Valider » demande ; Annuler laisse le
tracé en place. Elle est rangée en points, recadrée sur l'encre (`signature_utilisateur.dessin`), et
se redessine lissée, dans la couleur du texte qui l'entoure. Avant la migration
`2026-10-29_signatures`, la section le dit et les visas restent au nom.

L'écriture du nom est **Great Vibes** (licence OFL, `src/assets/fonts/`), réduite aux caractères latins
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
ses croix et ses visas. N'y figurent que les soudures couvertes par une validation. Il se
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
| `src/dashboard/pages/soudures.php`, `src/js/ecrans/soudures.js` | Mes soudures : les lignes et les numéros cliquables sur le dessin, la fenêtre d'une soudure, la fenêtre « Signaler un manque » |
| `src/dashboard/includes/lecture/plan_reperes.php`, `soudureAtelierDessin()` | Où chaque numéro de soudure est dessiné, et la géométrie de la liste soudure de la page |
| `src/dashboard/pages/suivi_soudures.php`, `src/js/ecrans/suivi-soudures.js` | Le suivi de l'encadrement |
| `src/dashboard/includes/atelier/soudure_atelier.php` | Les règles de saisie du soudeur |
| `src/dashboard/actions/soudures_feuille.php`, `save_soudures_atelier.php` | La feuille et son enregistrement — personnel affecté au plan |
| `src/dashboard/actions/save_soudure.php`, `save_nomenclature.php` | La liste des soudures et les cases de coulée — encadrement |
| `src/dashboard/actions/detecter_lignes.php` | La reconnaissance du tableau sur le PDF |
| `src/dashboard/actions/download_plan_final.php` | Le plan final |
| `planFinalParDemandes()` dans `src/dashboard/includes/atelier/plan_document.php`, `src/dashboard/partials/prefa_list_row.php` | Le sous-menu « Plan final » de la liste des demandes |
| `src/dashboard/actions/plan_final_lecteur.php`, `ouvrirLecteurPlanFinal()` dans `src/js/ecrans/prefa-interactions.js` | La visionneuse en consultation, servie à la demande pour le sous-menu |
| `src/js/ecrans/plan-viewer.js` | La visionneuse et ses cases ; elle annonce chaque page affichée (`plan:page`), où « Mes soudures » pose ses lignes et ses numéros, et lui prête `planEcrireEnAttente()` et `planRelireSoudures()` |
| `avancementViserCordons()` dans `src/dashboard/includes/atelier/avancement.php` | Les contrôles et le visa posés en validant |
| `src/dashboard/includes/atelier/signature.php`, `src/dashboard/actions/ma_signature.php` | La signature de chacun : la vérifier, la ranger, la poser en visa, la tracer en SVG — et qui peut la changer, une fois validée (`signatureModifiable()`) |
| `src/dashboard/actions/reinitialiser_signature.php` | Sa réinitialisation par le chargé de production, depuis « Utilisateurs » |
| `planFinalSignature()` dans `plan_final.php`, `dessinerSignature()` dans `src/js/ecrans/plan-viewer.js` | La signature dans la case VISA, sur le plan final et dans la fenêtre du plan |
| `src/dashboard/includes/lecture/ecriture_visa.php`, `planFinalEcritureVisa()` dans `plan_final.php` | L'écriture du visa : ses mesures, lues dans la police, et son embarquement dans le PDF |
