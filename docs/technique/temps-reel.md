# Le flux en temps réel

Quand le chargé d'affaire dépose une demande, le chargé de production le voit sans recharger sa
page : une alerte paraît, les pastilles et les tuiles se mettent à jour. C'est le flux
d'événements.

## Le mécanisme

Des **Server-Sent Events** : le navigateur ouvre une connexion qui reste ouverte, et le serveur y
écrit quand il a quelque chose à dire. Il n'y a pas d'interrogation répétée côté navigateur.

```
  flux.js                                     api/flux.php
  new EventSource('/api/flux.php') ─────────▶ relit la session, puis la ferme aussitôt
                                              ┌─ toutes les 2 s : la table evenement
            ◀── event: compteurs ──────────── │  (le curseur de départ : le dernier id)
            ◀── event: <type>, id: 4812 ───── │  un événement que cette personne peut voir
            ◀── : battement ──────────────── │  après 15 s de silence
                                              └─ au bout de 60 s : fin de la réponse
  reconnexion automatique, Last-Event-ID: 4812 ─▶ reprend après le dernier reçu
```

| Règle | Détail |
| --- | --- |
| Pas de session | Réponse `204` — et non `401` : un `EventSource` ne se reconnecte pas sur un code hors 2xx, et c'est ce qu'on veut d'une session finie. |
| La session ne bloque pas | Elle est relue puis fermée tout de suite : une connexion ouverte une minute ne fige pas les autres requêtes de la même personne. |
| Reprise | Le navigateur se reconnecte seul et envoie `Last-Event-ID` : rien n'est perdu ni rejoué. Au premier passage, le curseur part du dernier événement : pas d'historique rejoué. |
| Tampon | `X-Accel-Buffering: no`, pour qu'un mandataire ne retienne pas les messages. |
| Journal | Le flux n'est pas noté dans le journal tant qu'il va bien — il rend la main toutes les minutes pour chaque onglet ouvert. |

## Les événements

Écrits par `Evenement::noter()` (`src/modeles/Evenement.php`) **après** la validation de la
transaction ; un échec d'écriture ne fait jamais échouer l'action. Chaque événement est doublé
d'une ligne de journal.

| Type | Émis quand | Pour qui |
| --- | --- | --- |
| `demande.deposee` | Une demande est déposée | Ceux qui peuvent voir la demande |
| `demande.validee` | Une demande est validée | Ceux qui peuvent voir la demande |
| `demande.refusee` | Une demande est refusée, avec son motif | Ceux qui peuvent voir la demande |
| `affaire.confiee` | Une affaire est confiée à un chargé d'affaire | Ce chargé d'affaire |
| `manque.signale` | L'atelier signale un manque | Chaque membre de l'encadrement, nommément |
| `manque.traite` | Un manque est traité | Celui qui l'avait signalé |

**Qui reçoit quoi.** Un événement adressé à quelqu'un ne va qu'à lui. Les autres suivent la règle
de visibilité des demandes (`Demande::clauseVisible()`), évaluée sur l'état **actuel** de la
demande. L'auteur d'un événement n'est jamais prévenu de son propre geste.

## Ce que le navigateur en fait

- **Les compteurs** — en attente, urgentes, nouvelles affaires, mes affaires, manques — voyagent
  avec chaque événement et sont **posés**, jamais incrémentés : un message perdu ne fausse rien.
  Ils alimentent les pastilles de la barre latérale (`data-compteur`) et les tuiles de l'accueil.
- **Les alertes** : trois au plus à la fois, neuf secondes chacune, cliquables pour ouvrir ce
  qu'elles annoncent.

## Ajouter un type d'événement

1. Le déclarer dans `Evenement` et l'émettre par `Evenement::noter()` **après** le `commit`.
2. Dire à qui il s'adresse : un destinataire nommé, ou la règle de visibilité de sa demande.
3. Le traiter dans `src/js/coquille/flux.js`.
