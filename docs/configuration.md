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

## Règles fixes

Les règles suivantes ne se règlent pas : elles sont inscrites dans le code, comme constantes de
`BabyfootConfig`.

- Une partie se joue **en 10 buts**, et le camp vainqueur doit atteindre ce total. Une partie
  écourtée est refusée.
- Il n'y a **jamais de match nul** : deux scores égaux sont refusés.
- Le **coefficient K vaut 40** pour tout le monde, sans distinction de débutant ou de confirmé et
  sans pondération par l'écart de buts. Deux joueurs à 1000 qui s'affrontent échangent donc
  exactement 20 points, et les deux joueurs d'un même camp reçoivent toujours la même variation.
- On **figure au classement dès la première partie**, sans seuil minimum.

## Configuration

Trois paramètres seulement, créés à l'activation du module et réglables depuis l'onglet
**Configuration**.

- **Elo de départ** (`BABYFOOT_ELO_INITIAL`, défaut 1000) — note attribuée à la première partie
  d'un joueur.
- **Pré-positionner l'utilisateur connecté comme premier joueur** (`BABYFOOT_PREFILL_CURRENT_USER`,
  défaut oui).
- **Mode de jeu proposé par défaut** (`BABYFOOT_DEFAULT_MODE`, défaut 2v2). Le dernier mode utilisé
  est ensuite mémorisé par utilisateur.

> Note : modifier l'Elo de départ ne recalcule pas le classement existant. Lancer ensuite un
> recalcul complet depuis l'onglet Configuration pour que l'historique soit rejoué avec la
> nouvelle valeur.

## Utilisation

### Saisir une partie

1. Ouvrir **Babyfoot > Parties > Créer une partie**.
2. Choisir le mode : 1 contre 1 ou 2 contre 2.
3. Sélectionner les joueurs de chaque camp. Les derniers coéquipiers et adversaires sont proposés
   en tête de liste.
4. Saisir les deux scores au clavier.
5. Valider. Le camp vainqueur est déduit du score, il n'est jamais saisi.

La partie est enregistrée à l'instant de la saisie : l'écran ne propose pas de choisir la date.
Une partie oubliée se corrige depuis sa fiche, où la date reste modifiable. Après enregistrement,
la variation d'Elo de chaque joueur s'affiche et le formulaire se réinitialise, prêt pour la
partie suivante.

> Note : le créateur d'une partie n'est pas obligé d'y avoir participé. Une personne peut saisir
> pour tout le monde.

### Retrouver un joueur

**Babyfoot > Joueurs > Liste des joueurs** est l'annuaire : un joueur y apparaît dès sa première
partie. Le tri par défaut est alphabétique, chaque colonne est triable, et le nom mène à la fiche
du joueur. Un joueur dont le compte Dolibarr a été supprimé reste listé, sous la forme `#identifiant` :
son historique compte toujours dans le classement.

### Consulter le classement

**Babyfoot > Classement** présente les trois classements sous forme d'onglets. Un filtre de période
recalcule les compteurs sur 30 jours, 90 jours, l'année ou la totalité de l'historique. En période
restreinte, l'Elo affiché reste l'Elo courant, complété par la variation sur la période.

En cas d'égalité stricte d'Elo, les joueurs sont départagés par ratio de victoires, puis par
différence de buts, puis par nombre de parties.

### Corriger une partie

Modifier, annuler ou supprimer une partie exige le droit **Modifier toute partie**. Ni le fait
d'avoir saisi la partie, ni son ancienneté ne donnent de privilège particulier.

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

### Pourquoi mon Elo bouge-t-il autant ?

Le coefficient K vaut 40 pour tout le monde, ce qui est volontairement nerveux : deux joueurs de
même niveau échangent 20 points par partie, et battre un joueur bien mieux classé peut en rapporter
30. Le classement réagit donc vite, au prix d'une stabilité moindre. Comptez une quinzaine de
parties pour qu'il reflète un niveau réel.

### Pourquoi les deux joueurs de mon camp gagnent-ils autant l'un que l'autre ?

Parce que le coefficient K est le même pour tous et que l'espérance de gain est celle du camp, pas
celle du joueur. En 2 contre 2, les deux coéquipiers reçoivent exactement la même variation.

### Le classement est-il cloisonné entre entités ?

Oui. Sur une instance multi-sociétés, chaque entité possède son propre classement et ses propres
statistiques.
