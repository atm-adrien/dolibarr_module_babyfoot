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
 * \file    stats.php
 * \ingroup babyfoot
 * \brief   Collective statistics: totals, top duos, fannies, when people play.
 */

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
require_once dirname(__FILE__).'/class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/class/game.class.php';
require_once dirname(__FILE__).'/class/stats/globalstatsrepository.class.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$repository = new GlobalStatsRepository($db, (int) $conf->entity);
$totals = $repository->getTotals();

/**
 * Resolve the display names of a set of players, in one pass.
 *
 * Resolving a name inside a display loop would mean one query per row, and the
 * duo table alone needs two names per row.
 *
 * @param	DoliDB		$db			Database handler
 * @param	Translate	$langs		Translation object
 * @param	int[]		$userIds	Player ids to resolve
 * @return	array<int,string>		Names indexed by user id, escaped for HTML
 */
function babyfootStatsPlayerNames($db, $langs, array $userIds)
{
	$names = array();

	foreach ($userIds as $userId) {
		$userId = (int) $userId;
		if ($userId <= 0 || isset($names[$userId])) {
			continue;
		}
		$player = new User($db);
		$label = ($player->fetch($userId) > 0) ? $player->getFullName($langs) : '#'.$userId;
		$names[$userId] = dol_escape_htmltag($label);
	}

	return $names;
}

/**
 * Return a resolved player name, falling back on the raw id.
 *
 * @param	array	$names		Names indexed by user id
 * @param	int		$userId		Player id
 * @return	string				Name, escaped for HTML output
 */
function babyfootStatsName(array $names, $userId)
{
	$userId = (int) $userId;

	return isset($names[$userId]) ? $names[$userId] : ('#'.$userId);
}

/**
 * Render a table of games ordered by goal gap.
 *
 * @param	array		$games		Games to render
 * @param	string		$titleKey	Translation key of the section title
 * @param	DoliDB		$db			Database handler
 * @param	Translate	$langs		Translation object
 * @return	void
 */
function babyfootStatsGameTable($games, $titleKey, $db, $langs)
{
	print load_fiche_titre($langs->trans($titleKey), '', '');

	if (empty($games)) {
		print '<div class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootNoGameYet')).'</div>';
		return;
	}

	$gameObject = new Game($db);

	print '<div class="div-table-responsive"><table class="tagtable liste">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Ref').'</th>';
	print '<th>'.$langs->trans('BabyfootDateGame').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootScore').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootGoalGap').'</th>';
	print '</tr>';

	foreach ($games as $row) {
		$gameObject->id = (int) $row['rowid'];
		$gameObject->ref = $row['ref'];

		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.$gameObject->getNomUrl(1).'</td>';
		print '<td class="nowraponall">'.dol_print_date($row['date_game'], 'dayhour').'</td>';
		print '<td class="center">'.((int) $row['score_team1']).' - '.((int) $row['score_team2']).'</td>';
		print '<td class="center">'.((int) $row['gap']).'</td>';
		print '</tr>';
	}

	print '</table></div><br>';
}


/*
 * View
 */

llxHeader('', $langs->trans('BabyfootMenuStats'), '', '', 0, 0, array(), array());

print load_fiche_titre($langs->trans('BabyfootMenuStats'), '', 'babyfoot@babyfoot');

// Empty state (section 9): no chart at all rather than empty charts
if ((int) $totals['nb_games'] === 0) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootNoGameYet')).'<br><br>';
	print '<a class="button" href="'.dol_buildpath('/babyfoot/game_quickadd.php', 1).'">';
	print dol_escape_htmltag($langs->trans('BabyfootRecordFirstGame'));
	print '</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

// Load the data sets first, so every player name is resolved in a single pass
$mostActive = $repository->getMostActivePlayer(30);
$duos = $repository->getTopDuos(10);
$fannies = $repository->getFannyTable();

$userIds = array();
if (!is_null($mostActive)) {
	$userIds[] = (int) $mostActive['fk_user'];
}
foreach ($duos as $duo) {
	$userIds[] = (int) $duo['user1'];
	$userIds[] = (int) $duo['user2'];
}
foreach ($fannies as $fanny) {
	$userIds[] = (int) $fanny['fk_user'];
}
$playerNames = babyfootStatsPlayerNames($db, $langs, $userIds);

// Totals
print '<div class="fichecenter">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('BabyfootTotalGames').'</td><td>'.((int) $totals['nb_games']).'</td></tr>';
print '<tr><td>'.$langs->trans('BabyfootTotalGoals').'</td><td>'.((int) $totals['nb_goals']).'</td></tr>';
print '<tr><td>'.$langs->trans('BabyfootAvgGoalsPerGame').'</td><td>'.price2num((float) $totals['avg_goals'], 2).'</td></tr>';

