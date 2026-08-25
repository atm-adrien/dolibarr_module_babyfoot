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
 * \file    player_card.php
 * \ingroup babyfoot
 * \brief   Player card: counters, Elo chart, head to head records, last games.
 */

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
require_once dirname(__FILE__).'/class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/class/game.class.php';
require_once dirname(__FILE__).'/class/stats/playerstatsrepository.class.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$playerId = GETPOSTINT('id');
$config = BabyfootConfig::resolve();
$repository = new PlayerStatsRepository($db, (int) $conf->entity);

$player = new User($db);
$playerFound = ($playerId > 0) ? $player->fetch($playerId) : 0;

$ratings = ($playerId > 0) ? $repository->getRatings($playerId) : array();

/**
 * Render a head to head section, or an explicit "not enough data" message.
 *
 * @param	array|null	$relation		Relation returned by the repository
 * @param	string		$labelKey		Translation key of the section title
 * @param	DoliDB		$db				Database handler
 * @param	Translate	$langs			Translation object
 * @return	void
 */
function babyfootRelationBlock($relation, $labelKey, $db, $langs)
{
	print '<tr><td class="titlefield">'.$langs->trans($labelKey).'</td><td>';

	if (is_null($relation)) {
		print '<span class="opacitymedium">';
		print dol_escape_htmltag($langs->trans('BabyfootNotEnoughData', PlayerStatsRepository::MIN_SHARED_GAMES));
		print '</span>';
	} else {
		$other = new User($db);
		$name = ($other->fetch((int) $relation['fk_user']) > 0) ? $other->getFullName($langs) : '#'.((int) $relation['fk_user']);
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.((int) $relation['fk_user']).'">'.dol_escape_htmltag($name).'</a>';
		print ' <span class="opacitymedium">('.((int) $relation['wins']).'/'.((int) $relation['nb']).', ';
		print price2num(((float) $relation['ratio']) * 100, 1).' %)</span>';
	}

	print '</td></tr>';
}


/*
 * View
 */

llxHeader('', $langs->trans('BabyfootPlayer'), '', '', 0, 0, array(), array());

// Section 9: an unknown player, or a player without history, is an explicit
// empty state and never a PHP error. A deactivated or deleted Dolibarr user
// keeps their card as long as they have games.
if ($playerId <= 0 || ($playerFound <= 0 && empty($ratings))) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootPlayerNotFound')).'<br><br>';
	print '<a class="button" href="'.dol_buildpath('/babyfoot/ranking.php', 1).'">';
	print dol_escape_htmltag($langs->trans('BabyfootMenuRanking'));
	print '</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

$playerName = ($playerFound > 0) ? $player->getFullName($langs) : '#'.$playerId;

print load_fiche_titre(dol_escape_htmltag($playerName), '', 'user');

print '<div class="fichecenter">';

// Header: photo and the current Elo of every played mode
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';

if ($playerFound > 0) {
	print '<tr><td class="titlefield">'.$langs->trans('Photo').'</td><td>';
	print Form::showphoto('userphoto', $player, 80);
	print '</td></tr>';
}

foreach (BabyfootConfig::ratingModes() as $mode) {
	// A mode never played has no row at all: do not display it (section 9)
	if (!isset($ratings[$mode])) {
		continue;
	}
	$rating = $ratings[$mode];
	$modeLabel = ($mode === BabyfootConfig::MODE_ALL)
		? $langs->trans('BabyfootRankingOverall')
		: $langs->trans($mode === BabyfootConfig::MODE_1V1 ? 'Babyfoot1v1' : 'Babyfoot2v2');

	print '<tr><td class="titlefield">'.dol_escape_htmltag($modeLabel).'</td><td>';
	print '<strong>'.((int) $rating['elo']).'</strong>';
	print ' <span class="opacitymedium">('.$langs->trans('BabyfootEloPeak').' '.((int) $rating['elo_peak']).')</span>';
	print ' &nbsp; '.((int) $rating['nb_games']).' '.$langs->trans('BabyfootNbGames');
	print ' &nbsp; '.((int) $rating['nb_wins']).' / '.((int) $rating['nb_losses']);
	print ' &nbsp; '.price2num(((float) $rating['ratio']) * 100, 1).' %';
	print '</td></tr>';
}

