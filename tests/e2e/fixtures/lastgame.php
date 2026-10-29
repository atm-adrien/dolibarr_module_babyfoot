<?php
/**
 * \file       tests/e2e/fixtures/lastgame.php
 * \brief      Print as JSON the number of games played by an e2e account and the latest one.
 */
require_once dirname(__FILE__).'/bootstrap.php';

$userFilter = "SELECT u.rowid FROM ".$db->prefix()."user AS u WHERE u.login LIKE 'e2ebabyfoot\\_%'";

$sql = "SELECT g.rowid, g.mode, g.score_team1, g.score_team2";
$sql .= " FROM ".$db->prefix()."babyfoot_game AS g";
$sql .= " WHERE g.entity = ".((int) $conf->entity);
$sql .= " AND EXISTS (SELECT gp.rowid FROM ".$db->prefix()."babyfoot_game_player AS gp";
$sql .= " WHERE gp.fk_game = g.rowid AND gp.fk_user IN (".$userFilter."))";
$sql .= " ORDER BY g.rowid DESC";
$resql = $db->query($sql);
if (!$resql) {
	fwrite(STDERR, "game lookup failed: ".$db->lasterror()."\n");
	exit(1);
}
$count = $db->num_rows($resql);
$last = $db->fetch_object($resql);
$db->free($resql);

$result = ['count' => $count, 'last' => null];
if ($last) {
	$players = [];
	$sql = "SELECT fk_user, team FROM ".$db->prefix()."babyfoot_game_player WHERE fk_game = ".((int) $last->rowid);
	$resql = $db->query($sql);
	if (!$resql) {
		fwrite(STDERR, "player lookup failed: ".$db->lasterror()."\n");
		exit(1);
	}
	while ($obj = $db->fetch_object($resql)) {
		$players[(int) $obj->fk_user] = (int) $obj->team;
	}
	$db->free($resql);

	$result['last'] = [
		'id' => (int) $last->rowid,
		'mode' => $last->mode,
		'score_team1' => (int) $last->score_team1,
		'score_team2' => (int) $last->score_team2,
		'players' => $players,
	];
}

echo json_encode($result), "\n";
