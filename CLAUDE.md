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
  stats/                       repositories de lecture seule (agrégats) ; GameRepository
                               charge N parties en 3 requêtes et lève une Exception sur erreur SQL
  api_babyfoot.class.php       API REST /babyfoot/games — appelle Game, jamais de règle propre
core/modules/modBabyfoot.class.php    descripteur
core/modules/babyfoot/                modèle de numérotation BF{yy}{mm}-{0000}
lib/babyfoot.lib.php           prepareHead + garde babyfootDocumentationIsEnabled() UNIQUEMENT
lib/babyfoot_doc.lib.php       rendu de l'onglet doc — généré par `team-ai-scaffold docs`, ne pas éditer
docs/configuration.md          source de l'onglet documentation en ligne
```

## Commandes

```bash
# Suite de tests du module
cd /home/client/standard/demo22/dolibarr/htdocs/custom/babyfoot
/home/atm-adrien/bin/phpunit -c /home/client/standard/demo22/dolibarr/test/phpunit/phpunittest.xml test/phpunit/

# Tests E2E Playwright (instance jetable uniquement : écrit en base, active le module si besoin)
npm i && E2E_ALLOW_FIXTURES=1 E2E_ENTITY=<entity> BASE_URL=<url htdocs/> npx playwright test

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
| D10 | **RG-15 abandonnée** : `K` unique de 40 pour tous, aucune notion de débutant | Le conflit RG-14/RG-15 en 2v2 disparaît avec un K commun. Les deux joueurs d'un camp reçoivent le même delta. |
| D11 | Série en cours affichée = toujours celle de la ligne `mode = 'all'` | Le glossaire définit la série comme globale ; elle est stockée 3 fois. |
| D12 | Flèche d'évolution du rang = par rapport au rang précédant la propre dernière partie du joueur | Non spécifié par la spec. |
| D13 | Filtre « mes parties » = parties où l'utilisateur **a joué** (pas celles qu'il a saisies) | RG-07 rend les deux notions distinctes. |
| D14 | **RG-16 abandonnée** : l'écart de buts ne pondère jamais `K` | Demande explicite. `EloCalculator::delta()` ne reçoit plus les scores. |
| D15 | **RG-19 abandonnée** : on figure au classement dès la première partie | Plus de seuil, donc plus de section « non classés » ni de `getUnranked()`. |
| D16 | **RG-32 réduite au seul droit `modify_all`** : plus de délai, plus de droit `modify_own` | La notion d'auteur ne sert plus qu'à la traçabilité. |

## Pièges connus

- **`$this->const` : le 7ᵉ élément (`deleteonunactive`) doit valoir `0`.** À `1`, la désactivation
  du module supprime les constantes et un paramétrage personnalisé est perdu à la réactivation —
  ce que le critère d'acceptation de la spec interdit explicitement. Vérifié par un cycle
  désactivation/réactivation avec `BABYFOOT_ELO_INITIAL` forcé à 1200.
- **Tester l'activation/désactivation dans des processus PHP séparés.** Enchaîner
  `unActivateModule()` puis `activateModule()` dans le même processus renvoie `nbmodules = 0` :
  `$conf` est mis en cache en mémoire. Ce n'est pas un bug du module.
- **Le picto du module est `fa-futbol`, écrit `'^fa-futbol'` partout où Dolibarr le
  passe à `img_object()`** — `modBabyfoot::$picto`, `Game::$picto`, `$boximg` du widget.
  Sans le `^`, `img_object()` préfixe `object_`, ce qui casse la détection FontAwesome
  d'`img_picto()` et affiche le picto générique. Dans les appels directs à `img_picto()`,
  `load_fiche_titre()`, `dol_get_fiche_head()`, `print_barre_liste()` et dans le `prefix`
  d'un menu gauche, écrire `'fa-futbol'` nu.
- **Pas de menu dans le bandeau du haut** : tout est sous `fk_mainmenu=tools`, `mainmenu => 'tools'`.
  Les menus ne sont écrits en base qu'à l'activation : toute modification impose un cycle
  désactivation/réactivation pour être visible.
- **E2E : les comptes de test portent le préfixe `e2ebabyfoot_`**, c'est par lui que
  `tests/e2e/fixtures/teardown.php` supprime parties et comptes. Avec Multicompany, le login
  choisit l'entité `E2E_ENTITY` dans le formulaire : sans cela la session tombe dans une autre entité.
- **Les périodes passent par `BabyfootConfig::periods()` / `periodStart()`.** Leurs codes
  `'30'`, `'90'`, `'365'` deviennent des clés **entières** dans un tableau PHP : comparer avec
  `(string) $key === $period`, sinon aucune période n'est jamais sélectionnée.
- **`User::fetch($id)` ne filtre pas l'entité** (seule la recherche par login le fait). Pour
  vérifier qu'un utilisateur appartient à l'entité, passer par `RankingRepository::findUserId()`.
  En mode transverse de Multicompany, l'appartenance passe par les groupes (`usergroup_user`),
  pas par `user.entity` : tout filtre d'utilisateurs passe par `RankingRepository::userScopeSql()`.
