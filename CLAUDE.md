# Module Babyfoot — mémoire projet

## Description

Module Dolibarr custom d'enregistrement des parties de babyfoot jouées au bureau, en 1v1 ou 2v2,
avec classement Elo, statistiques individuelles et collectives.

Principe directeur : **un écran de saisie ultra-rapide, tout le reste est de la consultation.**
La saisie se fait debout, sur mobile, en 15 secondes juste après la partie. Toute friction
ajoutée à `game_quickadd.php` fait abandonner l'outil — c'est le critère qui arbitre tous les
choix d'ergonomie.

Aucun enjeu métier : pas de workflow d'approbation, pas de PDF, pas de comptabilité.

## Stack

- Dolibarr 22.0 (montée 24.0 prévue), PHP 8.x, MySQL/MariaDB
- PHPUnit 9.6 — `extends CommonClassTest`, base réelle, aucun mock
- Aucune dépendance externe, aucune librairie JS ajoutée, graphiques via `DolGraph` du core

## Structure

```
class/
  babyfootconfig.class.php     seul point de lecture de getDolGlobal* du module
  elocalculator.class.php      calcul Elo pur — AUCUN accès base, AUCUNE lecture de conf
  gamevalidator.class.php      règles RG-01 à RG-08, travaille sur des scalaires
  game.class.php               partie (CommonObject)
  gameplayer.class.php         participant (ligne fille de Game)
  rating.class.php             une ligne de classement
  ratingset.class.php          collection [fk_user][mode], chargement base OU mémoire
  ratingengine.class.php       SEULE classe autorisée à écrire dans babyfoot_rating
  stats/                       repositories de lecture seule (agrégats)
core/modules/modBabyfoot.class.php    descripteur
core/modules/babyfoot/                modèle de numérotation BF{yy}{mm}-{0000}
lib/babyfoot.lib.php           prepareHead UNIQUEMENT — pas un fourre-tout
docs/configuration.md          source de l'onglet documentation en ligne
```

## Commandes

```bash
# Suite de tests du module
cd /home/client/standard/demo22/dolibarr/htdocs/custom/babyfoot
/home/atm-adrien/bin/phpunit -c /home/client/standard/demo22/dolibarr/test/phpunit/phpunittest.xml test/phpunit/

# Recalcul complet du classement en CLI
php scripts/recompute_ratings.php <entity>
```

## Conventions structurantes du module

**Le calcul Elo est isolé et pur.** `EloCalculator` n'accède ni à la base ni à `$conf` : il reçoit
ses paramètres au constructeur. C'est ce qui le rend testable sans fixture. Ne jamais y introduire
de requête ni d'appel à `getDolGlobal*`.

**Aucune écriture dans `babyfoot_rating` hors de `RatingEngine`.** La table est un cache dérivé,
intégralement reconstructible par `recomputeAll()`. Rien ne doit y vivre qui ne soit pas recalculable.

**Un seul algorithme de rejeu.** `RatingEngine::applyGame()` travaille sur un `RatingSet` fourni par
l'appelant, sans savoir s'il vient de la base (calcul incrémental) ou de la mémoire (recalcul complet).
Cette indifférence est ce qui garantit que « supprimer une partie ancienne + recalculer » donne le
même état que si elle n'avait jamais existé. Ne pas créer de troisième chemin de recalcul.

**Verrou de concurrence obligatoire.** Toute écriture de rating passe par `RatingEngine::acquireLock()`
(ligne sentinelle `fk_user = 0, mode = '__lock__'` prise en `FOR UPDATE`). Sans lui, deux saisies
simultanées corrompent silencieusement le classement. La sentinelle est exclue de tous les affichages
et agrégats par `fk_user > 0`.

**Pas de calcul Elo à l'affichage.** Les écrans lisent `babyfoot_rating` ou les colonnes `elo_*`
dénormalisées de `babyfoot_game_player`. Jamais de rejeu chronologique dans une page.

**`BabyfootConfig::resolve()` est le seul lecteur de la configuration.** Les classes métier reçoivent
un tableau résolu, jamais `$conf`.

## Arbitrages actés sur la spécification

| # | Décision | Motif |
|---|---|---|
| D4 | Pas de recalcul partiel : toute modification non terminale déclenche `recomputeAll()` | Un chemin de code au lieu de deux, idempotence par construction. RG-30 satisfait fonctionnellement. |
| D9 | Colonnes `elo_all_before/after/delta` ajoutées sur `babyfoot_game_player` | Sans elles, la courbe d'Elo du mode `all` exigerait un rejeu à chaque affichage. |
| D10 | **RG-15 prime sur RG-14** : espérance commune au camp, `K` individuel par joueur et par mode | Les deux règles de la spec étaient incompatibles en 2v2 (novice + confirmé). |
| D11 | Série en cours affichée = toujours celle de la ligne `mode = 'all'` | Le glossaire définit la série comme globale ; elle est stockée 3 fois. |
| D12 | Flèche d'évolution du rang = par rapport au rang précédant la propre dernière partie du joueur | Non spécifié par la spec. |
| D13 | Filtre « mes parties » = parties où l'utilisateur **a joué** (pas celles qu'il a saisies) | RG-07 rend les deux notions distinctes. |
| D14 | RG-16 : `écart = abs(score1 - score2)`, facteur planché à `1.0` | La règle était tronquée dans la spec. |

## Pièges connus

- **`$this->const` : le 7ᵉ élément (`deleteonunactive`) doit valoir `0`.** À `1`, la désactivation
  du module supprime les constantes et un paramétrage personnalisé est perdu à la réactivation —
  ce que le critère d'acceptation de la spec interdit explicitement. Vérifié par un cycle
  désactivation/réactivation avec `BABYFOOT_SCORE_MAX` forcé à 7.
- **Tester l'activation/désactivation dans des processus PHP séparés.** Enchaîner
  `unActivateModule()` puis `activateModule()` dans le même processus renvoie `nbmodules = 0` :
  `$conf` est mis en cache en mémoire. Ce n'est pas un bug du module.
- `get_next_value()` du core attend un nom de table **sans** préfixe. C'est le seul endroit du
  module où un nom de table s'écrit sans `$db->prefix()`.
- `rowid`, `ref`, `mode` et `status` déclenchent le gate `sqlfluff-lint` (mots-clés réservés). Les
  trois premiers sont imposés par `CommonObject` : commiter avec `SKIP_HOOKS="sqlfluff-lint"`.
- `class/techatm.class.php` est **vendorisé** par `team-ai-scaffold techatm` et produit 14 erreurs
  PHP CodeSniffer (PHPDoc et visibilités manquantes) sur le gate `lint` d'ATM lui-même. Ne pas le
  corriger sans le signaler : il serait écrasé au prochain scaffold.
- Le K du calcul Elo est lu sur `nb_games` **du mode concerné**. Un joueur peut donc être novice en
  1v1 et confirmé en `all` sur la même partie : les deltas des deux lignes diffèrent. C'est voulu.
- `recomputeAll()` ouvre sa propre transaction et peut être appelée depuis celle de `Game::create()`.
  L'imbrication `begin`/`commit` de DoliDB est comptée par références, mais un `rollback()` interne
  annule tout — comportement voulu : si le recalcul échoue, la partie ne s'enregistre pas.

## Hors périmètre v1

Saisie but par but, saisons, validation par l'adversaire, tournois, notifications, API REST
(l'emplacement `class/api_babyfoot.class.php` est prévu mais non déclaré dans `module_parts`).
