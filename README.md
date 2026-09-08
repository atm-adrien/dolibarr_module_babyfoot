# Module Babyfoot pour Dolibarr

Enregistre les parties de babyfoot jouées au bureau, en 1 contre 1 ou en 2 contre 2,
et en tire un classement Elo ainsi que des statistiques individuelles et collectives.

Les joueurs sont les utilisateurs Dolibarr de l'instance. Aucun enjeu métier : pas de
workflow de validation, pas de document PDF, pas d'écriture comptable.

## Principe

Le module est construit autour d'une seule contrainte : **l'écran de saisie doit
permettre d'enregistrer une partie en une quinzaine de secondes, depuis un mobile,
debout, juste après la partie.** Tout le reste est de la consultation.

## Prérequis

- Dolibarr 22.0 ou supérieur
- PHP 7.4 ou supérieur
- MySQL / MariaDB

Aucune dépendance externe, aucune librairie JavaScript ajoutée. Les graphiques
utilisent la bibliothèque fournie par Dolibarr.

## Installation

1. Copier le dossier `babyfoot` dans `htdocs/custom/`.
2. Activer le module depuis `Accueil > Configuration > Modules`, onglet des modules
   externes.
3. Attribuer les droits aux utilisateurs concernés.

L'activation crée trois tables, les quatre droits, le menu, le widget de tableau de bord
et les trois paramètres par défaut. **La désactivation ne supprime aucune donnée et ne
réinitialise aucun paramétrage** : la réactivation retrouve l'existant intact.

## Fonctionnalités

| Écran | Rôle |
|---|---|
| Créer une partie | Saisie rapide, 1v1 ou 2v2, score final uniquement |
| Liste des parties | Liste filtrable, fiche, modification, annulation, suppression |
| Liste des joueurs | Annuaire trié par nom, colonnes triables, lien vers la fiche |
| Classement | Elo par mode, avec filtre de période |
| Fiche joueur | Compteurs, courbe d'Elo, confrontations, dernières parties |
| Statistiques | Totaux, duos, fannys, répartition horaire et hebdomadaire |
| Widget | Top 5, rang personnel, trois dernières parties |
| Configuration | Les trois paramètres, recalcul et vérification de cohérence |

## Droits

| Code | Portée |
|---|---|
| `read` | Consulter les parties, le classement et les statistiques |
| `create` | Saisir une partie |
| `modify_all` | Modifier, annuler ou supprimer toute partie |
| `admin` | Configurer le module et lancer un recalcul |

`read` peut être accordé largement. `admin` n'implique pas `modify_all` : ce sont deux
axes distincts, le paramétrage d'une part, les données de l'autre.

## Paramétrage

Les règles du jeu ne se règlent pas : une partie se joue en 10 buts que le vainqueur
doit atteindre, un match nul est refusé, le coefficient K vaut 40 pour tout le monde
sans pondération par l'écart de buts, et on figure au classement dès la première
partie. Ces valeurs sont des constantes de `BabyfootConfig`.

Trois paramètres seulement, réglables depuis l'onglet Configuration du module : Elo de
départ, pré-remplissage de l'utilisateur connecté et mode de jeu par défaut.

La documentation complète de chaque paramètre est accessible en ligne depuis l'onglet
Documentation du module.

## Recalcul du classement

L'Elo dépend de l'ordre chronologique des parties. Corriger ou supprimer une partie qui
n'est pas la plus récente déclenche donc un recalcul complet, transactionnel : en cas
d'échec, rien n'est modifié.

Le recalcul peut aussi être lancé manuellement, depuis l'écran de configuration ou en
ligne de commande :

```bash
php scripts/recompute_ratings.php <entity>          # recalcule
php scripts/recompute_ratings.php <entity> --check  # signale les écarts sans corriger
```

Au-delà de quelques milliers de parties, préférer le script au bouton, pour éviter un
dépassement du temps d'exécution PHP.

## Tests

```bash
cd htdocs/custom/babyfoot
phpunit -c <dolibarr>/test/phpunit/phpunittest.xml test/phpunit/
```

68 tests, 202 assertions. Les tests s'exécutent dans une transaction annulée à la fin :
ils ne laissent aucune donnée derrière eux.

## Hors périmètre de la version 1

Ces points sont documentés comme évolutions possibles, pas comme des manques :

- saisie but par but, buteur, gamelle, but contre son camp ;
- saisons ou championnats avec remise à zéro périodique ;
- validation de la partie par l'adversaire ;
- tournois, brackets, matchmaking ;
- notifications par courriel ou messagerie ;
- API REST — l'emplacement est prévu (`class/api_babyfoot.class.php`) mais n'est pas
  déclaré dans le descripteur.

## Licence

GPL v3 ou supérieure.
