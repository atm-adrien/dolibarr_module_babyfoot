<?php
/**
 * \file       tests/e2e/fixtures/bootstrap.php
 * \brief      Dolibarr bootstrap shared by the e2e fixture scripts. CLI only.
 */

// These scripts create accounts and business data, and they live under htdocs/: reaching them
// over HTTP would hand that to an anonymous visitor.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

// conf.php may point at a copy of a customer database: writing to it takes an explicit opt-in.
if (getenv('E2E_ALLOW_FIXTURES') !== '1') {
	fwrite(STDERR, "E2E_ALLOW_FIXTURES=1 is required: the fixtures write to the database of conf.php\n");
	exit(1);
}

// No NOREQUIRE* define: fixtures creating invoices or orders need $mysoc, loaded by the master file.

// Walk up to htdocs/ instead of hardcoding a depth: the module may sit at custom/<mod>/ or deeper.
$masterPath = '';
for ($dir = dirname(__FILE__), $i = 0; $i < 10; $i++) {
	if (file_exists($dir.'/master.inc.php')) {
		$masterPath = $dir.'/master.inc.php';
		break;
	}
	$parent = dirname($dir);
	if ($parent === $dir) {
		break;
	}
	$dir = $parent;
}
if ($masterPath === '') {
	fwrite(STDERR, "master.inc.php not found above ".dirname(__FILE__)."\n");
	exit(1);
}
// A CLI run without DOLENTITY lands in entity 1, whatever the instance.
$entity = (string) getenv('E2E_ENTITY');
$entity = $entity === '' ? '1' : $entity;
if (! ctype_digit($entity) || (int) $entity < 1) {
	fwrite(STDERR, "E2E_ENTITY must be a positive integer, got '".$entity."'\n");
	exit(1);
}
define('DOLENTITY', (int) $entity);
require_once $masterPath;
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

global $conf, $db, $user, $langs, $mysoc;

// Fixtures are written as an administrator, never as the account under test. rowid 1 is not
// one on every instance: E2E_ADMIN_LOGIN names it, else the first active one, superadmin first.
$user = new User($db);
$adminLogin = (string) getenv('E2E_ADMIN_LOGIN');
$adminId = 0;
if ($adminLogin === '') {
	$sql = "SELECT rowid FROM ".$db->prefix()."user";
	$sql .= " WHERE admin = 1 AND statut = ".((int) User::STATUS_ENABLED);
	$sql .= " AND entity IN (0, ".((int) $conf->entity).")";
	$sql .= " ORDER BY entity ASC, rowid ASC";
	$sql .= $db->plimit(1);
	$resql = $db->query($sql);
	if (! $resql) {
		fwrite(STDERR, "admin lookup failed: ".$db->lasterror()."\n");
		exit(1);
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (! $obj) {
		fwrite(STDERR, "no active administrator in entity ".((int) $conf->entity)."; set E2E_ADMIN_LOGIN\n");
		exit(1);
	}
	$adminId = (int) $obj->rowid;
}
$result = $user->fetch($adminId, $adminLogin);
if ($result < 0) {
	fwrite(STDERR, "admin fetch failed: ".$user->error."\n");
	exit(1);
}
// Before v17, fetch() fills only the deprecated $statut.
$status = isset($user->status) ? $user->status : $user->statut;
if ($result === 0 || empty($user->admin) || (int) $status !== User::STATUS_ENABLED) {
	fwrite(STDERR, "E2E_ADMIN_LOGIN '".$adminLogin."' is not an active administrator\n");
	exit(1);
}
// fetch() by login is not bounded to the entity when the caller has none, as here.
if (! in_array((int) $user->entity, array(0, (int) $conf->entity), true)) {
	fwrite(STDERR, "E2E_ADMIN_LOGIN '".$adminLogin."' belongs to entity ".((int) $user->entity).", not ".((int) $conf->entity)."\n");
	exit(1);
}
// loadRights() exists from v20 only; getrights() is deprecated from v20 on.
if (method_exists($user, 'loadRights')) {
	$user->loadRights();
} else {
	$user->getrights();
}
