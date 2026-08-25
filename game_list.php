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
 * \file    game_list.php
 * \ingroup babyfoot
 * \brief   List of games, newest first.
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
require_once dirname(__FILE__).'/class/game.class.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$form = new Form($db);

// Filters
$searchDateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_date_frommonth'), GETPOSTINT('search_date_fromday'), GETPOSTINT('search_date_fromyear'));
$searchDateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_date_tomonth'), GETPOSTINT('search_date_today'), GETPOSTINT('search_date_toyear'));
$searchPlayer = GETPOSTINT('search_player');
$searchMode = GETPOST('search_mode', 'aZ09');
$searchStatus = GETPOST('search_status', 'intcomma');
$searchMine = GETPOSTINT('search_mine');

if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$searchDateFrom = 0;
	$searchDateTo = 0;
	$searchPlayer = 0;
	$searchMode = '';
	$searchStatus = '';
	$searchMine = 0;
}

$hasFilter = ($searchDateFrom > 0 || $searchDateTo > 0 || $searchPlayer > 0 || $searchMode !== '' || $searchStatus !== '' || !empty($searchMine));

// Pagination and sorting, native mechanisms
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
if (empty($sortfield)) {
	$sortfield = 'g.date_game';
}
if (empty($sortorder)) {
	$sortorder = 'DESC';
}

// Only these columns may be sorted on: never trust the posted value blindly
$allowedSortFields = array('g.ref', 'g.date_game', 'g.mode', 'g.score_team1', 'g.score_team2', 'g.status');
if (!in_array($sortfield, $allowedSortFields, true)) {
	$sortfield = 'g.date_game';
}


/*
 * View
 */

$sqlWhere = " WHERE g.entity IN (".getEntity('babyfootgame').")";
if ($searchDateFrom > 0) {
	$sqlWhere .= " AND g.date_game >= '".$db->idate($searchDateFrom)."'";
}
if ($searchDateTo > 0) {
	$sqlWhere .= " AND g.date_game <= '".$db->idate($searchDateTo)."'";
}
if (in_array($searchMode, BabyfootConfig::gameModes(), true)) {
	$sqlWhere .= " AND g.mode = '".$db->escape($searchMode)."'";
}
if ($searchStatus !== '' && $searchStatus !== '-1') {
	$sqlWhere .= " AND g.status = ".((int) $searchStatus);
}
if ($searchPlayer > 0) {
	$sqlWhere .= " AND EXISTS (SELECT 1 FROM ".$db->prefix()."babyfoot_game_player as gpf";
	$sqlWhere .= " WHERE gpf.fk_game = g.rowid AND gpf.fk_user = ".((int) $searchPlayer).")";
}
// Decision D13: "my games" means the games I PLAYED, not the ones I recorded
if (!empty($searchMine)) {
	$sqlWhere .= " AND EXISTS (SELECT 1 FROM ".$db->prefix()."babyfoot_game_player as gpm";
	$sqlWhere .= " WHERE gpm.fk_game = g.rowid AND gpm.fk_user = ".((int) $user->id).")";
}

// Total count, for the pagination
$nbTotal = 0;
$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."babyfoot_game as g".$sqlWhere;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$nbTotal = (int) $obj->nb;
	$db->free($resql);
} else {
	dol_syslog('game_list count '.$db->lasterror(), LOG_ERR);
	setEventMessages($langs->trans('BabyfootErrValidation'), null, 'errors');
}

$sql = "SELECT g.rowid, g.ref, g.date_game, g.mode, g.score_team1, g.score_team2,";
$sql .= " g.winner_team, g.status, g.fk_user_creat";
$sql .= " FROM ".$db->prefix()."babyfoot_game as g";
$sql .= $sqlWhere;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$rows = array();
$gameIds = array();
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = $obj;
		$gameIds[] = (int) $obj->rowid;
	}
	$db->free($resql);
} else {
	dol_syslog('game_list '.$db->lasterror(), LOG_ERR);
	setEventMessages($langs->trans('BabyfootErrValidation'), null, 'errors');
}

// Load the players of the whole page in ONE query: never one query per row
$playersByGame = array();
$userIds = array();
if (!empty($gameIds)) {
	$sql = "SELECT gp.fk_game, gp.fk_user, gp.team, gp.elo_delta";
	$sql .= " FROM ".$db->prefix()."babyfoot_game_player as gp";
	$sql .= " WHERE gp.fk_game IN (".$db->sanitize(implode(',', $gameIds), 1).")";
	$sql .= " ORDER BY gp.fk_game ASC, gp.team ASC, gp.rowid ASC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$playersByGame[(int) $obj->fk_game][(int) $obj->team][] = $obj;
			$userIds[(int) $obj->fk_user] = (int) $obj->fk_user;
		}
		$db->free($resql);
	} else {
		dol_syslog('game_list players '.$db->lasterror(), LOG_ERR);
	}
}

// Resolve the user labels once, in a local cache
$usersCache = array();
foreach ($userIds as $userId) {
	$player = new User($db);
	$usersCache[$userId] = ($player->fetch($userId) > 0) ? $player->getFullName($langs) : '#'.$userId;
}

$title = $langs->trans('BabyfootGames');
llxHeader('', $title, '', '', 0, 0, array(), array());