- **La classe d'API s'appelle `Babyfoot`, pas `BabyfootApi`.** `api/index.php` déduit le nom de
  classe du premier segment d'URL : c'est ce qui sert les routes sous `/babyfoot/...`.
  Dans un test PHPUnit, renseigner `DolibarrApiAccess::$user` avant d'appeler une méthode.
- **Un sous-menu gauche doit avoir une `position` supérieure à celle de son parent.**
  `Menubase::menuLoad()` insère un enfant uniquement si le couple
  (`fk_mainmenu`, `fk_leftmenu`) de son parent figure déjà dans la liste construite.
  Parent déclaré après l'enfant : celui-ci disparaît de l'affichage sans erreur,
  seul un `dol_syslog` de niveau WARNING le signale.
- **`session_cache_limiter('public')` doit être appelé AVANT `main.inc.php`** dans
  `css/babyfoot.css.php` et `js/babyfoot.js.php`, comme le fait `theme/eldy/style.css.php`.
  Appelé après, la session est déjà ouverte et PHP émet un warning imprimé en tête du
  fichier : le parseur CSS avale alors la première règle en récupération d'erreur (donc
  `.babyfoot-teams`, et l'écran de saisie perd sa grille), et le JS ne se parse plus du tout.
  Les deux fichiers sont servis avec `max-age=86400` : toute modification exige un
  rechargement forcé du navigateur pour être constatée.
- **`lib/babyfoot.lib.php` : le 6ᵉ argument de `complete_head_from_modules()` est `$type`,
  pas un picto.** Il vaut `'babyfoot@babyfoot'` et ne doit pas suivre les changements d'icône.
- `get_next_value()` du core attend un nom de table **sans** préfixe. C'est le seul endroit du
  module où un nom de table s'écrit sans `$db->prefix()`.
- `rowid`, `ref`, `mode` et `status` déclenchent le gate `sqlfluff-lint` (mots-clés réservés). Les
  trois premiers sont imposés par `CommonObject` : commiter avec `SKIP_HOOKS="sqlfluff-lint"`.
- `class/techatm.class.php` est **vendorisé** par `team-ai-scaffold techatm` et produit 14 erreurs
  PHP CodeSniffer (PHPDoc et visibilités manquantes) sur le gate `lint` d'ATM lui-même. Ne pas le
  corriger sans le signaler : il serait écrasé au prochain scaffold.
- **`ModeleBoxes::showBox()` strippe les balises HTML** d'une cellule `text`, sauf si le texte
  commence par `<img`, `<div` ou `<span`, ou si la cellule porte `'asis' => 1`. Pour un lien,
  utiliser le mécanisme natif `'url' => ...` + `'text' => 'libellé en clair'` : construire soi-même
  un `<a href>` produit un lien silencieusement supprimé à l'affichage.
- **`showBox()` met en cache le rendu 15 minutes** sur disque (`DOL_DATA_ROOT/users/temp/widgets`),
  avec une clé qui ne dépend pas des données. Pour tester le widget, supprimer
  `box-box_babyfoot_ranking*` entre deux appels, sinon on relit le rendu précédent.
- **`CommonObject::__clone()` itère `$this->lines` de 0 à `count - 1`.** Toute méthode remplissant
  `lines` doit donc retourner une liste indexée numériquement, jamais un tableau associatif :
  `setStatusCommon()` clone l'objet pour construire `oldcopy` et échouerait.
- `fetchCommon()` retourne le **rowid** quand il trouve, pas `1`. Le contrat des `fetch()` du module
  est donc `>0` trouvé, `0` non trouvé, `<0` erreur.
- **Les règles de jeu et le `K` sont des constantes, pas des réglages.** `BabyfootConfig::SCORE_MAX`
  et `BabyfootConfig::ELO_K` ; le vainqueur doit atteindre `SCORE_MAX`, le nul est refusé, aucun
  seuil pour être classé. Ne pas les réintroduire dans `resolve()`, qui ne porte plus que
  `elo_initial`, `prefill_current_user` et `default_mode`.
- **`User::hasRight()` retourne 0 si `isModEnabled($module)` est faux**, donc pour toute entité où le
  module n'est pas activé. Un test PHPUnit portant sur un droit doit forcer
  `$conf->modules['babyfoot']` lui-même : c'est ce qui faisait échouer les tests de droits, sans
  aucun rapport avec le code testé.
- `recomputeAll()` ouvre sa propre transaction et peut être appelée depuis celle de `Game::create()`.
  L'imbrication `begin`/`commit` de DoliDB est comptée par références, mais un `rollback()` interne
  annule tout — comportement voulu : si le recalcul échoue, la partie ne s'enregistre pas.

## Hors périmètre

Saisie but par but, saisons, validation par l'adversaire, tournois, notifications.
