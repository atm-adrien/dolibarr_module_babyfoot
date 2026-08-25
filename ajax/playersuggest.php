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
 * \file    ajax/playersuggest.php
 * \ingroup babyfoot
 * \brief   Returns the most frequent teammates or opponents of the current user,
 *          so the entry screen can put them on top of its player selectors.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}

$res = @include '../../../main.inc.php';
if (!$res) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once dirname(__FILE__).'/../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../class/game.class.php';

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'create')) {
	accessforbidden();
}

/** @var int Maximum number of suggestions returned */
$maxSuggestions = 8;

$mode = GETPOST('mode', 'aZ09');
$relation = GETPOST('relation', 'aZ09');

$sameTeam = ($relation === 'teammate');

$sql = "SELECT other.fk_user, COUNT(*) as nb";
$sql .= " FROM ".$db->prefix()."babyfoot_game_player as mine";
$sql .= " INNER JOIN ".$db->prefix()."babyfoot_game_player as other ON other.fk_game = mine.fk_game";
$sql .= " INNER JOIN ".$db->prefix()."babyfoot_game as g ON g.rowid = mine.fk_game";
$sql .= " WHERE g.entity = ".((int) $conf->entity);
$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
$sql .= " AND mine.fk_user = ".((int) $user->id);
$sql .= " AND other.fk_user <> ".((int) $user->id);
$sql .= $sameTeam ? " AND other.team = mine.team" : " AND other.team <> mine.team";
if (in_array($mode, BabyfootConfig::gameModes(), true)) {
	$sql .= " AND g.mode = '".$db->escape($mode)."'";
}
$sql .= " GROUP BY other.fk_user";
$sql .= " ORDER BY nb DESC, other.fk_user ASC";
$sql .= $db->plimit($maxSuggestions, 0);

$suggestions = array();

$resql = $db->query($sql);
if (!$resql) {
	dol_syslog('ajax/playersuggest '.$db->lasterror(), LOG_ERR);
} else {
	while ($obj = $db->fetch_object($resql)) {
		$suggestions[] = array(
			'id' => (int) $obj->fk_user,
			'nb' => (int) $obj->nb,
		);
	}
	$db->free($resql);
}

top_httphead('application/json');

print json_encode($suggestions);
