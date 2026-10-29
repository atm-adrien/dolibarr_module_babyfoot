<?php
/**
 * \file       tests/e2e/fixtures/teardown.php
 * \brief      Drop every game played by an e2e account, then the accounts, then rebuild the ranking.
 */
require_once dirname(__FILE__).'/bootstrap.php';
require_once dirname(__FILE__).'/../../../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../../../class/ratingengine.class.php';

$userFilter = "SELECT u.rowid FROM ".$db->prefix()."user AS u WHERE u.login LIKE 'e2ebabyfoot\\_%'";

$gameFilter = "SELECT gp.fk_game FROM ".$db->prefix()."babyfoot_game_player AS gp";
$gameFilter .= " WHERE gp.fk_user IN (".$userFilter.")";

$db->begin();
$sqls = array(
	"DELETE FROM ".$db->prefix()."babyfoot_game WHERE rowid IN (SELECT fk_game FROM (".$gameFilter.") AS g)",
	"DELETE FROM ".$db->prefix()."babyfoot_game_player WHERE fk_game NOT IN (SELECT rowid FROM ".$db->prefix()."babyfoot_game)",
);
foreach ($sqls as $sql) {
	if (!$db->query($sql)) {
		fwrite(STDERR, "game cleanup failed: ".$db->lasterror()."\n");
		$db->rollback();
		exit(1);
	}
}
$db->commit();

$resql = $db->query($userFilter);
if (!$resql) {
	fwrite(STDERR, "user lookup failed: ".$db->lasterror()."\n");
	exit(1);
}
$ids = array();
while ($obj = $db->fetch_object($resql)) {
	$ids[] = (int) $obj->rowid;
}
$db->free($resql);

foreach ($ids as $id) {
	$e2eUser = new User($db);
	if ($e2eUser->fetch($id) > 0 && $e2eUser->delete($user) < 0) {
		fwrite(STDERR, "user ".$id." deletion failed: ".$e2eUser->error."\n");
		exit(1);
	}
}

if (isModEnabled('babyfoot')) {
	try {
		$engine = new RatingEngine($db, (int) $conf->entity, BabyfootConfig::resolve());
		$engine->recomputeAll();
	} catch (Exception $e) {
		fwrite(STDERR, "ranking rebuild failed: ".$e->getMessage()."\n");
		exit(1);
	}
}

echo json_encode(array('deleted_users' => count($ids))), "\n";
