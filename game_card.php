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
 * \file    game_card.php
 * \ingroup babyfoot
 * \brief   Game card: composition, score, and the Elo snapshots of each player.
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
require_once dirname(__FILE__).'/lib/babyfoot.lib.php';

$langs->loadLangs(array('babyfoot@babyfoot', 'other'));

// Access control, before anything else
if (!$user->hasRight('babyfoot', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$config = BabyfootConfig::resolve();
$form = new Form($db);

$object = new Game($db);
$fetchResult = $object->fetch($id);
if ($fetchResult < 0) {
	dol_syslog('game_card fetch failed for id '.$id, LOG_ERR);
	setEventMessages($langs->trans('BabyfootErrValidation'), null, 'errors');
}


/*
 * Actions
 */

if ($action !== '' && $fetchResult > 0) {
	// Every mutating action requires a valid token
	$isMutating = in_array($action, array('confirm_cancel', 'confirm_reopen', 'confirm_delete', 'update'), true);
	if ($isMutating && GETPOST('token', 'alpha') !== $_SESSION['newtoken']) {
		accessforbidden('BadCSRFToken');
	}

	// RG-32: the right is checked server side, never inferred from a visible button
	if ($isMutating && !$object->canBeEditedBy($user)) {
		accessforbidden();
	}

	if ($action === 'confirm_cancel' && $confirm === 'yes') {
		if ($object->cancel($user) > 0) {
			setEventMessages($langs->trans('BabyfootGameCancelled', $object->ref), null, 'mesgs');
		} else {
			setEventMessages($langs->trans(!empty($object->error) ? $object->error : 'BabyfootErrValidation'), null, 'errors');
		}
		$action = '';
	}

	if ($action === 'confirm_reopen' && $confirm === 'yes') {
		if ($object->reopen($user) > 0) {
			setEventMessages($langs->trans('BabyfootGameReopened', $object->ref), null, 'mesgs');
		} else {
			setEventMessages($langs->trans(!empty($object->error) ? $object->error : 'BabyfootErrValidation'), null, 'errors');
		}
		$action = '';
	}

	if ($action === 'confirm_delete' && $confirm === 'yes') {
		// Only modify_all may delete for good (RG-33 prefers cancelling)
		if (!$user->hasRight('babyfoot', 'modify_all')) {
			accessforbidden();
		}
		if ($object->delete($user) > 0) {
			setEventMessages($langs->trans('BabyfootGameDeleted'), null, 'mesgs');
			header('Location: '.dol_buildpath('/babyfoot/game_list.php', 1));
			exit;
		}
		setEventMessages($langs->trans(!empty($object->error) ? $object->error : 'BabyfootErrValidation'), null, 'errors');
		$action = '';
	}

	if ($action === 'update') {
		$players = array();
		$playersPerTeam = BabyfootConfig::playersPerTeam((string) $object->mode);
		for ($team = 1; $team <= 2; $team++) {
			for ($slot = 1; $slot <= $playersPerTeam; $slot++) {
				$playerId = GETPOSTINT('player_'.$team.'_'.$slot);
				if ($playerId > 0) {
					$players[] = array('fk_user' => $playerId, 'team' => $team);
				}
			}
		}

		$newDate = dol_mktime(
			GETPOSTINT('date_gamehour'),
			GETPOSTINT('date_gamemin'),
			0,
			GETPOSTINT('date_gamemonth'),
			GETPOSTINT('date_gameday'),
			GETPOSTINT('date_gameyear')
		);

		$object->score_team1 = GETPOSTINT('score_team1');
		$object->score_team2 = GETPOSTINT('score_team2');
		if (!empty($newDate)) {
			$object->date_game = $newDate;
		}
		$object->setPlayers($players);

		if ($object->update($user) > 0) {
			setEventMessages($langs->trans('BabyfootGameUpdated', $object->ref), null, 'mesgs');
			$action = '';
			$object->fetch($id);
		} else {
			if (!empty($object->validationErrors)) {
				foreach ($object->validationErrors as $errorKey) {
					setEventMessages($langs->trans($errorKey, $config['score_max']), null, 'errors');
				}
			} else {
				setEventMessages($langs->trans(!empty($object->error) ? $object->error : 'BabyfootErrValidation'), null, 'errors');
			}
			$action = 'edit';
		}
	}
}


/*
 * View
 */

llxHeader('', $langs->trans('BabyfootGame'), '', '', 0, 0, array(), array());

// Section 9: a missing game is an explicit empty state, never a PHP error
if ($fetchResult <= 0) {
	print '<div class="babyfoot-empty opacitymedium">';
	print dol_escape_htmltag($langs->trans('BabyfootGameNotFound')).'<br><br>';
	print '<a class="button" href="'.dol_buildpath('/babyfoot/game_list.php', 1).'">';
	print dol_escape_htmltag($langs->trans('BabyfootGames'));
	print '</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

$object->fetchLines();

$head = babyfootGamePrepareHead($object);
print dol_get_fiche_head($head, 'card', $langs->trans('BabyfootGame'), -1, 'babyfoot@babyfoot');

// Confirmation dialogs
if ($action === 'cancel') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.((int) $object->id), $langs->trans('BabyfootCancelGame'), $langs->trans('BabyfootConfirmCancelGame', $object->ref), 'confirm_cancel', '', 0, 1);
}
if ($action === 'reopen') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.((int) $object->id), $langs->trans('BabyfootReopenGame'), $langs->trans('BabyfootConfirmReopenGame', $object->ref), 'confirm_reopen', '', 0, 1);
}
if ($action === 'delete') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.((int) $object->id), $langs->trans('Delete'), $langs->trans('BabyfootConfirmDeleteGame', $object->ref), 'confirm_delete', '', 0, 1);
}

