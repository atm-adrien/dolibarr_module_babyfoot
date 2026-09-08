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
 * \file    player_list.php
 * \ingroup babyfoot
 * \brief   Player directory: every player who has already played, sorted by name.
 *          Answers "where is this player's card", where the ranking screen answers
 *          "who plays best". No pagination: the roster is an office, not a database.
 */

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dirname(__FILE__).'/class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/class/stats/rankingrepository.class.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
if (empty($sortfield)) {
	$sortfield = 'name';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
}

$repository = new RankingRepository($db, (int) $conf->entity);
$players = $repository->getPlayerList($sortfield, $sortorder);


/*
 * View
 */

$title = $langs->trans('BabyfootPlayers');
llxHeader('', $title, '', '', 0, 0, array(), array());

$newCardButton = '';
if ($user->hasRight('babyfoot', 'create')) {
	$newCardButton = dolGetButtonTitle($langs->trans('BabyfootMenuNewGame'), '', 'fa fa-plus-circle', dol_buildpath('/babyfoot/game_quickadd.php', 1));
}

print_barre_liste($title, 0, $_SERVER['PHP_SELF'], '', $sortfield, $sortorder, '', count($players), count($players), 'fa-futbol', 0, $newCardButton, '', 0);

// Empty state (section 9): a player only exists once they have played
if (empty($players)) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootNoPlayerYet')).'<br><br>';
	if ($user->hasRight('babyfoot', 'create')) {
		print '<a class="button" href="'.dol_buildpath('/babyfoot/game_quickadd.php', 1).'">';
		print dol_escape_htmltag($langs->trans('BabyfootRecordFirstGame'));
		print '</a>';
	}
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

print '<tr class="liste_titre">';
print_liste_field_titre('BabyfootPlayer', $_SERVER['PHP_SELF'], 'name', '', '', '', $sortfield, $sortorder);
print_liste_field_titre('BabyfootEloOverall', $_SERVER['PHP_SELF'], 'elo', '', '', 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('BabyfootGames', $_SERVER['PHP_SELF'], 'nb_games', '', '', 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('BabyfootWinsLosses', $_SERVER['PHP_SELF'], '', '', '', 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('BabyfootRatio', $_SERVER['PHP_SELF'], 'ratio', '', '', 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('BabyfootDateLastGame', $_SERVER['PHP_SELF'], 'date_last_game', '', '', 'align="center"', $sortfield, $sortorder);
print '</tr>';

// The rows already carry the user labels: no User::fetch inside this loop
$playerObject = new User($db);
foreach ($players as $row) {
	$playerObject->id = (int) $row['fk_user'];
	$playerObject->lastname = $row['lastname'];
	$playerObject->firstname = $row['firstname'];
	$playerObject->login = $row['login'];

	print '<tr class="oddeven'.($row['user_status'] == 0 ? ' babyfoot-unranked' : '').'">';

	// A deleted Dolibarr user keeps its history, and its row, but has no label left
	$hasLabel = ($row['lastname'] !== '' || $row['firstname'] !== '' || $row['login'] !== '');
	$label = $hasLabel ? $playerObject->getFullName($langs) : '#'.((int) $row['fk_user']);

	print '<td class="nowraponall">';
	print '<a href="'.dol_buildpath('/babyfoot/player_card.php', 1).'?id='.((int) $row['fk_user']).'">';
	print dol_escape_htmltag($label);
	print '</a>';
	if ($row['user_status'] == 0) {
		print ' <span class="opacitymedium">('.dol_escape_htmltag($langs->trans('Disabled')).')</span>';
	}
	print '</td>';

	print '<td class="center"><strong>'.((int) $row['elo']).'</strong></td>';
	print '<td class="center">'.((int) $row['nb_games']).'</td>';
	print '<td class="center">'.((int) $row['nb_wins']).' / '.((int) $row['nb_losses']).'</td>';
	print '<td class="center">'.price2num(((float) $row['ratio']) * 100, 1).' %</td>';
	print '<td class="center nowraponall">';
	print empty($row['date_last_game']) ? '' : dol_print_date($row['date_last_game'], 'day');
	print '</td>';

	print '</tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
