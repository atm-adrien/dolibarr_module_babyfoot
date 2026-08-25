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
 * \file    js/babyfoot.js.php
 * \ingroup babyfoot
 * \brief   Client side helpers. Uses only the jQuery already loaded by Dolibarr:
 *          no extra library, no CDN. Everything here is convenience only, every
 *          rule is enforced again server side by GameValidator.
 */

if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

session_cache_limiter('public');

header('Content-type: application/javascript');
header('Cache-Control: max-age=86400, public, must-revalidate');
?>

/**
 * Bind the plus and minus score buttons.
 *
 * @param {number} maxScore Highest score allowed, only a client side comfort
 * @return {void}
 */
function babyfootBindScoreButtons(maxScore) {
	jQuery('.babyfoot-score-btn').off('click.babyfoot').on('click.babyfoot', function (e) {
		e.preventDefault();

		var target = jQuery('#' + jQuery(this).data('target'));
		if (target.length === 0) {
			return;
		}

		var step = parseInt(jQuery(this).data('step'), 10);
		var value = parseInt(target.val(), 10);
		if (isNaN(value)) {
			value = 0;
		}
		if (isNaN(step)) {
			step = 0;
		}

		value = value + step;
		if (value < 0) {
			value = 0;
		}
		if (maxScore > 0 && value > maxScore) {
			value = maxScore;
		}
		target.val(value);
	});
}

/**
 * Move the suggested players to the top of a player selector.
 *
 * Only reorders the options already produced by the server: the list of allowed
 * values never comes from the client.
 *
 * @param {string} selectId Id of the select element
 * @param {Array}  ids      User ids to move up, most relevant first
 * @return {void}
 */
function babyfootPromoteSuggestions(selectId, ids) {
	var select = jQuery('#' + selectId);
	if (select.length === 0 || !ids || ids.length === 0) {
		return;
	}

	var current = select.val();
	for (var i = ids.length - 1; i >= 0; i--) {
		var option = select.find('option[value="' + ids[i] + '"]');
		if (option.length > 0) {
			select.prepend(option);
		}
	}
	select.val(current);
}

/**
 * Toggle the collapsed date block of the entry screen.
 *
 * @param {string} boxId Id of the block to toggle
 * @return {void}
 */
function babyfootToggleDateBox(boxId) {
	jQuery('#' + boxId).slideToggle(120);
}
