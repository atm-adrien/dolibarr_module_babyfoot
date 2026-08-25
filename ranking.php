<?php
/* Copyright (C) 2026 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ranking.php
 * \ingroup babyfoot
 * \brief   Elo ranking, one tab per mode, with an optional period filter.
 */

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dirname(__FILE__).'/class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/class/stats/rankingrepository.class.php';
require_once dirname(__FILE__).'/lib/babyfoot.lib.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$config = BabyfootConfig::resolve();

$mode = GETPOST('mode', 'aZ09');
if (!in_array($mode, BabyfootConfig::ratingModes(), true)) {
	$mode = BabyfootConfig::MODE_ALL;
}

// Period filter. "All" is the default: it is the cheapest view, since it reads
// the cache table instead of aggregating the games.
$period = GETPOST('period', 'aZ09');
$allowedPeriods = array('all', '30', '90', '365');
if (!in_array($period, $allowedPeriods, true)) {
	$period = 'all';
}
$dateFrom = ($period === 'all') ? 0 : (dol_now() - ((int) $period * 24 * 3600));

$repository = new RankingRepository($db, (int) $conf->entity);
$ranking = $repository->getRanking($mode, (int) $config['min_games_ranked'], $dateFrom, 0);
$unranked = $repository->getUnranked($mode, (int) $config['min_games_ranked']);
$variations = $repository->getRankVariationSinceLastGame($mode, $ranking);

// Decision D11: the streak shown is always the overall one, whatever the tab
$overallStreaks = array();
if ($mode !== BabyfootConfig::MODE_ALL) {
	$overall = $repository->getRanking(BabyfootConfig::MODE_ALL, 0, 0, 0);
	foreach ($overall as $row) {
		$overallStreaks[(int) $row['fk_user']] = (int) $row['current_streak'];
	}
}

/**
 * Resolve the display name of a player.
 *
 * @param	DoliDB		$db			Database handler
 * @param	Translate	$langs		Translation object
 * @param	int			$userId		Rowid of the Dolibarr user
 * @return	string					Name, escaped for HTML output
 */
function babyfootRankingPlayerName($db, $langs, $userId)
{
	$player = new User($db);
	$name = ($player->fetch((int) $userId) > 0) ? $player->getFullName($langs) : '#'.((int) $userId);

	return dol_escape_htmltag($name);
}

/**
 * Render one ranking table row.
 *
 * @param	array		$row			Row returned by the repository
 * @param	int			$position		Zero based position in the ranking
 * @param	int			$variation		Rank variation since the last game
 * @param	int|null	$streak			Streak to display, null to use the row one
 * @param	bool		$isUnranked		Render as an unranked player
 * @param	DoliDB		$db				Database handler
 * @param	Translate	$langs			Translation object
 * @return	void
 */
function babyfootRankingRow($row, $position, $variation, $streak, $isUnranked, $db, $langs)
{
	$cssRank = '';
	if (!$isUnranked && $position < 3) {
		$cssRank = ' babyfoot-rank-'.($position + 1);
	}

	print '<tr class="oddeven'.($isUnranked ? ' babyfoot-unranked' : '').$cssRank.'">';

	print '<td class="center">'.($isUnranked ? '-' : ($position + 1)).'</td>';

	print '<td class="center">';
	if (!$isUnranked) {
		if ($variation > 0) {
			print '<span class="babyfoot-up">&uarr; '.((int) $variation).'</span>';
		} elseif ($variation < 0) {
			print '<span class="babyfoot-down">&darr; '.abs((int) $variation).'</span>';
		} else {
			print '<span class="babyfoot-flat">=</span>';
		}
	}
	print '</td>';

	print '<td><a href="'.dol_buildpath('/babyfoot/player_card.php', 1).'?id='.((int) $row['fk_user']).'">';
	print babyfootRankingPlayerName($db, $langs, (int) $row['fk_user']);
	print '</a></td>';

	print '<td class="center"><strong>'.((int) $row['elo']).'</strong>';
	if (isset($row['elo_variation']) && !is_null($row['elo_variation'])) {
		$variationLabel = (((int) $row['elo_variation']) >= 0 ? '+' : '').((int) $row['elo_variation']);
		print ' <span class="opacitymedium">('.dol_escape_htmltag($variationLabel).')</span>';
	}
	print '</td>';

	print '<td class="center">'.((int) $row['nb_games']).'</td>';
	print '<td class="center">'.((int) $row['nb_wins']).' / '.((int) $row['nb_losses']).'</td>';
	print '<td class="center">'.price2num(((float) $row['ratio']) * 100, 1).' %</td>';
	print '<td class="center">'.(((int) $row['goal_diff']) > 0 ? '+' : '').((int) $row['goal_diff']).'</td>';

	$shownStreak = is_null($streak) ? (int) $row['current_streak'] : (int) $streak;
	print '<td class="center">'.($shownStreak > 0 ? '+'.$shownStreak : $shownStreak).'</td>';

	print '<td class="center nowraponall">';
	print empty($row['date_last_game']) ? '' : dol_print_date($row['date_last_game'], 'day');
	print '</td>';

	print '</tr>';
}