print '<tr><td>'.$langs->trans('BabyfootMostActivePlayer').'</td><td>';
if (is_null($mostActive)) {
	print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootNoGameYet')).'</span>';
} else {
	print '<a href="'.dol_buildpath('/babyfoot/player_card.php', 1).'?id='.((int) $mostActive['fk_user']).'">';
	print babyfootStatsName($playerNames, $mostActive['fk_user']);
	print '</a> <span class="opacitymedium">('.((int) $mostActive['nb']).')</span>';
}
print '</td></tr>';
print '</table>';
print '<br>';

// Top duos
print load_fiche_titre($langs->trans('BabyfootTopDuos'), '', '');
if (empty($duos)) {
	print '<div class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootNotEnoughDataDuos', GlobalStatsRepository::MIN_DUO_GAMES)).'</div><br>';
} else {
	print '<div class="div-table-responsive"><table class="tagtable liste">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('BabyfootDuo').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootNbGames').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootNbWins').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootRatio').'</th>';
	print '</tr>';
	foreach ($duos as $duo) {
		print '<tr class="oddeven">';
		print '<td>'.babyfootStatsName($playerNames, $duo['user1']).' &amp; '.babyfootStatsName($playerNames, $duo['user2']).'</td>';
		print '<td class="center">'.((int) $duo['nb']).'</td>';
		print '<td class="center">'.((int) $duo['wins']).'</td>';
		print '<td class="center">'.price2num(((float) $duo['ratio']) * 100, 1).' %</td>';
		print '</tr>';
	}
	print '</table></div><br>';
}

// Tightest and widest games
babyfootStatsGameTable($repository->getTightestGames(5), 'BabyfootTightestGames', $db, $langs);
babyfootStatsGameTable($repository->getWidestGames(5), 'BabyfootWidestGames', $db, $langs);

// Fanny table
print load_fiche_titre($langs->trans('BabyfootFannyTable'), '', '');
if (empty($fannies)) {
	print '<div class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootNoFannyYet')).'</div><br>';
} else {
	print '<div class="div-table-responsive"><table class="tagtable liste">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('BabyfootPlayer').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootFannyGiven').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootFannyTaken').'</th>';
	print '</tr>';
	foreach ($fannies as $row) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/babyfoot/player_card.php', 1).'?id='.((int) $row['fk_user']).'">';
		print babyfootStatsName($playerNames, $row['fk_user']);
		print '</a></td>';
		print '<td class="center">'.((int) $row['given']).'</td>';
		print '<td class="center">'.((int) $row['taken']).'</td>';
		print '</tr>';
	}
	print '</table></div><br>';
}

// When do people actually play
$byWeekday = $repository->getDistributionByWeekday();
$weekdayData = array();
foreach ($byWeekday as $isoDay => $count) {
	// Day1 to Day7 are core translation keys, Day1 being Monday
	$weekdayData[] = array($langs->trans('Day'.$isoDay), $count);
}

$graphWeekday = new DolGraph();
if (!$graphWeekday->isGraphKo()) {
	$graphWeekday->SetData($weekdayData);
	$graphWeekday->SetType(array('bars'));
	$graphWeekday->SetLegend(array($langs->trans('BabyfootNbGames')));
	$graphWeekday->SetTitle($langs->trans('BabyfootGamesByWeekday'));
	$graphWeekday->SetWidth(700);
	$graphWeekday->SetHeight(200);
	$graphWeekday->SetMaxValue($graphWeekday->GetCeilMaxValue());
	$graphWeekday->SetMinValue(0);
	$graphWeekday->draw('babyfoot_by_weekday');
	print $graphWeekday->show();
	print '<br>';
}

$byHour = $repository->getDistributionByHour();
$hourData = array();
foreach ($byHour as $hour => $count) {
	$hourData[] = array(sprintf('%02dh', $hour), $count);
}

$graphHour = new DolGraph();
if (!$graphHour->isGraphKo()) {
	$graphHour->SetData($hourData);
	$graphHour->SetType(array('bars'));
	$graphHour->SetLegend(array($langs->trans('BabyfootNbGames')));
	$graphHour->SetTitle($langs->trans('BabyfootGamesByHour'));
	$graphHour->SetWidth(700);
	$graphHour->SetHeight(200);
	$graphHour->SetMaxValue($graphHour->GetCeilMaxValue());
	$graphHour->SetMinValue(0);
	$graphHour->draw('babyfoot_by_hour');
	print $graphHour->show();
}

print '</div>';

llxFooter();
$db->close();
