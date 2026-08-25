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
 * \file    lib/babyfoot.lib.php
 * \ingroup babyfoot
 * \brief   Tab preparation functions of the babyfoot module.
 *
 * This file holds prepareHead functions ONLY. Every piece of business logic
 * belongs to a class under class/ — do not turn this file into a catch-all.
 */

/**
 * Prepare the tabs of the module setup pages.
 *
 * @return	array<int,array<int,string>>	Array of tabs
 */
function babyfootAdminPrepareHead(): array
{
	global $langs, $conf;

	$langs->load('babyfoot@babyfoot');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/babyfoot/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/babyfoot/admin/babyfoot_documentation.php', 1);
	$head[$h][1] = $langs->trans('babyfootDocumentation');
	$head[$h][2] = 'documentation';
	$h++;

	$head[$h][0] = dol_buildpath('/babyfoot/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'babyfoot@babyfoot');

	return $head;
}

/**
 * Prepare the tabs of a game card.
 *
 * The module produces no document and logs no agenda event, so a single tab is
 * enough (spec section 1: no business workflow, no PDF).
 *
 * @param	Game	$object		Game to build the tabs for
 * @return	array<int,array<int,string>>	Array of tabs
 */
function babyfootGamePrepareHead($object): array
{
	global $langs, $conf;

	$langs->load('babyfoot@babyfoot');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/babyfoot/game_card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('BabyfootGame');
	$head[$h][2] = 'card';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'babyfootgame@babyfoot');

	return $head;
}

/**
 * Prepare the tabs of the ranking screen.
 *
 * @param	string	$activeMode		Mode currently displayed ('all', '1v1' or '2v2')
 * @return	array<int,array<int,string>>	Array of tabs
 */
function babyfootRankingPrepareHead(string $activeMode): array
{
	global $langs, $conf;

	$langs->load('babyfoot@babyfoot');

	$modes = array(
		'all' => 'BabyfootRankingOverall',
		'1v1' => 'Babyfoot1v1',
		'2v2' => 'Babyfoot2v2',
	);

	$h = 0;
	$head = array();
	foreach ($modes as $mode => $labelKey) {
		$head[$h][0] = dol_buildpath('/babyfoot/ranking.php', 1).'?mode='.urlencode($mode);
		$head[$h][1] = $langs->trans($labelKey);
		$head[$h][2] = $mode;
		$h++;
	}

	complete_head_from_modules($conf, $langs, null, $head, $h, 'babyfootranking@babyfoot');

	return $head;
}
