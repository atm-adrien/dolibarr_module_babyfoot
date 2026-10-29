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
 *	\file		admin/babyfoot_documentation.php
 *	\ingroup	babyfoot
 *	\brief		Documentation admin tab (GitBook-like layout)
 *
 *	View only: the markdown parsing and rendering live in lib/babyfoot_doc.lib.php, which is
 *	includable and therefore testable - this file calls llxHeader() and cannot be required.
 */

// Dolibarr environment
$res = @include "../../main.inc.php"; // From htdocs directory
if (! $res) {
	$res = @include "../../../main.inc.php"; // From "custom" directory
}
if (! $res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once __DIR__ . "/../lib/babyfoot.lib.php";
require_once __DIR__ . "/../lib/babyfoot_doc.lib.php";

// Translations
// "admin" carries BackToModuleList used below; main.inc.php loads main/dict/errors/other only
$langs->loadLangs(["admin", "babyfoot@babyfoot"]);

// Access control — hiding the tab is cosmetic, this page URL stays reachable.
if (empty($user->admin) || ! babyfootDocumentationIsEnabled()) {
	accessforbidden();
}

/*
 * View
 */
$pageName = "babyfootDocumentation";

// External CSS / JS assets
$arrayofcss = ['/babyfoot/css/babyfoot_documentation.css'];
$arrayofjs  = ['/babyfoot/js/babyfoot_documentation.js'];

llxHeader('', $langs->trans($pageName), '', '', 0, 0, $arrayofjs, $arrayofcss);

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">'
	. $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($pageName), $linkback);

// Configuration header (tab strip — prepareHead lives in lib/babyfoot.lib.php)
$head = babyfootAdminPrepareHead();
print dol_get_fiche_head(
	$head,
	'documentation',
	$langs->trans("ModuleBabyfootName"),
	-1,
	"generic"
);

// Read and parse the documentation source
$parsed = babyfootLoadDocumentation(__DIR__ . '/../docs');
if ($parsed === null) {
	print '<div class="warning">' . $langs->trans("babyfootDocumentationFileNotFound") . '</div>';
	print dol_get_fiche_end(-1);
	llxFooter();
	$db->close();
	return;
}

$toc = $parsed['toc'] ?? [];
$sections = $parsed['sections'] ?? [];

// Render layout
print babyfootRenderDoc($toc, $sections, (bool) ($parsed['byFeature'] ?? false));

print dol_get_fiche_end(-1);
llxFooter();
$db->close();
