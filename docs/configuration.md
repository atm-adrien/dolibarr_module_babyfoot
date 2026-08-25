# Babyfoot — Documentation

## Présentation

Le module Babyfoot enregistre les parties de babyfoot jouées au bureau, en 1 contre 1 ou en
2 contre 2, et en tire un classement Elo ainsi que des statistiques individuelles et collectives.

Les joueurs sont exclusivement des utilisateurs Dolibarr de l'instance. Aucun enjeu métier :
pas de workflow de validation, pas de document PDF, pas d'écriture comptable.

Le module est conçu autour d'un principe unique : **l'écran de saisie doit permettre
d'enregistrer une partie en une quinzaine de secondes, depuis un mobile, debout, juste après
la partie.** Tout le reste est de la consultation.

### Vocabulaire

- **Partie** : une rencontre terminée entre deux camps, avec un score final.
- **Mode** : 1v1 (simple) ou 2v2 (double).
- **Camp** : camp 1 (bleu) ou camp 2 (rouge), contenant 1 ou 2 joueurs selon le mode.
- **Elo** : note de force du joueur, recalculée après chaque partie validée.
- **Fanny** : victoire sur le score maximum contre zéro (10-0 par défaut). Compteur symbolique.
- **Série** : nombre de victoires ou de défaites consécutives, toutes parties confondues.

### Trois classements en parallèle

Un classement distinct est tenu pour le 1v1, pour le 2v2 et pour l'ensemble des parties.
Une partie 1v1 fait évoluer le classement 1v1 et le classement général ; une partie 2v2 fait
évoluer le classement 2v2 et le classement général. Les trois évoluent indépendamment.

Un joueur n'ayant jamais joué dans un mode n'apparaît pas du tout au classement de ce mode.

## Configuration

Les paramètres ci-dessous sont créés à l'activation du module et se règlent depuis
l'onglet **Configuration**.

> Note : modifier un paramètre de calcul Elo ne recalcule pas le classement existant.
> Après un changement de coefficient, lancer un recalcul complet depuis l'onglet Configuration
> pour que l'historique soit rejoué avec les nouvelles valeurs.

### Règles de partie

- **Score maximum d'une partie** (`BABYFOOT_SCORE_MAX`, défaut 10) — borne haute des deux scores.
- **Le camp gagnant doit atteindre exactement le score maximum** (`BABYFOOT_SCORE_EXACT`, défaut oui) —
  désactiver ce paramètre pour accepter les parties écourtées.
- **Autoriser les matchs nuls** (`BABYFOOT_ALLOW_DRAW`, défaut non).

### Calcul du classement

- **Elo de départ** (`BABYFOOT_ELO_INITIAL`, défaut 1000) — note attribuée à la première partie
  d'un joueur.
- **Coefficient K des joueurs confirmés** (`BABYFOOT_ELO_K`, défaut 24) — amplitude des variations
  d'Elo. Plus il est élevé, plus le classement réagit vite.
- **Coefficient K des débutants** (`BABYFOOT_ELO_K_NOVICE`, défaut 40) — appliqué tant que le joueur
  n'a pas atteint le seuil ci-dessous, pour que son niveau réel soit trouvé rapidement.
- **Nombre de parties avant de quitter le statut de débutant** (`BABYFOOT_ELO_NOVICE_GAMES`, défaut 15).
  Ce compteur est propre à chaque classement : un joueur peut être débutant en 1v1 et confirmé au
  classement général.
- **Pondérer le coefficient K par l'écart de buts** (`BABYFOOT_ELO_MARGIN`, défaut non) — une victoire
  large rapporte alors davantage qu'une victoire serrée.
- **Nombre de parties minimum pour figurer au classement** (`BABYFOOT_MIN_GAMES_RANKED`, défaut 5) —
  en dessous, le joueur est listé à part, dans la section « non classés ».

> Attention : en 2 contre 2, les deux joueurs d'un même camp partagent la même espérance de gain
> mais conservent leur propre coefficient K. Un débutant et un joueur confirmé du même camp
> reçoivent donc des variations d'Elo différentes, toujours de même sens.

