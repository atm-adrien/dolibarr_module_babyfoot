<?php
/**
 * \file       tests/e2e/fixtures/setup.php
 * \brief      e2e dataset: four players and a non admin runner holding every babyfoot right
 *             and an API key. Prints the ids and the runner credentials as JSON on stdout.
 */
require_once dirname(__FILE__).'/bootstrap.php';

/** Every account of the dataset carries this login prefix: teardown.php deletes by it. */
const E2E_LOGIN_PREFIX = 'e2ebabyfoot_';

/**
 * Create an active internal user.
 *
 * @param	string	$suffix		Login suffix, also used as last name
 * @param	string	$password	Clear password, empty for none
 * @return	int					Id of the new user
 */
function createE2eUser(string $suffix, string $password = ''): int
{
	global $db, $user;

	$newUser = new User($db);
	$newUser->login = E2E_LOGIN_PREFIX.$suffix;
	$newUser->lastname = 'E2EBabyfoot '.$suffix;
	$newUser->firstname = '';
	$newUser->admin = 0;
	$newUser->entity = (int) $GLOBALS['conf']->entity;
	$id = $newUser->create($user);
	if ($id <= 0) {
		fwrite(STDERR, "user ".$suffix." creation failed: ".$newUser->error."\n");
		exit(1);
	}
	if ($password !== '' && $newUser->setPassword($user, $password) === -1) {
		fwrite(STDERR, "user ".$suffix." password failed: ".$newUser->error."\n");
		exit(1);
	}

	return (int) $id;
}

$password = bin2hex(random_bytes(12));
$runnerId = createE2eUser('runner', $password);

$runner = new User($db);
$runner->fetch($runnerId);
if ($runner->addrights(0, 'babyfoot') < 0) {
	fwrite(STDERR, "rights grant failed: ".$runner->error."\n");
	exit(1);
}
$apiKey = bin2hex(random_bytes(16));
$runner->api_key = $apiKey;
if ($runner->update($user) < 0) {
	fwrite(STDERR, "api key failed: ".$runner->error."\n");
	exit(1);
}

$players = [];
for ($i = 1; $i <= 4; $i++) {
	$players['p'.$i] = ['id' => createE2eUser('p'.$i), 'label' => 'E2EBabyfoot p'.$i];
}

echo json_encode([
	'login' => $runner->login,
	'password' => $password,
	'api_key' => $apiKey,
	'runner_id' => $runnerId,
	'players' => $players,
]), "\n";
