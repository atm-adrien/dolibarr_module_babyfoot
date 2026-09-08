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
// RG-19 dropped: a single game is enough to be ranked
$ranking = $repository->getRanking($mode, 0, $dateFrom, 0);
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
 * Resolve the display names of every player of the screen, in one pass.
 *
 * Called once, before rendering: resolving a name inside the display loop would
 * mean one query per row, which section 9 forbids.
 *
 * @param	DoliDB		$db			Database handler
 * @param	Translate	$langs		Translation object
 * @param	array		$rowsets	Lists of rows holding a fk_user key
 * @return	array<int,string>		Names indexed by user id, escaped for HTML
 */
function babyfootRankingPlayerNames($db, $langs, array $rowsets)
{
	$names = array();

	foreach ($rowsets as $rows) {
		if (!is_array($rows) || empty($rows)) {
			continue;
		}
		foreach ($rows as $row) {
			$userId = (int) $row['fk_user'];
			if (isset($names[$userId])) {
				continue;
			}
			$player = new User($db);
			$label = ($player->fetch($userId) > 0) ? $player->getFullName($langs) : '#'.$userId;
			$names[$userId] = dol_escape_htmltag($label);
		}
	}

	return $names;
}

/**
 * Render one ranking table row.
 *
 * @param	array		$row			Row returned by the repository
 * @param	int			$position		Zero based position in the ranking
 * @param	int			$variation		Rank variation since the last game
 * @param	int|null	$streak			Streak to display, null to use the row one
 * @param	array		$names			Player names indexed by user id
 * @return	void
 */
function babyfootRankingRow($row, $position, $variation, $streak, array $names)
{
	$cssRank = ($position < 3) ? ' babyfoot-rank-'.($position + 1) : '';

	print '<tr class="oddeven'.$cssRank.'">';

	print '<td class="center">'.($position + 1).'</td>';

	print '<td class="center">';
	if ($variation > 0) {
		print '<span class="babyfoot-up">&uarr; '.((int) $variation).'</span>';
	} elseif ($variation < 0) {
		print '<span class="babyfoot-down">&darr; '.abs((int) $variation).'</span>';
	} else {
		print '<span class="babyfoot-flat">=</span>';
	}
	print '</td>';

	$userId = (int) $row['fk_user'];
	print '<td><a href="'.dol_buildpath('/babyfoot/player_card.php', 1).'?id='.$userId.'">';
	print isset($names[$userId]) ? $names[$userId] : ('#'.$userId);
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

// Resolve every player name once, before rendering anything: doing it inside the
// display loop would mean one query per row, which section 9 forbids
$playerNames = babyfootRankingPlayerNames($db, $langs, array($ranking));

llxHeader('', $langs->trans('BabyfootMenuRanking'), '', '', 0, 0, array(), array());

print load_fiche_titre($langs->trans('BabyfootMenuRanking'), '', 'fa-futbol');

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
if (empty($ranking)) {
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
	print dol_escape_htmltag($langs->trans('BabyfootNobodyRankedYet'));
	print '</td></tr>';
}

foreach ($ranking as $position => $row) {
	$userId = (int) $row['fk_user'];
	$variation = isset($variations[$userId]) ? (int) $variations[$userId] : 0;
	$streak = ($mode === BabyfootConfig::MODE_ALL) ? null : (isset($overallStreaks[$userId]) ? (int) $overallStreaks[$userId] : 0);
	babyfootRankingRow($row, $position, $variation, $streak, $playerNames);
}

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