### Saisie et modification

- **Délai de modification par l'auteur, en heures** (`BABYFOOT_EDIT_DELAY`, défaut 24) — au-delà,
  seul un utilisateur disposant du droit « Modifier toute partie » peut intervenir.
- **Pré-positionner l'utilisateur connecté comme premier joueur** (`BABYFOOT_PREFILL_CURRENT_USER`,
  défaut oui).
- **Mode de jeu proposé par défaut** (`BABYFOOT_DEFAULT_MODE`, défaut 2v2). Le dernier mode utilisé
  est ensuite mémorisé par utilisateur.

## Utilisation

### Saisir une partie

1. Ouvrir **Babyfoot > Nouvelle partie**.
2. Choisir le mode : 1 contre 1 ou 2 contre 2.
3. Sélectionner les joueurs de chaque camp. Les derniers coéquipiers et adversaires sont proposés
   en tête de liste.
4. Saisir les deux scores, au clavier ou avec les boutons + et −.
5. Valider. Le camp vainqueur est déduit du score, il n'est jamais saisi.

La date et l'heure sont pré-remplies à maintenant. Le bloc de date se déplie pour saisir une
partie oubliée. Après enregistrement, la variation d'Elo de chaque joueur s'affiche et le
formulaire se réinitialise, prêt pour la partie suivante.

> Note : le créateur d'une partie n'est pas obligé d'y avoir participé. Une personne peut saisir
> pour tout le monde.

### Consulter le classement

**Babyfoot > Classement** présente les trois classements sous forme d'onglets. Un filtre de période
recalcule les compteurs sur 30 jours, 90 jours, l'année ou la totalité de l'historique. En période
restreinte, l'Elo affiché reste l'Elo courant, complété par la variation sur la période.

En cas d'égalité stricte d'Elo, les joueurs sont départagés par ratio de victoires, puis par
différence de buts, puis par nombre de parties.

### Corriger une partie

Depuis la fiche d'une partie, l'annulation est à privilégier sur la suppression : une partie
annulée est conservée dans l'historique tout en devenant neutre pour tous les calculs.

> Attention : corriger ou supprimer une partie qui n'est pas la plus récente déclenche un recalcul
> complet du classement, puisque l'Elo dépend de l'ordre chronologique des parties. L'opération est
> transactionnelle : en cas d'échec, rien n'est modifié.

## Administration

L'onglet **Configuration** propose deux outils réservés au droit d'administration du module.

- **Recalculer l'intégralité du classement** — remet à zéro la table de classement et rejoue toutes
  les parties validées par ordre chronologique. L'opération est idempotente : la lancer deux fois de
  suite donne un résultat identique. Au-delà de quelques milliers de parties, préférer le script en
  ligne de commande pour éviter un dépassement du temps d'exécution PHP.
- **Vérifier la cohérence** — compare le classement stocké à un recalcul théorique et signale les
  écarts **sans les corriger**.

En ligne de commande :

```
php scripts/recompute_ratings.php <entity>
```

## FAQ

### Un joueur a quitté l'entreprise, que deviennent ses parties ?

Elles sont conservées. Le joueur disparaît du classement actif et des sélecteurs de saisie, mais
reste visible dans les fiches de parties passées et dans les statistiques historiques. Aucune
suppression en cascade n'est effectuée sur les utilisateurs Dolibarr.

### Pourquoi mon Elo bouge-t-il autant au début ?

Le coefficient K des débutants vaut 40 par défaut, contre 24 ensuite. Les quinze premières parties
servent à situer rapidement le niveau réel du joueur : les variations y sont volontairement plus
amples. Le classement ne devient significatif qu'après quelques dizaines de parties.

### Pourquoi ne suis-je pas dans le classement ?

Il faut avoir joué au moins cinq parties par défaut. En dessous, le joueur figure dans la section
repliée « joueurs non classés », avec son Elo grisé.

### Le classement est-il cloisonné entre entités ?

Oui. Sur une instance multi-sociétés, chaque entité possède son propre classement et ses propres
statistiques.