/*
 * View
 */

llxHeader('', $langs->trans('BabyfootMenuRanking'), '', '', 0, 0, array(), array());

print load_fiche_titre($langs->trans('BabyfootMenuRanking'), '', 'babyfoot@babyfoot');

$head = babyfootRankingPrepareHead($mode);
print dol_get_fiche_head($head, $mode, '', -1, '');

// Period selector
print '<div class="babyfoot-period-selector paddingbottom">';
$periodLabels = array(
	'30' => $langs->trans('BabyfootPeriod30'),
	'90' => $langs->trans('BabyfootPeriod90'),
	'365' => $langs->trans('BabyfootPeriodYear'),
	'all' => $langs->trans('BabyfootPeriodAll'),
);
foreach ($periodLabels as $value => $label) {
	$css = ($value === $period) ? ' class="babyfoot-winner"' : '';
	print '<a href="'.$_SERVER['PHP_SELF'].'?mode='.urlencode($mode).'&period='.urlencode($value).'"'.$css.'>';
	print dol_escape_htmltag($label);
	print '</a> &nbsp; ';
}
print '</div>';

// Empty state (section 9)
if (empty($ranking) && empty($unranked)) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootNoRankingYet')).'<br><br>';
	print '<a class="button" href="'.dol_buildpath('/babyfoot/game_quickadd.php', 1).'">';
	print dol_escape_htmltag($langs->trans('BabyfootRecordFirstGame'));
	print '</a>';
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

print '<tr class="liste_titre">';
print '<th class="center">'.$langs->trans('BabyfootRank').'</th>';
print '<th class="center"></th>';
print '<th>'.$langs->trans('BabyfootPlayer').'</th>';
print '<th class="center">'.$langs->trans('BabyfootElo').'</th>';
print '<th class="center">'.$langs->trans('BabyfootNbGames').'</th>';
print '<th class="center">'.$langs->trans('BabyfootWinsLosses').'</th>';
print '<th class="center">'.$langs->trans('BabyfootRatio').'</th>';
print '<th class="center">'.$langs->trans('BabyfootGoalDiff').'</th>';
print '<th class="center">'.$langs->trans('BabyfootCurrentStreak').'</th>';
print '<th class="center">'.$langs->trans('BabyfootDateLastGame').'</th>';
print '</tr>';

if (empty($ranking)) {
	print '<tr class="oddeven"><td colspan="10" class="opacitymedium center">';
	print dol_escape_htmltag($langs->trans('BabyfootNobodyRankedYet', (int) $config['min_games_ranked']));
	print '</td></tr>';
}

foreach ($ranking as $position => $row) {
	$userId = (int) $row['fk_user'];
	$variation = isset($variations[$userId]) ? (int) $variations[$userId] : 0;
	$streak = ($mode === BabyfootConfig::MODE_ALL) ? null : (isset($overallStreaks[$userId]) ? (int) $overallStreaks[$userId] : 0);
	babyfootRankingRow($row, $position, $variation, $streak, false, $db, $langs);
}

print '</table>';
print '</div>';

// Unranked players (RG-19), in a collapsed section
if (!empty($unranked)) {
	print '<br>';
	print '<div class="div-table-responsive">';
	print '<a href="#" onclick="babyfootToggleDateBox(\'babyfoot-unranked-table\'); return false;">';
	print dol_escape_htmltag($langs->trans('BabyfootUnrankedPlayers', count($unranked)));
	print '</a>';
	print '<div id="babyfoot-unranked-table" class="hideobject">';
	print '<table class="tagtable liste">';
	print '<tr class="liste_titre">';
	print '<th class="center">'.$langs->trans('BabyfootRank').'</th>';
	print '<th class="center"></th>';
	print '<th>'.$langs->trans('BabyfootPlayer').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootElo').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootNbGames').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootWinsLosses').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootRatio').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootGoalDiff').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootCurrentStreak').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootDateLastGame').'</th>';
	print '</tr>';

	foreach ($unranked as $position => $row) {
		$userId = (int) $row['fk_user'];
		$streak = ($mode === BabyfootConfig::MODE_ALL) ? null : (isset($overallStreaks[$userId]) ? (int) $overallStreaks[$userId] : 0);
		babyfootRankingRow($row, $position, 0, $streak, true, $db, $langs);
	}

	print '</table>';
	print '</div>';
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
