# Sessions et sécurité

Comment l'application sait qui est là, ce qu'elle protège, et ce qui reste à durcir.

## La session

| Ce qu'elle porte | Ce qu'elle ne porte pas |
| --- | --- |
| L'identifiant du compte, le jeton CSRF, la liste des comptes mémorisés sur l'appareil | Le nom et le rôle : ils sont **relus en base à chaque requête**, donc un compte renommé, changé de rôle ou supprimé se voit tout de suite |

`src/modeles/Session.php` est la seule classe qui touche `$_SESSION`.

| Règle | Détail |
| --- | --- |
| Durée | Le fichier de session est ramassé après **8 heures** sans activité (`session.gc_maxlifetime=28800`). |
| Cookie | Un cookie de session : fermer le navigateur déconnecte, et vide la liste des comptes mémorisés — la bonne règle sur un poste d'atelier partagé. |
| Déconnexion | `auth/logout.php` vide la session, retire le cookie et détruit le stockage : **tous** les comptes de l'appareil sont déconnectés. |
| Où elle vit | Dans le conteneur web : le recréer (`run down`, `run rebuild`, changement de mode) déconnecte tout le monde. |

## Plusieurs comptes sur un appareil

Le menu des comptes en mémorise plusieurs (`$_SESSION['comptes']`) ; `actions/comptes.php` en
fait trois opérations :

| Opération | Ce qu'elle fait |
| --- | --- |
| `add` | Identifiant et mot de passe exigés ; le nouveau compte rejoint la liste |
| `switch` | Bascule **sans mot de passe** vers un compte de la liste |
| `remove` | Retire un compte de l'appareil ; si c'était le compte actif, bascule sur un autre, ou déconnecte |

Chaque bascule régénère l'identifiant de session ; le jeton CSRF survit.

## Le jeton CSRF

Toute écriture porte le jeton de session : 64 caractères hexadécimaux, stables pendant la
session (`Session::jeton()`), comparés par `hash_equals` (`Session::jetonValide()`).

| Où il voyage | Comment |
| --- | --- |
| Formulaires | `<input type="hidden" name="csrf">` |
| Scripts | Lu dans un attribut `data-csrf`, ajouté au `FormData` sous le nom `csrf` |
| htmx | `hx-vals` |
| API | En-tête `X-Jeton`, ou champ `jeton` du corps |

Côté serveur, `prefaPost()` refuse un POST sans jeton valide : « Rechargez la page puis
réessayez. »

## Les droits

**Le serveur vérifie tout, l'interface ne fait que cacher.** Chaque page répond `403` si le rôle
n'y a pas droit, chaque action revérifie le droit avant d'écrire, et chaque lecture de demande
passe par la même règle de visibilité (`Demande::clauseVisible()`). La matrice est dans
[Rôles et droits](/fonctionnalites/roles#qui-peut-faire-quoi).

## Les fichiers déposés

Ils vivent sous la racine web, dans `src/dashboard/uploads/`, mais **Apache refuse de les
servir** (`Require all denied`, posé par le `Dockerfile` ; `AllowOverride` vaut `None`, un
`.htaccess` n'y changerait rien). Ils ne sortent que par les actions `download_*.php`, toutes
derrière la session :

| Action | Qui peut lire |
| --- | --- |
| `download_prefa_attachment.php` | L'encadrement, le propriétaire de la demande ; le personnel d'atelier seulement pour un document rattaché à **sa** affectation active, sur une demande validée |
| `download_qmos.php`, `download_dmos.php` | La même règle |
| `download_materiel.php`, `download_ccpu.php` | L'encadrement et le propriétaire — pas l'atelier |
| `download_pv_document.php` | La même logique que les PV |
| `plan_image.php`, `download_plan_iso.php`, `download_plan_final.php`, `plan_final_lecteur.php` | La même logique que les plans |
| `detecter_lignes.php` (la lecture des tableaux d'un plan) | L'encadrement ; le personnel d'atelier affecté au plan ; le demandeur, sur **ses** demandes — en lecture seule, pour la liste soudure du plan final |

Un refus répond `404`. Les réponses portent `X-Content-Type-Options: nosniff`, et partent en
pièce jointe sauf pour un aperçu.

| Contrôle à l'envoi | Règle |
| --- | --- |
| Taille | 50 Mo par fichier ; 512 Mo par envoi |
| Type | Vérifié sur le contenu (`finfo`), pas sur l'extension ; les PDF doivent commencer par `%PDF-` |
| Nom | Ramené à `[A-Za-z0-9._-]`, 180 caractères au plus, préfixé de 32 caractères aléatoires |

## Les mots de passe

Hachés par `password_hash` (bcrypt), vérifiés par `password_verify`. Un mot de passe créé par
`run admin` est tiré au sort et montré une seule fois ; ceux des comptes d'essai ne sont jamais
affichés.

## La prise de place, en développement seulement

`Environnement::estDev()` n'est vrai que si `APP_ENV` vaut **exactement** `dev`. Les deux points
d'entrée de la prise de place — `auth/dev_connexion.php` sur l'écran de connexion,
`dashboard/actions/dev_connexion.php` depuis le menu des comptes — répondent `404` en production
avant toute autre chose, et la liste des comptes n'est même pas lue. En développement, chaque
prise de place est journalisée : « [dev] A a pris la place de B ».

## Ce qui reste à durcir

Relevé en lisant le code, le 28 octobre 2026 — **rien de cela n'est corrigé** :

| Point | Constat | Piste |
| --- | --- | --- |
| Cookie de session | Ni `HttpOnly`, ni `SameSite`, ni `Secure` : ce sont les valeurs par défaut de PHP. Un script injecté pourrait lire le cookie. | `session.cookie_httponly=1`, `session.cookie_samesite=Lax` dans le `Dockerfile` ; `Secure` derrière HTTPS |
| Connexion par le formulaire | `auth/action.php` ne régénère pas l'identifiant de session, contrairement à l'API et aux bascules de compte. | `session_regenerate_id(true)` après une connexion réussie |
| Types de passivation | `actions/create_passivation.php` ne vérifie aucun rôle : tout compte connecté peut en créer. | Le réserver au chargé de production |
| Réception d'une commande | `receptionner_bon_materiel.php` n'exige pas que la commande soit validée ; seule l'interface l'impose. | Le vérifier côté serveur |
| phpMyAdmin | Publié sur le port 8081, avec les identifiants de `docker-compose.yml`. | Voir [la sécurité en production](/production/securite) |
| HTTP | L'application est servie en HTTP clair, sur le port 8080. | Un mandataire inverse avec TLS si elle sort du réseau de l'atelier |
