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
 * \file    admin/setup.php
 * \ingroup babyfoot
 * \brief   Module settings, plus the ranking rebuild and consistency tools.
 */

$res = @include '../../../main.inc.php';
if (!$res) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dirname(__FILE__).'/../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../class/game.class.php';
require_once dirname(__FILE__).'/../class/ratingengine.class.php';
require_once dirname(__FILE__).'/../lib/babyfoot.lib.php';

$langs->loadLangs(array('admin', 'babyfoot@babyfoot'));

// Access control, before anything else
if (!$user->admin && !$user->hasRight('babyfoot', 'admin')) {
	accessforbidden();
}

/** @var int Above this number of games, the rebuild is pushed to the CLI script */
$recomputeWebLimit = 5000;

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$form = new Form($db);

/**
 * Settings handled by this screen, with the input type to render.
 *
 * @var array<string,string> Setting name to input type
 */
$settings = array(
	'BABYFOOT_SCORE_MAX' => 'int',
	'BABYFOOT_SCORE_EXACT' => 'bool',
	'BABYFOOT_ALLOW_DRAW' => 'bool',
	'BABYFOOT_ELO_INITIAL' => 'int',
	'BABYFOOT_ELO_K' => 'int',
	'BABYFOOT_ELO_K_NOVICE' => 'int',
	'BABYFOOT_ELO_NOVICE_GAMES' => 'int',
	'BABYFOOT_ELO_MARGIN' => 'bool',
	'BABYFOOT_MIN_GAMES_RANKED' => 'int',
	'BABYFOOT_EDIT_DELAY' => 'int',
	'BABYFOOT_PREFILL_CURRENT_USER' => 'bool',
	'BABYFOOT_DEFAULT_MODE' => 'mode',
);


/*
 * Actions
 */

if ($action !== '') {
	// Every action of this screen mutates something: always check the token
	if (GETPOST('token', 'alpha') !== $_SESSION['newtoken']) {
		accessforbidden('BadCSRFToken');
	}
}

if ($action === 'update_settings') {
	$error = 0;

	foreach ($settings as $name => $type) {
		if ($type === 'mode') {
			$value = GETPOST($name, 'aZ09');
			if (!in_array($value, BabyfootConfig::gameModes(), true)) {
				$value = BabyfootConfig::MODE_2V2;
			}
		} else {
			$value = (string) GETPOSTINT($name);
		}

		if (!dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity)) {
			$error++;
			dol_syslog('admin/setup failed to save '.$name, LOG_ERR);
		}
	}

	if ($error > 0) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	$action = '';
}

if ($action === 'confirm_recompute' && $confirm === 'yes') {
	// The rebuild is reserved to the admin right, not merely to the setup screen
	if (!$user->admin && !$user->hasRight('babyfoot', 'admin')) {
		accessforbidden();
	}

	$engine = new RatingEngine($db, (int) $conf->entity);
	try {
		$result = $engine->recomputeAll();
		setEventMessages($langs->trans('BabyfootRecomputeDone', (int) $result['nb_games'], price2num($result['duration'], 2)), null, 'mesgs');
	} catch (Exception $e) {
		setEventMessages($langs->trans($e->getMessage()), null, 'errors');
	}
	$action = '';
}

$drifts = null;
if ($action === 'checkconsistency') {
	$engine = new RatingEngine($db, (int) $conf->entity);
	$drifts = $engine->checkConsistency();
	$action = '';
}


/*
 * View
 */

$config = BabyfootConfig::resolve();

// Count the validated games, to decide whether the web rebuild is safe
$nbGames = 0;
$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."babyfoot_game";
$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = ".((int) Game::STATUS_VALIDATED);
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$nbGames = (int) $obj->nb;
	$db->free($resql);
} else {
	dol_syslog('admin/setup game count '.$db->lasterror(), LOG_ERR);
}

llxHeader('', $langs->trans('BabyfootSetup'), '', '', 0, 0, array(), array());

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('BabyfootSetup'), $linkback, 'title_setup');

$head = babyfootAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('ModuleBabyfootName'), -1, 'babyfoot@babyfoot');

// Settings form
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_settings">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

foreach ($settings as $name => $type) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($langs->trans($name)).'</td>';
	print '<td>';

	if ($type === 'bool') {
		print $form->selectyesno($name, getDolGlobalInt($name), 1);
	} elseif ($type === 'mode') {
		$modes = array(
			BabyfootConfig::MODE_1V1 => $langs->trans('Babyfoot1v1'),
			BabyfootConfig::MODE_2V2 => $langs->trans('Babyfoot2v2'),
		);
		print $form->selectarray($name, $modes, getDolGlobalString($name, BabyfootConfig::MODE_2V2), 0);
	} else {
		print '<input type="number" min="0" name="'.$name.'" value="'.getDolGlobalInt($name).'" class="width75">';
	}

	print '</td></tr>';
}

print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br>';

// Tools
print load_fiche_titre($langs->trans('BabyfootTools'), '', '');

if ($action === 'recompute') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'],
		$langs->trans('BabyfootRecomputeRanking'),
		$langs->trans('BabyfootConfirmRecompute', $nbGames),
		'confirm_recompute',
		'',
		0,
		1
	);
}

print '<table class="noborder centpercent">';

print '<tr class="oddeven"><td>';
print dol_escape_htmltag($langs->trans('BabyfootRecomputeRanking'));
print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootRecomputeHelp')).'</span>';
print '</td><td class="right">';
if ($nbGames > $recomputeWebLimit) {
	// Protect against a PHP timeout: past that volume, use the CLI script
	print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootRecomputeUseCli', $nbGames)).'</span>';
} else {
	print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=recompute&token='.newToken().'">';
	print dol_escape_htmltag($langs->trans('BabyfootRecomputeRanking'));
	print '</a>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>';
print dol_escape_htmltag($langs->trans('BabyfootCheckConsistency'));
print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BabyfootCheckConsistencyHelp')).'</span>';
print '</td><td class="right">';
print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=checkconsistency&token='.newToken().'">';
print dol_escape_htmltag($langs->trans('BabyfootCheckConsistency'));
print '</a>';
print '</td></tr>';

print '</table>';

// Consistency report: reported, never fixed
if (is_array($drifts)) {
	print '<br>';
	if (empty($drifts)) {
		print '<div class="ok">'.dol_escape_htmltag($langs->trans('BabyfootConsistencyOk')).'</div>';
	} else {
		print '<div class="warning">'.dol_escape_htmltag($langs->trans('BabyfootConsistencyDrift', count($drifts))).'</div>';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('BabyfootPlayer').'</td>';
		print '<td>'.$langs->trans('BabyfootMode').'</td>';
		print '<td>'.$langs->trans('Field').'</td>';
		print '<td class="right">'.$langs->trans('BabyfootStoredValue').'</td>';
		print '<td class="right">'.$langs->trans('BabyfootExpectedValue').'</td>';
		print '</tr>';

		foreach ($drifts as $drift) {
			$player = new User($db);
			$name = ($player->fetch((int) $drift['fk_user']) > 0) ? $player->getFullName($langs) : '#'.((int) $drift['fk_user']);

			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag($name).'</td>';
			print '<td>'.dol_escape_htmltag((string) $drift['mode']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $drift['field']).'</td>';
			print '<td class="right">'.dol_escape_htmltag((string) $drift['stored']).'</td>';
			print '<td class="right">'.dol_escape_htmltag((string) $drift['expected']).'</td>';
			print '</tr>';
		}

		print '</table>';
	}
}

print dol_get_fiche_end();

llxFooter();
$db->close();
