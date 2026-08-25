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
 * \file    css/babyfoot.css.php
 * \ingroup babyfoot
 * \brief   Styles of the babyfoot module. Mobile first: the entry screen must
 *          fit one screen height and be usable with a thumb.
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

header('Content-type: text/css');
// Important: the css file is cached for one day
header('Cache-Control: max-age=86400, public, must-revalidate');
?>

/* Team blocks of the entry screen */
.babyfoot-teams {
	display: flex;
	gap: 1em;
	align-items: stretch;
}
.babyfoot-team {
	flex: 1;
	padding: 0.8em;
	border-radius: 6px;
	border: 2px solid transparent;
}
.babyfoot-team1 { background-color: #e8f0fb; border-color: #4a7ebb; }
.babyfoot-team2 { background-color: #fbeaea; border-color: #bb4a4a; }
.babyfoot-team-title { font-weight: bold; margin-bottom: 0.5em; }

/* Score input and thumb friendly buttons */
.babyfoot-score-row {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.4em;
	margin-top: 0.6em;
}
.babyfoot-score-input {
	font-size: 1.6em;
	text-align: center;
	width: 3em;
	padding: 0.2em;
}
.babyfoot-score-btn {
	min-width: 48px;
	min-height: 48px;
	font-size: 1.4em;
	line-height: 1;
	cursor: pointer;
}

/* Mode selector: two wide buttons */
.babyfoot-mode-selector { display: flex; gap: 0.6em; margin-bottom: 1em; }
.babyfoot-mode-btn {
	flex: 1;
	min-height: 48px;
	text-align: center;
	padding: 0.6em;
	border-radius: 6px;
	border: 2px solid #ccc;
	text-decoration: none;
}
.babyfoot-mode-btn.babyfoot-mode-active { border-color: #4a7ebb; font-weight: bold; }

.babyfoot-submit { width: 100%; min-height: 52px; font-size: 1.1em; margin-top: 1em; }
.babyfoot-datebox { margin-top: 0.8em; }

/* Winner highlight in lists and cards */
.babyfoot-winner { font-weight: bold; }
.babyfoot-winner-cell { background-color: rgba(74, 126, 187, 0.08); }

/* Podium */
.babyfoot-rank-1 { font-weight: bold; background-color: rgba(255, 215, 0, 0.18); }
.babyfoot-rank-2 { font-weight: bold; background-color: rgba(192, 192, 192, 0.18); }
.babyfoot-rank-3 { font-weight: bold; background-color: rgba(205, 127, 50, 0.18); }
.babyfoot-unranked { opacity: 0.6; }

.babyfoot-up { color: #2e7d32; }
.babyfoot-down { color: #c62828; }
.babyfoot-flat { color: #888; }

.babyfoot-empty {
	padding: 2em 1em;
	text-align: center;
}

/* Stacked team blocks on a phone */
@media only screen and (max-width: 767px) {
	.babyfoot-teams { flex-direction: column; }
	.babyfoot-score-input { font-size: 2em; width: 2.5em; }
	.babyfoot-score-btn { min-width: 3em; min-height: 3em; }
}
