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
 * \file    game_quickadd.php
 * \ingroup babyfoot
 * \brief   Quick game entry screen. This is THE screen of the module: it must fit
 *          one mobile screen height and allow recording a game in three gestures.
 *          Every rule checked here is checked again by GameValidator server side.
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
require_once dirname(__FILE__).'/class/gamevalidator.class.php';
require_once dirname(__FILE__).'/class/ratingengine.class.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'create')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$config = BabyfootConfig::resolve();

// Chosen mode, remembered from one game to the next
$mode = GETPOST('mode', 'aZ09');
if (!in_array($mode, BabyfootConfig::gameModes(), true)) {
	$mode = !empty($user->conf->BABYFOOT_LAST_MODE) ? $user->conf->BABYFOOT_LAST_MODE : $config['default_mode'];
}
if (!in_array($mode, BabyfootConfig::gameModes(), true)) {
	$mode = BabyfootConfig::MODE_2V2;
}

$playersPerTeam = BabyfootConfig::playersPerTeam($mode);
$form = new Form($db);

// Values kept to refill the form when the game is refused
$postedScore = array(1 => '', 2 => '');
$postedPlayers = array();


/*
 * Actions
 */

if ($action === 'add') {
	// Explicit anti CSRF check: never rely on the implicit one only
	if (GETPOST('token', 'alpha') !== $_SESSION['newtoken']) {
		accessforbidden('BadCSRFToken');
	}

	$players = array();
	for ($team = 1; $team <= 2; $team++) {
		for ($slot = 1; $slot <= $playersPerTeam; $slot++) {
			$playerId = GETPOSTINT('player_'.$team.'_'.$slot);
			$postedPlayers[$team][$slot] = $playerId;
			if ($playerId > 0) {
				$players[] = array('fk_user' => $playerId, 'team' => $team);
			}
		}
	}

	$postedScore[1] = GETPOSTINT('score_team1');
	$postedScore[2] = GETPOSTINT('score_team2');

	// The screen records a game that has just been played: the date is never chosen
	// here. Backdating stays possible from the game card, which is what makes the
	// isLatestDate() guard below still meaningful.
	$dateGame = dol_now();

	$game = new Game($db);
	$game->date_game = $dateGame;
	$game->mode = $mode;
	$game->score_team1 = $postedScore[1];
	$game->score_team2 = $postedScore[2];
	$game->setPlayers($players);

	// RG-08: only a warning, never a blocker
	$validator = new GameValidator($db, (int) $conf->entity);
	$duplicateId = $validator->findRecentDuplicate($mode, (int) $postedScore[1], (int) $postedScore[2], $players);

	// Section 9: a game older than the most recent one rebuilds the whole chain,
	// and the user must be told before it happens. Detected BEFORE the insert,
	// since afterwards the new game is itself part of the timeline.
	$engine = new RatingEngine($db, (int) $conf->entity, $config);
	$willRecompute = !$engine->isLatestDate($dateGame);

	if ($game->create($user) > 0) {
		$game->fetchLines();

		if ($duplicateId > 0) {
			setEventMessages($langs->trans('BabyfootWarnDuplicate', $duplicateId), null, 'warnings');
		}

		// Section 9: explicit warning that the ranking has just been adjusted
		if ($willRecompute) {
			setEventMessages($langs->trans('BabyfootWarnRecomputeTriggered'), null, 'warnings');
		}

		// Confirmation message: the Elo change of every player (section 6.1)
		$deltas = array();
		foreach ($game->lines as $line) {
			$player = new User($db);
			$name = ($player->fetch((int) $line->fk_user) > 0) ? $player->getFullName($langs) : '#'.((int) $line->fk_user);
			$deltas[] = dol_escape_htmltag($name).' '.(((int) $line->elo_delta >= 0) ? '+' : '').((int) $line->elo_delta);
		}
		setEventMessages($langs->trans('BabyfootGameSaved', $game->ref).' — '.implode(' · ', $deltas), null, 'mesgs');

		// Remember the mode for the next game
		dol_set_user_param($db, $conf, $user, array('BABYFOOT_LAST_MODE' => $mode));

		// No redirect to the card: come back on a blank form, ready for the next game
		$postedScore = array(1 => '', 2 => '');
		$postedPlayers = array();
		$action = '';
	} else {
		if (!empty($game->validationErrors)) {
			foreach ($game->validationErrors as $errorKey) {
				setEventMessages($langs->trans($errorKey, BabyfootConfig::SCORE_MAX), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans(!empty($game->error) ? $game->error : 'BabyfootErrValidation'), null, 'errors');
		}
	}
}


/*
 * View
 */

$title = $langs->trans('BabyfootNewGame');
$arrayofjs = array();
$arrayofcss = array();

llxHeader('', $title, '', '', 0, 0, $arrayofjs, $arrayofcss);

print load_fiche_titre($title, '', 'fa-futbol');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="quickadd" class="babyfoot-quickadd">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';
print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';

// Mode selector: a segmented control, switching reloads the page in GET
print '<div class="babyfoot-modebar">';
print '<div class="babyfoot-mode-selector">';
foreach (BabyfootConfig::gameModes() as $availableMode) {
	$cssActive = ($availableMode === $mode) ? ' babyfoot-mode-active' : '';
	$label = ($availableMode === BabyfootConfig::MODE_1V1) ? $langs->trans('Babyfoot1v1') : $langs->trans('Babyfoot2v2');
	print '<a class="babyfoot-mode-btn'.$cssActive.'" href="'.$_SERVER['PHP_SELF'].'?mode='.urlencode($availableMode).'">';
	print dol_escape_htmltag($label);
	print '</a>';
}
print '</div>';
print '</div>';

// The two team blocks, always side by side whatever the screen width
print '<div class="babyfoot-teams">';
for ($team = 1; $team <= 2; $team++) {
	if ($team === 2) {
		print '<div class="babyfoot-vs">'.dol_escape_htmltag($langs->trans('BabyfootVersus')).'</div>';
	}

	print '<div class="babyfoot-team babyfoot-team'.$team.'">';
	print '<div class="babyfoot-team-title">'.dol_escape_htmltag($langs->trans('BabyfootTeam'.$team)).'</div>';

	for ($slot = 1; $slot <= $playersPerTeam; $slot++) {
		$fieldName = 'player_'.$team.'_'.$slot;

		$selected = '';
		if (isset($postedPlayers[$team][$slot]) && $postedPlayers[$team][$slot] > 0) {
			$selected = (int) $postedPlayers[$team][$slot];
		} elseif ($team === 1 && $slot === 1 && !empty($config['prefill_current_user'])) {
			$selected = (int) $user->id;
		}

		print '<div class="babyfoot-player-slot">';
		// $notdisabled = 1 (15th argument) restricts the list to active users
		print $form->select_dolusers($selected, $fieldName, 1, null, 0, '', '', '0', 0, -1, '', 0, '', 'babyfoot-player-select', 1);
		print '</div>';
	}

	print '<div class="babyfoot-score-row">';
	print '<label class="babyfoot-score-label" for="score_team'.$team.'">'.dol_escape_htmltag($langs->trans('BabyfootScore')).'</label>';
	print '<input type="number" inputmode="numeric" pattern="[0-9]*" min="0" max="'.BabyfootConfig::SCORE_MAX.'"';
	print ' class="babyfoot-score-input" id="score_team'.$team.'" name="score_team'.$team.'"';
	print ' aria-label="'.dol_escape_htmltag($langs->trans('BabyfootScoreTeam'.$team)).'"';
	print ' value="'.dol_escape_htmltag((string) $postedScore[$team]).'">';
	print '</div>';

	print '</div>';
}
print '</div>';

print $form->buttonsSaveCancel('BabyfootSaveGame', '', array(), false, 'babyfoot-submit');

print '</form>';

// Client side helpers only: every rule is enforced again server side
print '<script nonce="'.getNonce().'" type="text/javascript">';
print 'jQuery(document).ready(function () {';
for ($team = 1; $team <= 2; $team++) {
	$relation = ($team === 1) ? 'teammate' : 'opponent';
	for ($slot = 1; $slot <= $playersPerTeam; $slot++) {
		if ($team === 1 && $slot === 1) {
			continue;   // that slot holds the current user
		}
		$url = dol_buildpath('/babyfoot/ajax/playersuggest.php', 1);
		print '	jQuery.getJSON("'.dol_escape_js($url).'", {mode: "'.dol_escape_js($mode).'", relation: "'.$relation.'"},';
		print '		function (data) {';
		print '			var ids = [];';
		print '			jQuery.each(data, function (i, row) { ids.push(row.id); });';
		print '			babyfootPromoteSuggestions("player_'.$team.'_'.$slot.'", ids);';
		print '		}).fail(function () { /* keep the server side list as is */ });';
	}
}
print '});';
print '</script>';

llxFooter();
$db->close();