$linkback = '<a href="'.dol_buildpath('/babyfoot/game_list.php', 1).'">'.$langs->trans('BackToList').'</a>';
print dol_banner_tab($object, 'ref', $linkback, 0, 'ref', 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

if ($action === 'edit') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
}

print '<table class="border centpercent tableforfield">';

print '<tr><td class="titlefield">'.$langs->trans('BabyfootDateGame').'</td><td>';
if ($action === 'edit') {
	print $form->selectDate($object->date_game, 'date_game', 1, 1, 0, '', 1, 0);
} else {
	print dol_print_date($object->date_game, 'dayhour');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('BabyfootMode').'</td><td>';
print dol_escape_htmltag($langs->trans($object->mode === BabyfootConfig::MODE_1V1 ? 'Babyfoot1v1' : 'Babyfoot2v2'));
print '</td></tr>';

// Composition and score, team by team
$playersPerTeam = BabyfootConfig::playersPerTeam((string) $object->mode);
for ($team = 1; $team <= 2; $team++) {
	$teamPlayers = $object->getPlayersByTeam($team);
	$isWinner = ((int) $object->winner_team === $team);

	print '<tr><td>'.$langs->trans('BabyfootTeam'.$team).'</td>';
	print '<td class="'.($isWinner ? 'babyfoot-winner' : '').'">';

	if ($action === 'edit') {
		for ($slot = 1; $slot <= $playersPerTeam; $slot++) {
			$selected = isset($teamPlayers[$slot - 1]) ? (int) $teamPlayers[$slot - 1]->fk_user : '';
			print $form->select_dolusers($selected, 'player_'.$team.'_'.$slot, 1, null, 0, '', '', '0', 0, -1, '', 0, '', 'minwidth150 maxwidth300', 1);
			print ' ';
		}
		print '<br>'.$langs->trans('BabyfootScoreTeam'.$team).' ';
		print '<input type="number" min="0" max="'.((int) $config['score_max']).'" name="score_team'.$team.'"';
		print ' value="'.((int) ($team === 1 ? $object->score_team1 : $object->score_team2)).'" class="width50">';
	} else {
		$labels = array();
		foreach ($teamPlayers as $line) {
			$player = new User($db);
			$name = ($player->fetch((int) $line->fk_user) > 0) ? $player->getFullName($langs) : '#'.((int) $line->fk_user);
			$delta = (((int) $line->elo_delta >= 0) ? '+' : '').((int) $line->elo_delta);
			$labels[] = dol_escape_htmltag($name)
				.' <span class="opacitymedium">('.((int) $line->elo_before).' &rarr; '.((int) $line->elo_after).', '.dol_escape_htmltag($delta).')</span>';
		}
		print implode('<br>', $labels);
		print '<br><strong>'.((int) ($team === 1 ? $object->score_team1 : $object->score_team2)).'</strong>';
	}

	print '</td></tr>';
}

print '<tr><td>'.$langs->trans('BabyfootWinnerTeam').'</td><td>';
if (is_null($object->winner_team)) {
	print dol_escape_htmltag($langs->trans('BabyfootDraw'));
} else {
	print dol_escape_htmltag($langs->trans('BabyfootTeam'.((int) $object->winner_team)));
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('UserAuthor').'</td><td>';
$author = new User($db);
if ($author->fetch((int) $object->fk_user_creat) > 0) {
	print $author->getNomUrl(-1);
} else {
	print dol_escape_htmltag('#'.((int) $object->fk_user_creat));
}
print ' <span class="opacitymedium">'.dol_print_date($object->date_creation, 'dayhour').'</span>';
print '</td></tr>';

print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(4).'</td></tr>';

print '</table>';

if ($action === 'edit') {
	print '<div class="center">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';
}

print '</div>';
print dol_get_fiche_end();

// Action buttons. RG-33: cancelling comes before deleting.
if ($action === '') {
	print '<div class="tabsAction">';

	if ($object->canBeEditedBy($user)) {
		print dolGetButtonAction('', $langs->trans('Modify'), 'default', $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=edit&token='.newToken(), '');

		if ((int) $object->status === Game::STATUS_VALIDATED) {
			print dolGetButtonAction('', $langs->trans('BabyfootCancelGame'), 'default', $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=cancel&token='.newToken(), '');
		} else {
			print dolGetButtonAction('', $langs->trans('BabyfootReopenGame'), 'default', $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=reopen&token='.newToken(), '');
		}
	}

	if ($user->hasRight('babyfoot', 'modify_all')) {
		print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=delete&token='.newToken(), '');
	}

	print '</div>';
}

llxFooter();
$db->close();
