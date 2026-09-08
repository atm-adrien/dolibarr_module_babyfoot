# ChangeLog — Module Babyfoot

## 1.0.0

Première version.

### Fonctionnalités

- Saisie rapide d'une partie en 1v1 ou 2v2, score final uniquement, pensée pour
  un usage mobile en quinze secondes.
- Liste, consultation, modification, annulation et suppression des parties.
- Classement Elo tenu en parallèle pour le 1v1, le 2v2 et l'ensemble des parties.
- Statistiques individuelles : compteurs, courbe d'évolution de l'Elo, meilleur
  coéquipier, bête noire, victime préférée, dix dernières parties.
- Statistiques collectives : totaux, meilleurs duos, parties les plus serrées et
  les plus larges, tableau des fannys, répartition par jour et par heure, joueur
  le plus actif.
- Widget de tableau de bord : top 5, rang personnel, trois dernières parties.
- Écran d'administration : les douze paramètres, le recalcul complet du
  classement et la vérification de cohérence.
- Recalcul complet en ligne de commande (`scripts/recompute_ratings.php`).

### Écarts assumés à la spécification

- **D4** — aucun recalcul partiel n'est implémenté. Toute modification qui n'est
  pas la continuation de la chronologie déclenche un recalcul complet. Le
  résultat est identique à celui attendu par RG-30, avec un seul chemin de code
  au lieu de deux.
- **D9** — trois colonnes supplémentaires (`elo_all_before`, `elo_all_after`,
  `elo_all_delta`) sur `llx_babyfoot_game_player`, sans lesquelles la courbe
  d'Elo du classement général exigerait un rejeu à chaque affichage.
- **D10** — RG-14 et RG-15 étaient incompatibles en 2v2. RG-15 prime : les deux
  joueurs d'un camp partagent la même espérance de gain mais conservent leur
  propre coefficient K, donc leurs variations d'Elo diffèrent en amplitude tout
  en restant de même sens.
- **D11** — la série affichée est toujours celle du classement général, conforme
  au glossaire, alors qu'elle est stockée pour les trois classements.
- **D12** — la flèche d'évolution du rang se calcule par rapport au rang détenu
  juste avant la dernière partie du joueur, point que la spécification ne
  précisait pas.
- **D13** — le filtre « mes parties » retient les parties où l'utilisateur a
  joué, et non celles qu'il a saisies.
- **D14** — RG-16 était tronquée. L'écart retenu est `|score1 - score2|`, avec un
  facteur planché à 1.0 pour qu'une partie serrée ne réduise jamais K sous sa
  valeur nominale.

### Corrections issues de l'audit

- L'avertissement exigé au § 9 lorsqu'une partie antidatée est saisie n'était pas
  affiché : la clé de traduction existait mais n'était utilisée nulle part. Ajout
  de `RatingEngine::isLatestDate()`, qui répond avant l'insertion, là où
  `isLatest()` exige une partie déjà stockée.
- Suppression d'un N+1 de requêtes sur le classement et sur les statistiques
  collectives : les noms de joueurs étaient résolus dans la boucle d'affichage,
  soit une requête par ligne, et deux par ligne du tableau des duos. Ils sont
  désormais résolus en une passe avant le rendu, comme le faisait déjà la liste
  des parties.
- Suppression du code mort laissé dans l'écran de saisie par l'avertissement
  oublié.

### Ergonomie de l'écran de saisie

- L'icône du module est désormais le picto natif `fa-futbol` de FontAwesome, qui
  suit la couleur du thème, au lieu du PNG 14x14 embarqué. Là où Dolibarr passe
  la valeur à `img_object()`, elle est préfixée d'un `^` pour éviter le préfixe
  `object_` automatique.
- Les deux camps sont présentés en vis-à-vis, camp bleu à gauche et camp rouge à
  droite, séparés par un « VS ». La grille n'est plus cassée en lignes sur
  mobile : seules les tailles se réduisent.
- Suppression des boutons `+` et `−` du score. Un seul champ numérique par camp,
  ce qui retire un aller-retour au pouce et supprime le helper JavaScript associé.
- Le bouton d'enregistrement passe par `Form::buttonsSaveCancel()`, sans bouton
  Annuler : mêmes classes et même positionnement que partout ailleurs dans
  Dolibarr.
- Correction de `css/babyfoot.css.php` et `js/babyfoot.js.php` :
  `session_cache_limiter('public')` était appelé après `main.inc.php`, donc une
  fois la session ouverte. PHP émettait un warning en tête des deux fichiers, ce
  qui faisait avaler la première règle CSS par la récupération d'erreur du
  parseur — la grille des camps — et empêchait purement et simplement le JS de se
  parser. L'appel précède désormais l'inclusion, comme dans `theme/eldy/style.css.php`.
- L'écran ne propose plus de choisir la date ni l'heure : une partie est
  enregistrée à l'instant où elle est saisie. L'antidatage reste possible depuis
  la fiche de la partie, ce qui laisse au garde-fou `isLatestDate()` son utilité.
- Correction de l'alignement de la flèche des listes de joueurs. Elle est
  positionnée en absolu par select2 contre `.select2-container` : étirer le seul
  conteneur la renvoyait à l'extrémité du camp. Le conteneur et
  `.select2-selection--single` sont désormais étirés ensemble.
