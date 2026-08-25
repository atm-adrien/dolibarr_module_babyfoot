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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file		admin/babyfoot_about.php
 *	\ingroup	babyfoot
 *	\brief		About tab (ATM TechATM) — generated from team-ai-config skeleton
 */

// Dolibarr environment
$res = @include '../../main.inc.php'; // From htdocs directory
if (! $res) {
	$res = @include '../../../main.inc.php'; // From "custom" directory
}

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/babyfoot.lib.php';

// Translations
$langs->load('babyfoot@babyfoot');

// Access control
if (! $user->admin) {
	accessforbidden();
}

/*
 * View
 */
$page_name = 'BabyfootAbout';
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">'
	. $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback);

// Configuration header
$head = babyfootAdminPrepareHead();

$notab = -1;
print dol_get_fiche_head($head, 'about', $langs->trans('ModuleBabyfootName'), $notab, 'babyfoot@babyfoot');
print dol_get_fiche_end($notab);

require_once __DIR__ . '/../class/techatm.class.php';
$techATM = new \ATM\Babyfoot\TechATM($db);

require_once __DIR__ . '/../core/modules/modBabyfoot.class.php';
$moduleDescriptor = new modBabyfoot($db);

print $techATM->getAboutPage($moduleDescriptor);

llxFooter();

$db->close();