$paramsUrl = '';
if ($searchPlayer > 0) {
	$paramsUrl .= '&search_player='.urlencode((string) $searchPlayer);
}
if ($searchMode !== '') {
	$paramsUrl .= '&search_mode='.urlencode($searchMode);
}
if ($searchStatus !== '') {
	$paramsUrl .= '&search_status='.urlencode($searchStatus);
}
if (!empty($searchMine)) {
	$paramsUrl .= '&search_mine=1';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="gamelist">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

$newCardButton = '';
if ($user->hasRight('babyfoot', 'create')) {
	$newCardButton = dolGetButtonTitle($langs->trans('BabyfootNewGame'), '', 'fa fa-plus-circle', dol_buildpath('/babyfoot/game_quickadd.php', 1));
}

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $paramsUrl, $sortfield, $sortorder, '', count($rows), $nbTotal, 'babyfoot@babyfoot', 0, $newCardButton, '', $limit);

// Empty state (section 9): explicit message and a link to the entry screen
if ($nbTotal === 0 && !$hasFilter) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootNoGameYet')).'<br><br>';
	print '<a class="button" href="'.dol_buildpath('/babyfoot/game_quickadd.php', 1).'">';
	print dol_escape_htmltag($langs->trans('BabyfootRecordFirstGame'));
	print '</a>';
	print '</div>';
	print '</form>';
	llxFooter();
	$db->close();
	exit;
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre nowraponall">';
print $form->selectDate($searchDateFrom > 0 ? $searchDateFrom : -1, 'search_date_from', 0, 0, 1, '', 1, 0);
print $form->selectDate($searchDateTo > 0 ? $searchDateTo : -1, 'search_date_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="liste_titre">';
print $form->selectarray('search_mode', array('1v1' => $langs->trans('Babyfoot1v1'), '2v2' => $langs->trans('Babyfoot2v2')), $searchMode, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre" colspan="3">';
print $form->select_dolusers($searchPlayer > 0 ? $searchPlayer : '', 'search_player', 1, null, 0, '', '', '0', 0, -1, '', 0, '', 'maxwidth150');
print '</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">';
print $form->selectarray('search_status', array('1' => $langs->trans('BabyfootStatusValidated'), '0' => $langs->trans('BabyfootStatusCanceled')), $searchStatus, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center">';
print '<label class="nowraponall"><input type="checkbox" name="search_mine" value="1"'.(!empty($searchMine) ? ' checked' : '').'> ';
print dol_escape_htmltag($langs->trans('BabyfootMyGames')).'</label><br>';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Header row
print '<tr class="liste_titre">';
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 'g.ref', '', $paramsUrl, '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootDateGame', $_SERVER['PHP_SELF'], 'g.date_game', '', $paramsUrl, '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootMode', $_SERVER['PHP_SELF'], 'g.mode', '', $paramsUrl, '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootTeam1', $_SERVER['PHP_SELF'], '', '', $paramsUrl, '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootScore', $_SERVER['PHP_SELF'], '', '', $paramsUrl, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('BabyfootTeam2', $_SERVER['PHP_SELF'], '', '', $paramsUrl, '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootEloDelta', $_SERVER['PHP_SELF'], '', '', $paramsUrl, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'g.status', '', $paramsUrl, 'align="right"', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $paramsUrl, '', $sortfield, $sortorder);
print '</tr>';

$gameObject = new Game($db);
$displayed = 0;
foreach ($rows as $row) {
	$displayed++;
	if ($displayed > $limit) {
		break;   // the extra row only tells us there is a next page
	}

	$gameObject->id = (int) $row->rowid;
	$gameObject->ref = $row->ref;
	$gameObject->status = (int) $row->status;

	print '<tr class="oddeven">';

	print '<td class="nowraponall">'.$gameObject->getNomUrl(1).'</td>';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($row->date_game), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($langs->trans($row->mode === BabyfootConfig::MODE_1V1 ? 'Babyfoot1v1' : 'Babyfoot2v2')).'</td>';

	// Team 1, then the score, then team 2
	$names = array(1 => array(), 2 => array());
	for ($team = 1; $team <= 2; $team++) {
		if (!empty($playersByGame[(int) $row->rowid][$team])) {
			foreach ($playersByGame[(int) $row->rowid][$team] as $line) {
				$names[$team][] = dol_escape_htmltag($usersCache[(int) $line->fk_user]);
			}
		}
	}
	$winner = (int) $row->winner_team;

	print '<td class="'.($winner === 1 ? 'babyfoot-winner babyfoot-winner-cell' : '').'">'.implode(', ', $names[1]).'</td>';

	print '<td class="center nowraponall">';
	print '<span class="'.($winner === 1 ? 'babyfoot-winner' : '').'">'.((int) $row->score_team1).'</span>';
	print ' - ';
	print '<span class="'.($winner === 2 ? 'babyfoot-winner' : '').'">'.((int) $row->score_team2).'</span>';
	print '</td>';

	print '<td class="'.($winner === 2 ? 'babyfoot-winner babyfoot-winner-cell' : '').'">'.implode(', ', $names[2]).'</td>';

	// Elo change of the current user when they played, otherwise of team 1
	$deltaLabel = '';
	if (!empty($playersByGame[(int) $row->rowid])) {
		foreach ($playersByGame[(int) $row->rowid] as $teamLines) {
			foreach ($teamLines as $line) {
				if ((int) $line->fk_user === (int) $user->id) {
					$deltaLabel = (((int) $line->elo_delta >= 0) ? '+' : '').((int) $line->elo_delta);
					break 2;
				}
			}
		}
	}
	print '<td class="center nowraponall">'.dol_escape_htmltag($deltaLabel).'</td>';

	print '<td class="right nowraponall">'.$gameObject->getLibStatut(5).'</td>';
	print '<td></td>';

	print '</tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