- Reprise du rendu de l'écran : sélecteur de mode en contrôle segmenté, camps en
  cartes avec liseré de couleur, pastille VS circulaire, champ de score encadré
  avec son libellé, formulaire borné à 700 px. Les couleurs s'appuient sur les
  variables du thème Dolibarr, ce qui rend l'écran lisible en thème sombre.
- Réorganisation du menu de gauche sur deux niveaux : **Parties** portant
  **Créer une partie** et **Liste des parties**, **Joueurs** portant **Liste des
  joueurs** et **Classement**, et **Statistiques** au premier niveau puisque les
  statistiques collectives ne décrivent aucun joueur en particulier. Chaque parent
  pointe la liste correspondante, comme le veut la convention Dolibarr. Les menus
  étant stockés en base à l'activation, la nouvelle arborescence n'apparaît
  qu'après un cycle désactivation/réactivation.
- Nouvel écran **Liste des joueurs** (`player_list.php`), annuaire trié par nom
  avec colonnes triables et lien vers la fiche. Il répond à « où est la fiche de
  ce joueur », là où le classement répond à « qui joue le mieux ». Alimenté par
  `RankingRepository::getPlayerList()`, dont la clé de tri est filtrée par liste
  blanche et le sens réduit à `ASC`/`DESC`. La jointure sur `user` est volontairement
  externe : un joueur dont le compte a été supprimé pèse encore dans le classement
  et doit rester listé, comme le fait déjà l'écran de classement. Six tests PHPUnit.
- Déclaration explicite de `module_parts['icon']`, qui alimente
  `MAIN_MODULE_BABYFOOT_ICON` : sans elle le thème écrase l'icône du menu du
  bandeau par un glyphe générique. La déduction automatique de
  `DolibarrModules::insert_module_parts()` ne pouvait pas s'appliquer, son test
  `/^fa-/` échouant sur le `'^fa-futbol'` exigé par `img_object()`.

### Simplification du paramétrage

Le module passe de douze paramètres à trois. Ce qui relève de la règle du jeu n'est plus
réglable mais inscrit dans le code, comme constante de `BabyfootConfig`.

- Supprimés au profit de constantes : `BABYFOOT_SCORE_MAX` (une partie se joue toujours en
  `BabyfootConfig::SCORE_MAX` = 10 buts), `BABYFOOT_SCORE_EXACT` (le vainqueur doit toujours
  atteindre ce total) et `BABYFOOT_ALLOW_DRAW` (un nul est toujours refusé). `GameValidator`
  ne lit plus aucun réglage : son constructeur perd son troisième argument.
- Supprimés purement : `BABYFOOT_ELO_K`, `BABYFOOT_ELO_K_NOVICE` et
  `BABYFOOT_ELO_NOVICE_GAMES`. Un coefficient unique `BabyfootConfig::ELO_K = 40` s'applique
  à tout le monde. `EloCalculator` perd `coefficient()` et trois propriétés, son constructeur
  ne prend plus qu'un entier, et `delta()` ne reçoit plus ni le nombre de parties jouées ni
  les scores. Conséquence voulue : les deux joueurs d'un même camp reçoivent désormais
  exactement la même variation, et les trois lignes de classement d'un joueur bougent du même
  nombre de points. La décision D10 de la spécification tombe.
- Supprimé : `BABYFOOT_ELO_MARGIN`, avec `EloCalculator::marginFactor()`. L'écart de buts ne
  pèse plus jamais sur l'Elo. La décision D14 tombe.
- Supprimé : `BABYFOOT_MIN_GAMES_RANKED`. On figure au classement dès la première partie, donc
  la section repliée « joueurs non classés » et `RankingRepository::getUnranked()` disparaissent.
- Supprimé : `BABYFOOT_EDIT_DELAY`, ainsi que le droit `modify_own`. `Game::canBeEditedBy()` se
  résume au droit `modify_all` : ni la qualité d'auteur ni l'ancienneté de la partie ne donnent
  de privilège. Le module passe de cinq à quatre droits.
- Conservés : `BABYFOOT_ELO_INITIAL`, `BABYFOOT_PREFILL_CURRENT_USER` et `BABYFOOT_DEFAULT_MODE`.

Les colonnes `nb_draws` de `babyfoot_rating` et la constante `EloCalculator::RESULT_DRAW`
subsistent : un nul ne peut plus être enregistré, mais retirer la colonne demanderait une
migration de schéma, hors périmètre de ce lot.

### Notes techniques

- 73 tests PHPUnit, 221 assertions, suite entièrement verte. Les deux échecs qui
  traînaient sur `GameTest` ne venaient pas du module : `User::hasRight()` retourne 0
  dès que `isModEnabled()` est faux, ce qui est le cas dans l'entité où tourne la suite.
  Le test de droit force désormais `$conf->modules['babyfoot']` lui-même.
- Parmi ces tests, les trois critères d'acceptation les
  plus sensibles : +20/-20 sur une première partie, idempotence du recalcul
  complet, et suppression d'une partie ancienne restituant exactement le
  classement qui aurait existé sans elle.
- Mesures sur 200 parties : 20 ms par saisie sur le chemin incrémental, 0,19 s
  pour un recalcul complet, toutes les requêtes d'affichage sous 20 ms.
