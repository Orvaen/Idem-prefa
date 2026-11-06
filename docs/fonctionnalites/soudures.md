# Soudures

Chaque plan ISO porte, imprimé, le **tableau de ses soudures**. Le soudeur le remplit soudure par
soudure ; l'encadrement le suit et le valide.

L'application dit « soudure » partout, jamais « cordon » : c'est le mot de la liste soudure
imprimée sur le plan. Le code, lui, garde « cordon » dans ses noms (`plan-cordon`,
`avancementCordonsAValider()`…).

## Les soudures dans « Mes plans » — le personnel d'atelier

**Écran :** « Mes plans » — personnel d'atelier ([l'écran](/fonctionnalites/atelier#mes-plans-le-personnel-d-atelier)).
« Mes soudures » y a été réuni le 6 novembre 2026 : il n'a plus d'entrée dans le menu, et son
ancienne adresse ouvre « Mes plans ».

Chaque plan confié est une ligne, précédée d'une flèche : **la toucher déplie le plan sur ses
feuilles**, en vignettes — le menu qui se déplie —, et un autre toucher le replie. Un plan seul
arrive déplié. En haut, « Actualiser » et « Signaler un manque », la recherche, puis une phrase :
« Touchez un plan pour voir ses feuilles, puis la feuille que vous avez en main ».

1. **Toucher la feuille** — une **carte** par page de cahier ISO, dans l'ordre du cahier : la
   **vignette de son dessin** — on reconnaît sa feuille d'un coup d'œil —, son **numéro de
   ligne** (« YU-PRE-101 »), et ses soudures faites sur prévues, « 3 / 5 », sur une barre —
   **azur** en cours, **verte avec une coche** sur le dessin quand elle est finie. L'état en
   toutes lettres n'est lu que par les lecteurs d'écran. Toucher une carte affiche aussitôt
   « Ouverture… » sur sa vignette, puis **ouvre son dessin**, à sa page ; la carte reste marquée
   en refermant. Si le dessin ne peut pas s'ouvrir, un message le dit — la carte ne reste jamais
   sans réponse. La recherche de « Mes plans » trouve aussi un numéro de ligne : le plan qui
   porte la feuille se déplie, et seules les feuilles qui répondent restent ; la vider rend les
   plans comme on les avait laissés. Un plan confié sans feuille le dit sous sa ligne : c'est au
   chef d'atelier de les ajouter. Rouverte, une feuille déjà vue revient aussitôt telle qu'on
   l'a laissée, et se relit sans bruit derrière ; une autre reste blanche le temps que son image
   arrive, plutôt que de montrer la précédente. Rien n'est ouvert d'office : on arrive avec son
   papier en main, le logiciel ne devine pas.

   Refait trois fois le 6 novembre 2026 : des plans repliés au-dessus d'un tableau à colonnes
   (« je vais dans soudures, je vois un plan ? ») ; des cartes de cinq lignes sans plan (« trop
   de détails, et je ne sais pas à quel plan c'est relié ») ; des plans en titre, toujours
   ouverts. Le client voulait garder le menu qui se déplie, et tout réunir dans « Mes plans ».
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

**La coulée d'une ligne.** Dans la **NOMENCLATURE DE MATÉRIEL** du dessin, la dernière case
de chaque ligne — « N° COULÉE », soulignée d'un trait — se touche à part : elle ouvre le
**choix de la coulée** de la ligne, parmi celles lues sur les certificats CCPU de la demande
(un toucher suffit ; « à vérifier » marque une coulée lue une seule fois), ou un numéro tapé.
« Retirer la coulée » l'efface. Le numéro s'écrit dans la case, et l'encadrement le voit dans
sa visionneuse. Sur une page qui n'a encore aucune case, une case est posée pour chaque ligne —
comme la pose d'office de l'encadrement. Le reste de la ligne signale un manque (plus bas).

La fenêtre tient avec beaucoup de coulées — une demande en compte vite trente ou cinquante :

| | |
| --- | --- |
| Toucher, ou taper | Deux gestes, deux endroits : on **touche** une coulée de la liste ; sinon, en bas, **« Pas dans la liste ? Tapez le numéro »** et son « Enregistrer » gardent ce qui est tapé, **mot pour mot** — jamais une coulée qui lui ressemble. Un seul champ faisait les deux (« Chercher dans la liste, ou taper le numéro ») et se comprenait mal, la liste étant déjà sous les yeux. |
| La recherche, en haut | Seulement pour une longue liste (plus de douze) : un champ à loupe qui **ne fait que chercher** — quelques chiffres tapés, la liste se réduit aux coulées qui les contiennent (« 2 sur 26 »), sans tenir compte des espaces ni des tirets. Une seule trouvée : Entrée la choisit. Aucune : ce qui est tapé descend dans le champ du bas, prêt à enregistrer. Sur tablette, le clavier ne s'ouvre pas de lui-même ; à la souris, la recherche a la main. |
| Par certificat | Les coulées se rangent sous leur certificat : son numéro (ou le nom du fichier), ses dimensions et sa nuance, à comparer à la désignation de la ligne, écrite sous le titre avec la coulée actuelle. |
| Déjà sur cette feuille | En tête quand la liste est longue (plus de douze), ou qu'une coulée de la feuille n'est sur aucun certificat — un numéro tapé : le même tube revient d'une ligne à l'autre. |
| La hauteur | Seule la liste défile ; la recherche, le champ du bas et les boutons restent en place, et la fenêtre ne rétrécit pas pendant qu'on cherche. |

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

Le tableau **« Matériel manquant »**, sous les feuilles, suit ses manques : ceux en cours, et ceux traités depuis moins
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
  onglets, les soudures relevées sur le total prévu, et les plans à valider.
- **« Examiner »** — ou un clic n'importe où sur la ligne — ouvre le plan :
  - **trois chiffres** côte à côte : l'avancement validé, les soudures relevées sur le total
    prévu, qui y travaille ;
  - s'il a des soudures à valider, **une ligne** « N soudures à valider · Valider → », qui
    ouvre [Validation des soudures](#validation) sur ce plan ;
  - **« Toutes les soudures relevées »**, en lecture — numéro, soudeur, procédé, date, RT, VT,
    PT, visa —, rangées par ligne ISO comme les contrôles. Repliée quand il y a quelque
    chose « À valider » : elle redonnait juste dessous les mêmes soudures que les contrôles. Ouverte
    sinon.

## Validation des soudures — la décision de l'encadrement {#validation}

**Écran :** « Validation des soudures », section « Atelier » — chargé de production et chef
d'atelier. Une **pastille** sur son entrée compte les plans qui attendent ; elle baisse à
chaque décision, sans recharger.

Demandé le 6 novembre 2026 : « la validation de soudure dans un autre onglet ». Elle était
répétée à trois endroits — la fiche d'une demande, le volet « Plans & équipes », la fenêtre
d'un plan dans « Soudures » —, au milieu de ce qu'on y venait faire. Elle a son écran, et ces
trois endroits n'en gardent qu'**une ligne** : « N soudures à valider · Valider → », qui ouvre
cet écran sur le bon plan.

**Une file, pas une liste** — refait le même jour : « je déteste l'interface ». C'était la liste
de « Soudures » trait pour trait : un onglet « À valider » seul, qu'on ne pouvait pas changer ; un
tableau ; un bouton « Valider » qui ne validait rien et ouvrait une grande fenêtre, où le plan se
redisait deux fois — en titre, puis en trois chiffres — avant les contrôles ; et, validé, on
refermait, on revenait à la liste et on rouvrait le plan suivant.

- **À gauche, la file** : « 3 plans · 12 soudures à viser », puis un plan par ligne — son nom
  (l'éclair s'il est urgent) et ce qu'il a de soudures à valider en pastille, sa description, la
  demande, qui y travaille et le jour de la déclaration. **L'urgent d'abord, puis ce qui attend
  depuis le plus longtemps** ; les soudures relevées sans déclaration ferment la marche. La
  recherche, en tête, porte sur le plan, la demande, l'affaire et les personnes. Les **flèches**
  passent d'un plan à l'autre, Début et Fin vont aux bouts.
- **À droite, le plan qu'on vise** — le premier de la file à l'arrivée : son nom, la demande,
  l'affaire et l'atelier, une fois, en tête, qui reste en vue ; puis **ses soudures tout de suite**.
- Dedans, **ce qui attend** :
  - une **déclaration** de l'atelier qui vise des soudures, à valider ou refuser, dite en une
    ligne — « Karim Belkacem a déclaré 5 soudures sur 69 · 06/11/2026 à 09:12 » — et la **note de
    l'atelier** s'il en a laissé une ;
  - des soudures relevées **sans déclaration** — enregistrées avant que la liste du plan soit
    lue, ou avant que le suivi soudage s'ouvre tout seul — : « Valider » pose le chiffre calculé.
    Sans suivi soudage, le chef n'a **rien à affecter** avant : « Valider » l'ouvre au nom du
    soudeur, déjà affecté au plan (un formulaire par soudeur s'ils sont plusieurs, « Valider 2
    soudures de Sophie Durand »). Seul cas sans bouton : plus personne de l'atelier n'est affecté
    au plan — la décision le dit, et la file ne lui met pas de pastille.
- **Un seul bouton engage**, au pied, et il dit ce qu'il vise : **« Valider 5 soudures »**. Le pied
  reste collé au bas de l'écran pendant qu'on coche ; l'avancement que la validation pose y est
  dit en sourd, à gauche — « Avancement 3 % → 10 % » : le chef vise des soudures, le pourcentage
  n'en est que la suite.
- Validé ou refusé, un message le dit (« Soudures validées : 5 sur 69. »), le plan **quitte la
  file** et **le suivant s'ouvre** ; après le dernier, le précédent. Plus rien : « Rien à valider
  pour l'instant ». « Actualiser » garde le plan ouvert et la recherche.
- **Sous 851 px**, une colonne à la fois : la file, puis le plan qu'on touche, avec « ‹ Plans à
  valider » pour revenir.
- **Voir une soudure sur le plan** : dans les contrôles, « N° 1 » et le nom de sa feuille portent un
  œil et se touchent. La fenêtre du plan s'ouvre sur la bonne feuille, la soudure **cerclée** sur le
  dessin — son numéro le long du tube, et sa ligne dans la liste imprimée —, la vue centrée dessus.
  « N° 1 » seul ne disait pas où elle était (6 novembre 2026).

Ce qui ne vise **aucune soudure** — un avancement aux étapes — n'y vient pas : il se tranche là
où il est, dans la fiche et dans « Plans & équipes », d'un clic, sans contrôle ni signature.

### Les contrôles, posés en validant

Chaque « Valider » qui fait passer des soudures sur le plan les montre d'abord : celles du
soudeur concerné, saisies depuis sa dernière validation. Un **tableau**, une ligne par soudure —
son numéro, son procédé et sa date —, et ses trois contrôles **RT**, **VT**, **PT** en **cases**
qu'on touche. Une case touchée passe au **vert, avec sa coche** : c'est une **croix** sur le plan,
le contrôle est OK. Une case laissée n'écrit rien. S'il y a plusieurs soudures, **RT, VT et PT en
tête de colonne** cochent la colonne entière ; décocher une soudure décoche l'en-tête.

Les soudures se rangent sous leur **ligne ISO** — le numéro de ligne que le cahier porte pour la
page (« YU-PRE-101 »), sinon le nom de la feuille —, avec la page quand la feuille en a
plusieurs : les numéros 1, 2, 3 reviennent d'une page à l'autre, l'en-tête dit de laquelle on
parle. Sur tablette, les cases sont à la taille du doigt (44 px).

| Règle | Détail |
| --- | --- |
| Où | Sur cet écran seulement (`validationSouduresDecisionRendu()`). Ailleurs, la décision garde le dessin des volets (`includes/atelier/decision.php`) pour ce qui ne vise aucune soudure. |
| Refuser | Ne pose rien. |
| Une liste périmée | Une soudure saisie entre l'affichage et le clic fait refuser la validation (`409`) : on ne valide pas une soudure qu'on n'a pas vue. |
| Les soudures déjà validées | N'y reviennent pas. Leurs contrôles se corrigent dans la visionneuse. |
| La saisie d'un pourcentage | La fenêtre « Saisir l'avancement » de Plans / ISO ne montre pas les contrôles : c'est une reprise en main du chiffre, pas une validation de soudures. |

« VT » et non « VI » : c'est l'en-tête que le bureau d'études imprime sur ses listes soudure. La
colonne garde son nom en base, `plan_soudure.vi`.

### Le visa, posé en validant {#visa}

Valider des soudures, c'est les **viser**. La case **VISA** de chacun reçoit la **signature de
celui qui valide**, sur le plan final comme dans la fenêtre du plan.

**Pas de signature, pas de visa** — décidé le 6 novembre 2026 : un nom écrit par l'application
ne vaut pas signature.

- Qui valide des soudures — chef d'atelier, chargé de production — et n'en a pas la trace **dès
  son arrivée** : la grande fenêtre de signature s'ouvre sur le tableau de bord, sans croix ni
  « Annuler », avec seulement « Se déconnecter », et ne se referme qu'une fois la signature
  validée. Échap ne la ferme pas.
- Si l'on tombe quand même sur une décision sans signature — une page restée ouverte, une
  signature réinitialisée entre-temps —, son pied le dit à la place du visa, en couleur
  d'attente : « Il vous faut une signature pour valider », avec **Tracer ma signature**.
  « Valider » reste éteint ; une fois la signature tracée, il se rallume sans recharger, et le
  pied dit « Valider les vise de votre signature. ».
- Le serveur refuse de toute façon une validation qui viserait des soudures sans signature
  (409, `avancementViserCordons()`). « Refuser » reste possible : il ne vise rien.

| Règle | Détail |
| --- | --- |
| Qui vise | Celui qui valide : chef d'atelier ou chargé de production. |
| Ce qui est rangé | `plan_soudure.vise_par` et `date_visa` : qui, et quand ; `plan_soudure.id_signature` : la signature posée ce jour-là. |
| Les visas posés au nom | Ceux d'avant les signatures gardent le **prénom et le nom** de leur auteur, écrits dans une écriture manuscrite, jusqu'à ce qu'il en trace une : elle les remplace alors. La validation dit combien de soudures elle vient de signer. |
| Validée une fois | Tracée et validée, la signature ne se change plus de soi-même : une signature qu'on referait à sa guise ne vaudrait pas visa. Seul le **chargé de production** la réinitialise, depuis « Utilisateurs » ([le détail](/fonctionnalites/utilisateurs#la-signature)) ; la personne en trace alors une nouvelle, qu'elle valide à son tour. Lui garde la main sur la sienne : il la refait ou la supprime dans ses paramètres. |
| Une signature remplacée | Les soudures déjà signées gardent celle du jour : comme sur le papier, changer de signature ne retouche pas ce qui est signé. Seuls les visas suivants prennent la nouvelle. |
| Une signature retirée | Réinitialisée, ou supprimée par le chargé de production pour la sienne : plus de validation de soudures jusqu'à la nouvelle, que la fenêtre demande dès l'arrivée suivante. Les soudures déjà visées gardent leur signature ; une signature qui ne vise aucune soudure est effacée. |
| Sans la migration | Avant `2026-10-29_signatures`, il n'y a pas où ranger une signature : les visas restent au nom, et rien n'est bloqué. |
| Refuser | Ne vise rien. |
| Les soudures validées autrement | « Saisir l'avancement » et les étapes couvrent des soudures sans les viser : leur case VISA reste vide. |
| Sur le plan final | La signature est faite de **traits** du PDF, le nom de **texte** : ni l'un ni l'autre n'est une image, tous deux restent nets à tous les zooms. Le visa occupe la ligne à une fine marge près, et rétrécit pour tenir dans la case. |

Dans les paramètres, la signature s'affiche comme au bas d'un document : au-dessus d'un trait,
le nom et « Validée le … » dessous, et en dessous du cadre « Pour la changer, demandez à un
administrateur. » — le chargé de production, lui, a « Refaire » et « Supprimer » au survol.
Elle se trace **en grand**, dans sa fenêtre à elle — demandé le 6 novembre 2026 : le cadre des
paramètres faisait quatre centimètres de haut. Tout l'écran sur une tablette ou un téléphone,
une grande fenêtre sur un écran de bureau ; le cadre y prend toute la place, sans être plus haut
qu'une fois et demie sa largeur. Il garde le dessin du bloc : « Signez ici » au milieu, le trait,
le nom et « Aujourd'hui » dessous, et dans son coin de quoi retirer le dernier trait ou tout
**Effacer** ([signature_pad](https://github.com/szimek/signature_pad), chargé à la première
ouverture ; s'il ne vient pas, toucher le cadre retente). On signe à la souris, au doigt ou au
stylet. « Valider ma signature » reste éteint tant que le cadre est vide ; Annuler, la croix ou
Échap referment sans rien garder.

La fenêtre s'ouvre de **Tracer ma signature** (la zone à pointillés des paramètres, l'outil
« Refaire »), du pied d'une décision, et d'elle-même à l'arrivée pour qui doit la tracer. La règle
se lit avant : sous le titre (« Une fois validée, seul un administrateur pourra la changer »),
puis dans la confirmation que « Valider ma signature » demande. Elle est rangée en points,
recadrée sur l'encre (`signature_utilisateur.dessin`), et se redessine lissée, dans la couleur du
texte qui l'entoure. Avant la migration `2026-10-29_signatures`, la section le dit et les visas
restent au nom.

L'écriture du nom est **Great Vibes** (licence OFL, `src/assets/fonts/`), réduite aux caractères latins
d'Europe de l'Ouest : 36 Ko en `.ttf`, que le plan final **embarque une fois** pour tout le
document — il s'affiche et s'imprime pareil partout, sans dépendre des polices du poste —, et
20 Ko en `.woff2` pour l'écran. Les deux sont produits par fontTools, sans crénage ni ligature :
le PDF écrit lettre après lettre, et l'écran doit montrer la même chose. Changer d'écriture,
c'est remplacer ces deux fichiers (voir l'en-tête de `ecriture_visa.php`).

## La visionneuse

Dans « Plans & équipes », la visionneuse plein écran d'une feuille porte deux choses
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
| `src/dashboard/pages/atelier.php`, `src/js/ecrans/soudures.js` | Les soudures de « Mes plans » : le plan qui se déplie sur ses feuilles, les lignes et les numéros cliquables sur le dessin, la fenêtre d'une soudure, la fenêtre « Signaler un manque » |
| `src/dashboard/includes/lecture/plan_reperes.php`, `soudureAtelierDessin()` | Où chaque numéro de soudure est dessiné, et la géométrie de la liste soudure de la page |
| `src/dashboard/pages/suivi_soudures.php`, `src/js/ecrans/suivi-soudures.js` | Le suivi de l'encadrement |
| `src/dashboard/includes/atelier/soudure_atelier.php` | Les règles de saisie du soudeur |
| `src/dashboard/actions/soudures_feuille.php`, `save_soudures_atelier.php` | La feuille et son enregistrement — personnel affecté au plan |
| `src/dashboard/actions/save_soudure.php`, `save_nomenclature.php` | La liste des soudures et les cases de coulée — encadrement |
| `src/dashboard/actions/coulee_soudeur.php`, `nomenclatureCouleeDeLigne()` | La coulée d'une ligne de nomenclature, choisie par le soudeur — personnel affecté au plan |
| `src/dashboard/actions/detecter_lignes.php` | La reconnaissance du tableau sur le PDF |
| `src/dashboard/actions/download_plan_final.php` | Le plan final |
| `planFinalParDemandes()` dans `src/dashboard/includes/atelier/plan_document.php`, `src/dashboard/partials/prefa_list_row.php` | Le sous-menu « Plan final » de la liste des demandes |
| `src/dashboard/actions/plan_final_lecteur.php`, `ouvrirLecteurPlanFinal()` dans `src/js/ecrans/prefa-interactions.js` | La visionneuse en consultation, servie à la demande pour le sous-menu |
| `src/js/ecrans/plan-viewer.js` | La visionneuse et ses cases ; elle annonce chaque page affichée (`plan:page`), où les soudures de « Mes plans » posent leurs lignes et leurs numéros, et lui prête `planEcrireEnAttente()` et `planRelireSoudures()` |
| `avancementViserCordons()` dans `src/dashboard/includes/atelier/avancement.php` | Les contrôles et le visa posés en validant |
| `src/dashboard/includes/atelier/decision.php`, `soudureControlesRendu()` dans `soudure.php` | La décision « À valider » des volets — fiche d'une demande, « Plans & équipes » — et ses contrôles en lignes |
| `soudureFeuille()` dans `src/dashboard/includes/atelier/soudure.php` | La ligne ISO d'une soudure, sous laquelle les tableaux la rangent |
| `src/dashboard/pages/validation_soudures.php`, `src/dashboard/includes/atelier/validation_soudures.php` | « Validation des soudures » : la file des plans et le plan qu'on vise ; les décisions de chaque plan, en données (`validationSouduresPlan()`), lues comme dans « Soudures » (`soudureSuiviLignes()`), et leur dessin — la décision, son formulaire, le tableau des contrôles (`validationSouduresDecisionRendu()`, `validationSouduresFormulaire()`, `validationSouduresControles()`) ; la pastille du menu (`validationSouduresCompte()`) ; la ligne qui y renvoie (`validationSouduresRenvoi()`) |
| `src/js/ecrans/validation-soudures.js`, `src/css/dashboard/validation-soudures.css` | La file : choisir un plan, les flèches, la recherche, le plan suivant après une décision, la colonne montrée sous 851 px ; `window.validationSouduresOuvrir()`, qu'appellent les renvois (`prefa-interactions.js`) |
| `src/dashboard/includes/atelier/signature.php`, `src/dashboard/actions/ma_signature.php` | La signature de chacun : la vérifier, la ranger, la poser en visa, la tracer en SVG — et qui peut la changer, une fois validée (`signatureModifiable()`) |
| `src/dashboard/actions/reinitialiser_signature.php` | Sa réinitialisation par le chargé de production, depuis « Utilisateurs » |
| `planFinalSignature()` dans `plan_final.php`, `dessinerSignature()` dans `src/js/ecrans/plan-viewer.js` | La signature dans la case VISA, sur le plan final et dans la fenêtre du plan |
| `src/dashboard/includes/lecture/ecriture_visa.php`, `planFinalEcritureVisa()` dans `plan_final.php` | L'écriture du visa : ses mesures, lues dans la police, et son embarquement dans le PDF |
