<?php
/**
 * \file       tests/e2e/fixtures/module.php
 * \brief      Report or switch the state of the babyfoot module: status | on | off.
 *
 * Menus are written to the database at activation only, so an instance enabled before
 * the menus moved to Tools reports 'stale' and needs off then on, in separate processes.
 */
require_once dirname(__FILE__).'/bootstrap.php';

$command = isset($argv[1]) ? $argv[1] : 'status';

if ($command === 'status') {
	if (!isModEnabled('babyfoot')) {
		echo "off\n";
		exit(0);
	}
	$sql = "SELECT COUNT(rowid) AS nb FROM ".$db->prefix()."menu";
	$sql .= " WHERE module = 'babyfoot' AND type = 'left' AND mainmenu = 'tools'";
	$sql .= " AND entity = ".((int) $conf->entity);
	$resql = $db->query($sql);
	if (!$resql) {
		fwrite(STDERR, "menu lookup failed: ".$db->lasterror()."\n");
		exit(1);
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	echo ((int) $obj->nb > 0 ? 'ok' : 'stale'), "\n";
	exit(0);
}

if ($command === 'on') {
	$result = activateModule('modBabyfoot');
	if (!empty($result['errors'])) {
		fwrite(STDERR, "activation failed: ".implode(', ', $result['errors'])."\n");
		exit(1);
	}
	echo "on\n";
	exit(0);
}

if ($command === 'off') {
	if (unActivateModule('modBabyfoot') !== '') {
		fwrite(STDERR, "deactivation failed\n");
		exit(1);
	}
	echo "off\n";
	exit(0);
}

fwrite(STDERR, "usage: module.php status|on|off\n");
exit(1);