// Counters of the overall ranking (decision D11: the streak is the overall one)
if (isset($ratings[BabyfootConfig::MODE_ALL])) {
	$overall = $ratings[BabyfootConfig::MODE_ALL];

	print '<tr><td>'.$langs->trans('BabyfootGoalsFor').' / '.$langs->trans('BabyfootGoalsAgainst').'</td><td>';
	print ((int) $overall['goals_for']).' / '.((int) $overall['goals_against']);
	print ' <span class="opacitymedium">('.$langs->trans('BabyfootAvgGoalDiff').' ';
	print price2num((float) $overall['avg_goal_diff'], 2).')</span>';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('BabyfootCurrentStreak').'</td><td>';
	print (((int) $overall['current_streak']) > 0 ? '+' : '').((int) $overall['current_streak']);
	print ' <span class="opacitymedium">('.$langs->trans('BabyfootBestStreak').' '.((int) $overall['best_streak']).')</span>';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('BabyfootFannyGiven').' / '.$langs->trans('BabyfootFannyTaken').'</td><td>';
	print ((int) $overall['nb_fanny_given']).' / '.((int) $overall['nb_fanny_taken']);
	print '</td></tr>';
}

// Head to head records
babyfootRelationBlock($repository->getBestTeammate($playerId), 'BabyfootBestTeammate', $db, $langs);
babyfootRelationBlock($repository->getNemesis($playerId), 'BabyfootNemesis', $db, $langs);
babyfootRelationBlock($repository->getFavouriteVictim($playerId), 'BabyfootFavouriteVictim', $db, $langs);

print '</table>';

// Elo chart, built from the stored snapshots: no computation here
$history = $repository->getEloHistory($playerId, BabyfootConfig::MODE_ALL);
if (count($history) > 1) {
	$data = array();
	foreach ($history as $point) {
		$data[] = array(dol_print_date($point['date'], 'day'), $point['elo']);
	}

	$graph = new DolGraph();
	$errorMessage = $graph->isGraphKo();
	if (!$errorMessage) {
		$graph->SetData($data);
		$graph->SetLegend(array($langs->trans('BabyfootElo')));
		$graph->SetType(array('lines'));
		$graph->SetTitle($langs->trans('BabyfootEloHistory'));
		$graph->SetYLabel($langs->trans('BabyfootElo'));
		$graph->SetWidth(700);
		$graph->SetHeight(220);
		$graph->SetMaxValue($graph->GetCeilMaxValue());
		$graph->SetMinValue($graph->GetFloorMinValue());
		$graph->draw('babyfoot_elo_history');

		print '<br>'.$graph->show();
	} else {
		dol_syslog('player_card graph unavailable: '.$errorMessage, LOG_WARNING);
	}
}

// Last games
$lastGames = $repository->getLastGames($playerId, 10);
print '<br>';
print load_fiche_titre($langs->trans('BabyfootLastGames'), '', '');

if (empty($lastGames)) {
	print '<div class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootNoGameYet')).'</div>';
} else {
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Ref').'</th>';
	print '<th>'.$langs->trans('BabyfootDateGame').'</th>';
	print '<th>'.$langs->trans('BabyfootMode').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootScore').'</th>';
	print '<th class="center">'.$langs->trans('BabyfootEloDelta').'</th>';
	print '</tr>';

	$gameObject = new Game($db);
	foreach ($lastGames as $row) {
		$gameObject->id = (int) $row['rowid'];
		$gameObject->ref = $row['ref'];

		$won = ((int) $row['is_winner'] === 1);
		$delta = (((int) $row['elo_delta']) >= 0 ? '+' : '').((int) $row['elo_delta']);

		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.$gameObject->getNomUrl(1).'</td>';
		print '<td class="nowraponall">'.dol_print_date($row['date_game'], 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans($row['mode'] === BabyfootConfig::MODE_1V1 ? 'Babyfoot1v1' : 'Babyfoot2v2')).'</td>';
		print '<td class="center'.($won ? ' babyfoot-winner' : '').'">';
		print ((int) $row['score_team1']).' - '.((int) $row['score_team2']);
		print '</td>';
		print '<td class="center">'.dol_escape_htmltag($delta).'</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';
}

print '</div>';

llxFooter();
$db->close();
