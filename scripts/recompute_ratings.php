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
 * \file    scripts/recompute_ratings.php
 * \ingroup babyfoot
 * \brief   Rebuild the whole ranking from the command line.
 *
 * Usage: php scripts/recompute_ratings.php <entity> [--check]
 *        --check reports the drifts without fixing anything.
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	print "This script must be run from the command line only\n";
	exit(1);
}

if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}

$path = dirname(__FILE__).'/';

require_once $path.'../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once $path.'../class/babyfootconfig.class.php';
require_once $path.'../class/game.class.php';
require_once $path.'../class/ratingengine.class.php';

$entityArg = isset($argv[1]) ? (int) $argv[1] : 0;
$checkOnly = (isset($argv[2]) && $argv[2] === '--check');

if ($entityArg <= 0) {
	print "Usage: php recompute_ratings.php <entity> [--check]\n";
	print "  <entity>  Dolibarr entity to work on, 1 on a single company instance\n";
	print "  --check   report the drifts without fixing anything\n";
	exit(1);
}

// The engine writes through Rating objects, which need a user for the audit fields
$user = new User($db);
if ($user->fetch(1) <= 0) {
	print "Cannot load the administrator user (id 1)\n";
	exit(1);
}

$conf->entity = $entityArg;

$engine = new RatingEngine($db, $entityArg);

if ($checkOnly) {
	$drifts = $engine->checkConsistency();
	if (empty($drifts)) {
		print "Entity ".$entityArg.": ranking is consistent, no drift found\n";
		$db->close();
		exit(0);
	}

	print "Entity ".$entityArg.": ".count($drifts)." drift(s) found\n";
	foreach ($drifts as $drift) {
		printf("  user %-6d mode %-4s %-16s stored=%-8s expected=%s\n",
			(int) $drift['fk_user'],
			(string) $drift['mode'],
			(string) $drift['field'],
			(string) $drift['stored'],
			(string) $drift['expected']
		);
	}
	$db->close();
	exit(1);
}

try {
	$result = $engine->recomputeAll();
} catch (Exception $e) {
	print "Entity ".$entityArg.": rebuild FAILED (".$e->getMessage()."), nothing was changed\n";
	$db->close();
	exit(1);
}

printf("Entity %d: %d game(s) replayed in %.3f s\n", $entityArg, (int) $result['nb_games'], (float) $result['duration']);

$db->close();
exit(0);
