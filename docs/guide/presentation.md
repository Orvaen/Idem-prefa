# Présentation

IDEM Préfabrication est l'application web qui suit une **demande de préfabrication de
tuyauterie** de bout en bout : depuis le chargé d'affaire qui la dépose jusqu'aux procès-verbaux
qui ferment la fabrication, en passant par la validation, le planning, la répartition des plans
entre les ouvriers, les soudures et la commande de matériel.

Elle tourne sur un serveur de l'atelier, dans des conteneurs Docker, et s'utilise depuis un
navigateur — sur un poste de bureau comme sur une tablette d'atelier.

## Le parcours d'une demande

```
 Chargé d'affaire        Chargé de production        Chef d'atelier            Personnel atelier
 ────────────────        ────────────────────        ──────────────            ─────────────────
 dépose la demande  ──▶  la valide ou la refuse  ──▶  y ajoute les plans  ──▶  avance ses plans,
 dans une affaire        et la planifie               et les répartit          remplit ses soudures
                                                      valide l'avancement ◀──  déclare l'avancement
                                                      remplit les PV
 suit l'avancement  ◀──────────────────── tout le monde voit ce qui le concerne ─────────────────
 et consulte les PV
```

1. **Une affaire** (un chantier) est ouverte par le chargé de production, qui la confie à un
   chargé d'affaire.
2. **Le chargé d'affaire dépose une demande** dans cette affaire : pouces ISO, échéances,
   matière, passivation, revêtement, contrôles, urgence, plans et pièces jointes. Elle reçoit une
   référence du type `26-DP-0001`.
3. **Le chargé de production la valide** — elle prend aussitôt sa place dans le planning —
   **ou la refuse**, avec un motif.
4. **Une demande validée est ouverte à l'atelier** : le chef d'atelier y ajoute ses plans ISO et
   les répartit entre le personnel, par type de travail — plan, montage, suivi soudage.
5. **Le personnel déclare son avancement** : les étapes du plan, et les soudures cordon par
   cordon. **Le chef d'atelier valide** ces déclarations ; lui seul fait bouger l'avancement.
6. **Les PV** — réception matériel, fin de fabrication, passivation — documentent la
   fabrication, photos et pièces à l'appui. Le chargé d'affaire les consulte.

À côté, la **commande matériel** suit les bons de commande fournisseur et leurs réceptions, et
l'atelier y **signale les manques**. Les certificats matière (CCPU) et les bons de commande
déposés sont **lus automatiquement**, sur le serveur.

## Pour qui

| Rôle | Ce qu'il y fait, en une ligne |
| --- | --- |
| **Chargé de production** | Ouvre les affaires, valide et planifie les demandes, administre les comptes et les paramètres. |
| **Chef d'atelier** | Prépare les plans des demandes validées et les affecte, valide l'avancement et les soudures, remplit les PV, suit la commande matériel. |
| **Personnel atelier** | Avance les plans et remplit les soudures qui lui sont confiés, signale un manque de matériel. |
| **Chargé d'affaire** | Dépose et modifie ses demandes, suit leur avancement et consulte leurs PV. |

Le détail, écran par écran, est dans [Rôles et droits](/fonctionnalites/roles).

## Le vocabulaire

| Terme | Ce que c'est |
| --- | --- |
| **Affaire** | Un chantier. Chaque demande lui est rattachée, et un chargé d'affaire la suit. |
| **Demande (de préfabrication)** | Ce qu'un chargé d'affaire commande à l'atelier. Sa référence suit le format `AA-DP-NNNN` : l'année de création, puis un compteur annuel. |
| **Plan ISO, feuille** | Le dessin isométrique d'une ligne de tuyauterie. Un cahier PDF porte souvent plusieurs feuilles. |
| **Élément** | Un objet concret d'une demande qu'on peut confier à quelqu'un — en pratique, un plan ISO. |
| **Affectation** | Le fait de confier un élément à une personne, pour un type de travail : plan, montage ou soudage. |
| **Avancement** | Le pourcentage fait d'une affectation. Il se calcule (étapes faites, soudures remplies) et n'est écrit que quand le chef d'atelier valide. |
| **Déclaration** | Un avancement proposé par l'atelier, en attente de la validation du chef. |
| **Cordon, soudure** | Une soudure du tableau imprimé sur le plan. Le soudeur la remplit dans « Mes soudures ». |
| **Coulée** | Le numéro de fabrication d'un lot de tube ou d'acier, porté par le certificat matière. C'est une donnée de traçabilité. |
| **CCPU** | Le certificat matière du fournisseur (EN 10204 type 3.1), qui porte les numéros de coulée. |
| **Nomenclature** | La liste des pièces d'un plan, avec leurs coulées. |
| **PV** | Procès-verbal d'une étape de fabrication, avec ses documents et ses photos. |
| **Bon de commande, bon de livraison** | Ce qu'IDEM commande au fournisseur, et ce qui arrive réellement. |
| **Manque** | Un matériel qui manque à l'atelier, signalé par le soudeur depuis « Mes soudures ». |
| **Passivation** | Le traitement de surface de l'inox après fabrication (décapage, acide citrique…). |
| **RT, VT, PT** | Les contrôles de soudure : radiographie, contrôle visuel, ressuage. Le chef les coche en validant les cordons ; une croix sur le plan veut dire OK. |
| **QMOS, DMOS** | La qualification et le descriptif du mode opératoire de soudage. |
| **Pouce ISO** | L'unité de charge de la tuyauterie. Le temps par pouce ISO sert à estimer le délai d'une demande. |

## La pile technique en un coup d'œil

| Pièce | Version | Rôle |
| --- | --- | --- |
| PHP + Apache | 8.3 | L'application, servie par l'image `php:8.3-apache` |
| MySQL | 8.4 | La base de données |
| phpMyAdmin | dernière | L'administration de la base, sur le port 8081 |
| htmx | 2.0.11 | L’interface, chargée depuis un CDN — pas d'étape de compilation |
| Poppler, Tesseract | 25.03, 5.5 | La lecture automatique des PDF et des scans, sur le serveur |
| Docker Compose | v2 et plus | Le lancement de tout l'ensemble |
| VitePress | 1.6.4 | Cette documentation, sur le port 8082 |

| Adresse | Ce qu'on y trouve |
| --- | --- |
| http://localhost:8080 | L'application — l'écran de connexion est à la racine |
| http://localhost:8081 | phpMyAdmin |
| http://localhost:8082 | Cette documentation |

La suite : les [prérequis](/guide/prerequis), puis l'[installation](/guide/installation).
