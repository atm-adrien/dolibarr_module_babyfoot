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

// Must be called BEFORE main.inc.php starts the session, otherwise PHP raises a
// warning that is printed into the file and swallows its first rule
session_cache_limiter('public');

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

header('Content-type: text/css');
// Important: the css file is cached for one day
header('Cache-Control: max-age=86400, public, must-revalidate');
?>

/* ---------------------------------------------------------------------------
   Quick entry screen. Colours come from the Dolibarr theme variables wherever
   possible so the screen follows a dark theme; only the two camp accents are
   fixed, they are the identity of the screen.
   --------------------------------------------------------------------------- */

.babyfoot-quickadd {
	max-width: 700px;
	margin: 0 auto;
}

/* Mode selector: a segmented control, centred above the pitch */
.babyfoot-modebar {
	display: flex;
	justify-content: center;
	margin-bottom: 1.4em;
}
.babyfoot-mode-selector {
	display: inline-flex;
	gap: 3px;
	padding: 3px;
	border-radius: 999px;
	background: rgba(128, 128, 128, 0.14);
}
.babyfoot-mode-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 40px;
	padding: 0 1.5em;
	border-radius: 999px;
	font-weight: 600;
	font-size: 0.92em;
	white-space: nowrap;
	text-decoration: none;
	color: var(--colortext, #333);
	transition: background-color 0.15s ease;
}
.babyfoot-mode-btn:hover { background: rgba(128, 128, 128, 0.12); }
.babyfoot-mode-btn.babyfoot-mode-active {
	background: var(--colorbackbody, #fff);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
	cursor: default;
}

/* The pitch: camp 1 on the left, VS in the middle, camp 2 on the right.
   The grid is never broken into rows, whatever the screen width. */
.babyfoot-teams {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 0.7em;
	align-items: stretch;
	margin-bottom: 1.6em;
}

.babyfoot-team {
	position: relative;
	display: flex;
	flex-direction: column;
	min-width: 0;
	padding: 1.1em 0.9em 1em;
	border-radius: 10px;
	border: 1px solid var(--inputbordercolor, #dcdcdc);
	background: var(--colorbacktabcard1, #fff);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
	overflow: hidden;
}
/* Accent bar carrying the camp colour */
.babyfoot-team::before {
	content: "";
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	height: 4px;
}
.babyfoot-team1::before { background: #4a7ebb; }
.babyfoot-team2::before { background: #bb4a4a; }

.babyfoot-team-title {
	margin-bottom: 1em;
	font-size: 0.76em;
	font-weight: 700;
	text-align: center;
	text-transform: uppercase;
	letter-spacing: 0.09em;
}
.babyfoot-team1 .babyfoot-team-title { color: #4a7ebb; }
.babyfoot-team2 .babyfoot-team-title { color: #bb4a4a; }

/* Player selectors. Dolibarr renders them through select2, whose arrow is
   positioned against .select2-container: the container and the selection box
   must therefore be stretched together, otherwise the arrow drifts to the far
   right of the camp. */
.babyfoot-player-slot { margin-bottom: 0.5em; }
.babyfoot-player-slot select.babyfoot-player-select {
	width: 100%;
	min-width: 0;
	max-width: 100%;
}
.babyfoot-player-slot .select2-container {
	width: 100% !important;
	max-width: 100%;
}
.babyfoot-player-slot .select2-container .select2-selection--single {
	width: 100%;
	box-sizing: border-box;
}

/* VS badge */
.babyfoot-vs {
	align-self: center;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 2.4em;
	height: 2.4em;
	border-radius: 50%;
	border: 1px solid var(--inputbordercolor, #dcdcdc);
	background: var(--colorbackbody, #fff);
	font-size: 0.8em;
	font-weight: 700;
	letter-spacing: 0.02em;
	opacity: 0.75;
}

/* Score: a single field per camp, no stepper at all */
.babyfoot-score-row {
	margin-top: auto;
	padding-top: 1em;
	text-align: center;
}
.babyfoot-score-label {
	display: block;
	margin-bottom: 0.4em;
	font-size: 0.68em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.09em;
	opacity: 0.55;
}
.babyfoot-score-input {
	width: 2.1em;
	max-width: 100%;
	padding: 0.12em 0.1em;
	font-size: 2.4em;
	font-weight: 700;
	line-height: 1.25;
	text-align: center;
	border: 1px solid var(--inputbordercolor, #dcdcdc);
	border-radius: 8px;
	background: var(--inputbackgroundcolor, #fff);
	color: var(--colortext, #333);
}
.babyfoot-score-input:focus {
	outline: none;
	border-color: #4a7ebb;
	box-shadow: 0 0 0 3px rgba(74, 126, 187, 0.18);
}
.babyfoot-team2 .babyfoot-score-input:focus {
	border-color: #bb4a4a;
	box-shadow: 0 0 0 3px rgba(187, 74, 74, 0.18);
}
/* The stepper is what the entry screen deliberately does without */
.babyfoot-score-input::-webkit-outer-spin-button,
.babyfoot-score-input::-webkit-inner-spin-button {
	-webkit-appearance: none;
	margin: 0;
}
.babyfoot-score-input {
	-moz-appearance: textfield;
	appearance: textfield;
}

.babyfoot-submit { min-height: 46px; min-width: 240px; font-size: 1.05em; }

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

/* Phone: the left/right layout is kept, only the sizes shrink */
@media only screen and (max-width: 767px) {
	.babyfoot-teams { gap: 0.4em; }
	.babyfoot-team { padding: 0.9em 0.5em 0.8em; }
	.babyfoot-team-title { font-size: 0.68em; letter-spacing: 0.05em; }
	.babyfoot-mode-btn { padding: 0 1.1em; min-height: 44px; }
	.babyfoot-vs { width: 2em; height: 2em; font-size: 0.7em; }
	.babyfoot-score-input { font-size: 1.9em; width: 1.9em; }
	.babyfoot-submit { width: 100%; min-width: 0; min-height: 52px; }
}
