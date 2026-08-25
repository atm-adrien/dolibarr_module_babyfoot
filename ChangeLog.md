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

### Notes techniques

- 68 tests PHPUnit, 202 assertions, dont les trois critères d'acceptation les
  plus sensibles : +20/-20 sur une première partie, idempotence du recalcul
  complet, et suppression d'une partie ancienne restituant exactement le
  classement qui aurait existé sans elle.
- Mesures sur 200 parties : 20 ms par saisie sur le chemin incrémental, 0,19 s
  pour un recalcul complet, toutes les requêtes d'affichage sous 20 ms.
