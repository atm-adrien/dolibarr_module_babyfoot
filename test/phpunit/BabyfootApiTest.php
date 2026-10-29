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
 * \file    test/phpunit/BabyfootApiTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the REST API class, called directly without HTTP.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
require_once dirname(__FILE__).'/../../class/api_babyfoot.class.php';

use Luracast\Restler\RestException;

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class BabyfootApiTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class BabyfootApiTest extends CommonClassTest
{
	/** @var bool Whether the module was already enabled before the test */
	private $moduleWasEnabled = false;

	/**
	 * Force the module as enabled: User::hasRight() returns 0 otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->moduleWasEnabled = !empty($conf->modules['babyfoot']);
		$conf->modules['babyfoot'] = 'babyfoot';
	}

	/**
	 * Restore the module state.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if (!$this->moduleWasEnabled) {
			unset($conf->modules['babyfoot']);
		}
		parent::tearDown();
	}

	/**
	 * Make the API act as user 1 holding only the given babyfoot permissions.
	 *
	 * @param	string[]	$rights		Permissions among read, create, modify_all
	 * @return	Babyfoot				API instance
	 */
	private function apiAs(array $rights): Babyfoot
	{
		global $user;

		$apiUser = clone $user;
		$apiUser->rights = new stdClass();
		$apiUser->rights->babyfoot = new stdClass();
		foreach ($rights as $right) {
			$apiUser->rights->babyfoot->{$right} = 1;
		}
		DolibarrApiAccess::$user = $apiUser;

		return new Babyfoot();
	}

	/**
	 * Body of a valid 1v1 game between users 1 and 2.
	 *
	 * @return array<string,mixed>	Request data
	 */
	private function validGameData(): array
	{
		return [
			'mode' => '1v1',
			'score_team1' => 10,
			'score_team2' => 4,
			'players' => [
				['fk_user' => 1, 'team' => 1],
				['fk_user' => 2, 'team' => 2],
			],
		];
	}

	/**
	 * Run a call and return the HTTP code of the RestException it raises.
	 *
	 * @param	callable	$call	Call expected to fail
	 * @return	int					HTTP code, 0 when nothing was raised
	 */
	private function codeOf(callable $call): int
	{
		try {
			$call();
		} catch (RestException $e) {
			return (int) $e->getCode();
		}

		return 0;
	}

	/**
	 * A posted game is stored and read back with its players and Elo changes.
	 *
	 * @return void
	 */
	public function testPostThenGetGame()
	{
		$api = $this->apiAs(['read', 'create']);

		$id = $api->postGame($this->validGameData());
		$this->assertGreaterThan(0, $id);

		$game = $api->getGame($id);
		$this->assertSame($id, $game['id']);
		$this->assertSame('1v1', $game['mode']);
		$this->assertSame(1, $game['winner_team']);
		$this->assertCount(2, $game['players']);
		foreach ($game['players'] as $player) {
			$this->assertSame(((int) $player['team'] === 1) ? 1 : 0, $player['is_winner']);
			$this->assertSame($player['elo_after'] - $player['elo_before'], $player['elo_delta']);
		}
	}

	/**
	 * A player may be designated by login instead of id.
	 *
	 * @return void
	 */
	public function testPostResolvesPlayerByLogin()
	{
		global $db;

		$other = new User($db);
		$this->assertGreaterThan(0, $other->fetch(2));

		$api = $this->apiAs(['read', 'create']);
		$data = $this->validGameData();
		$data['players'][1] = ['login' => $other->login, 'team' => 2];

		$game = $api->getGame($api->postGame($data));
		$ids = array_column($game['players'], 'fk_user', 'team');
		$this->assertSame(2, $ids[2]);
	}

	/**
	 * A broken business rule or an unknown player is a 400, the right is checked first.
	 *
	 * @return void
	 */
	public function testPostRejectsInvalidGames()
	{
		$api = $this->apiAs(['read', 'create']);

		$badScore = $this->validGameData();
		$badScore['score_team1'] = 9;
		$this->assertSame(400, $this->codeOf(function () use ($api, $badScore) {
			$api->postGame($badScore);
		}));

		$unknownLogin = $this->validGameData();
		$unknownLogin['players'][1] = ['login' => 'no-such-login-'.uniqid(), 'team' => 2];
		$this->assertSame(400, $this->codeOf(function () use ($api, $unknownLogin) {
			$api->postGame($unknownLogin);
		}));

		$badTeam = $this->validGameData();
		$badTeam['players'][1]['team'] = 3;
		$this->assertSame(400, $this->codeOf(function () use ($api, $badTeam) {
			$api->postGame($badTeam);
		}));

		$readOnly = $this->apiAs(['read']);
		$data = $this->validGameData();
		$this->assertSame(403, $this->codeOf(function () use ($readOnly, $data) {
			$readOnly->postGame($data);
		}));
	}

	/**
	 * PUT changes only the given fields and keeps the players when none are given.
	 *
	 * @return void
	 */
	public function testPutUpdatesScoresAndKeepsPlayers()
	{
		$api = $this->apiAs(['read', 'create', 'modify_all']);
		$id = $api->postGame($this->validGameData());

		$game = $api->putGame($id, ['score_team1' => 3, 'score_team2' => 10]);
		$this->assertSame(3, $game['score_team1']);
		$this->assertSame(2, $game['winner_team']);
		$ids = array_column($game['players'], 'fk_user', 'team');
		$this->assertSame([1 => 1, 2 => 2], $ids);
	}

	/**
	 * PUT requires modify_all, and an unknown game is a 404.
	 *
	 * @return void
	 */
	public function testPutAndGetGuards()
	{
		$creator = $this->apiAs(['read', 'create']);
		$id = $creator->postGame($this->validGameData());

		$this->assertSame(403, $this->codeOf(function () use ($creator, $id) {
			$creator->putGame($id, ['score_team1' => 3, 'score_team2' => 10]);
		}));
		$this->assertSame(404, $this->codeOf(function () use ($creator) {
			$creator->getGame(999999999);
		}));
	}

	/**
	 * The list filters on the games a user played.
	 *
	 * @return void
	 */
	public function testListFiltersByPlayer()
	{
		$api = $this->apiAs(['read', 'create']);
		$id = $api->postGame($this->validGameData());

		$games = $api->listGames('t.date_game', 'DESC', 0, 0, '1v1', 1, 2);
		$this->assertContains($id, array_column($games, 'id'));
		foreach ($games as $game) {
			$this->assertContains(2, array_column($game['players'], 'fk_user'));
		}
	}

	/**
	 * GET /me answers with the permissions and the module constants.
	 *
	 * @return void
	 */
	public function testMeExposesRightsAndConstants()
	{
		$me = $this->apiAs(['read', 'create'])->getMe();

		$this->assertSame(['read' => true, 'create' => true, 'modify_all' => false, 'admin' => false], $me['rights']);
		$this->assertSame(BabyfootConfig::SCORE_MAX, $me['score_max']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $me['module_version']);
	}

	/**
	 * Games carry the names of their players, and the search matches them.
	 *
	 * @return void
	 */
	public function testGamesCarryPlayerNamesAndAreSearchable()
	{
		global $db;

		$other = new User($db);
		$this->assertGreaterThan(0, $other->fetch(2));

		$api = $this->apiAs(['read', 'create']);
		$id = $api->postGame($this->validGameData());

		$game = $api->getGame($id);
		foreach ($game['players'] as $player) {
			$this->assertNotSame('', $player['name']);
		}
		$this->assertContains($other->login, array_column($game['players'], 'login'));

		$found = $api->listGames('t.date_game', 'DESC', 0, 0, '', -1, 0, 0, 0, $other->login);
		$this->assertContains($id, array_column($found, 'id'));
		$notFound = $api->listGames('t.date_game', 'DESC', 0, 0, '', -1, 0, 0, 0, 'no-such-player-'.uniqid());
		$this->assertNotContains($id, array_column($notFound, 'id'));
	}

	/**
	 * Cancel, reopen and delete, each refused when the status does not allow it.
	 *
	 * @return void
	 */
	public function testCancelReopenDeleteLifecycle()
	{
		$api = $this->apiAs(['read', 'create', 'modify_all']);
		$id = $api->postGame($this->validGameData());

		$this->assertSame(400, $this->codeOf(function () use ($api, $id) {
			$api->reopenGame($id);
		}));
		$this->assertSame(Game::STATUS_CANCELED, $api->cancelGame($id)['status']);
		$this->assertSame(400, $this->codeOf(function () use ($api, $id) {
			$api->cancelGame($id);
		}));
		$this->assertSame(Game::STATUS_VALIDATED, $api->reopenGame($id)['status']);

		$api->deleteGame($id);
		$this->assertSame(404, $this->codeOf(function () use ($api, $id) {
			$api->getGame($id);
		}));

		$creator = $this->apiAs(['read', 'create']);
		$other = $creator->postGame($this->validGameData());
		$this->assertSame(403, $this->codeOf(function () use ($creator, $other) {
			$creator->deleteGame($other);
		}));
	}

	/**
	 * The player list starts from the users: someone who never played is listed, without Elo.
	 *
	 * @return void
	 */
	public function testPlayersIncludeUsersWhoNeverPlayed()
	{
		global $db, $user;

		$newcomer = new User($db);
		$newcomer->login = 'bfapitest'.uniqid();
		$newcomer->lastname = 'Newcomer';
		$this->assertGreaterThan(0, $newcomer->create($user));

		$players = $this->apiAs(['read'])->listPlayers();
		$byId = array_column($players, null, 'fk_user');
		$this->assertArrayHasKey($newcomer->id, $byId);
		$this->assertNull($byId[$newcomer->id]['elo']);
		$this->assertSame(0, $byId[$newcomer->id]['nb_games']);
	}

	/**
	 * Player card and Elo history of a player who played, 404 for an unknown id.
	 *
	 * @return void
	 */
	public function testPlayerCardAndEloHistory()
	{
		$api = $this->apiAs(['read', 'create']);
		$api->postGame($this->validGameData());

		$card = $api->getPlayer(2);
		$this->assertSame(2, $card['player']['fk_user']);
		$this->assertArrayHasKey(BabyfootConfig::MODE_ALL, (array) $card['ratings']);
		$this->assertNotEmpty($card['last_games']);

		$history = $api->getPlayerEloHistory(2, BabyfootConfig::MODE_ALL);
		$this->assertCount(((array) $card['ratings'])[BabyfootConfig::MODE_ALL]['nb_games'], $history);

		$this->assertSame(404, $this->codeOf(function () use ($api) {
			$api->getPlayer(999999999);
		}));
		$this->assertSame(400, $this->codeOf(function () use ($api) {
			$api->getPlayerEloHistory(2, '3v3');
		}));
	}

	/**
	 * Ranking rows are numbered and named; statistics follow the period; an unknown period is a 400.
	 *
	 * @return void
	 */
	public function testRankingAndStats()
	{
		$api = $this->apiAs(['read', 'create']);
		$api->postGame($this->validGameData());

		$ranking = $api->getRanking(BabyfootConfig::MODE_ALL, '30');
		$this->assertNotEmpty($ranking);
		$this->assertSame(range(1, count($ranking)), array_column($ranking, 'rank'));
		$this->assertNotSame('', $ranking[0]['name']);

		$stats = $api->getStats('30');
		$this->assertGreaterThanOrEqual(1, $stats['totals']['nb_games']);
		$this->assertCount(7, $stats['by_weekday']);
		$this->assertCount(24, $stats['by_hour']);
		$this->assertNotNull($stats['most_active_player']);

		$this->assertSame(400, $this->codeOf(function () use ($api) {
			$api->getStats('7');
		}));
		$this->assertSame(400, $this->codeOf(function () use ($api) {
			$api->getRanking(BabyfootConfig::MODE_ALL, 'week');
		}));
	}

	/**
	 * Create a user and move it into another entity, or disable it.
	 *
	 * @param	string	$change		'foreign' to move it to another entity, 'disabled' to disable it
	 * @return	int					Id of the user
	 */
	private function makeUnplayableUser(string $change): int
	{
		global $db, $user;

		$other = new User($db);
		$other->login = 'bfapitest'.uniqid();
		$other->lastname = 'Unplayable';
		$id = $other->create($user);
		$this->assertGreaterThan(0, $id);

		$set = ($change === 'foreign') ? "entity = 999999" : "statut = 0";
		$this->assertNotFalse($db->query("UPDATE ".$db->prefix()."user SET ".$set." WHERE rowid = ".((int) $id)));

		return (int) $id;
	}

	/**
	 * A user of another entity is invisible: no card, and it cannot play.
	 *
	 * @return void
	 */
	public function testUserOfAnotherEntityIsInvisible()
	{
		$foreignId = $this->makeUnplayableUser('foreign');
		$api = $this->apiAs(['read', 'create']);

		$this->assertSame(404, $this->codeOf(function () use ($api, $foreignId) {
			$api->getPlayer($foreignId);
		}));

		$data = $this->validGameData();
		$data['players'][1] = ['fk_user' => $foreignId, 'team' => 2];
		$this->assertSame(400, $this->codeOf(function () use ($api, $data) {
			$api->postGame($data);
		}));
	}

	/**
	 * A disabled user cannot be put in a game, as on the quick entry screen.
	 *
	 * @return void
	 */
	public function testDisabledUserCannotPlay()
	{
		$disabledId = $this->makeUnplayableUser('disabled');
		$api = $this->apiAs(['read', 'create']);

		$data = $this->validGameData();
		$data['players'][1] = ['fk_user' => $disabledId, 'team' => 2];
		$this->assertSame(400, $this->codeOf(function () use ($api, $data) {
			$api->postGame($data);
		}));
	}

	/**
	 * Sorting on a column outside the whitelist is a 400, not a database error.
	 *
	 * @return void
	 */
	public function testUnknownSortFieldIsRejected()
	{
		$api = $this->apiAs(['read']);

		$this->assertSame(400, $this->codeOf(function () use ($api) {
			$api->listGames('u.pass');
		}));
		$this->assertSame(0, $this->codeOf(function () use ($api) {
			$api->listGames('t.date_game,t.rowid', 'DESC,DESC', 1);
		}));
	}

	/**
	 * GET /me refuses to answer while the module is disabled.
	 *
	 * @return void
	 */
	public function testMeRefusedWhenModuleDisabled()
	{
		global $conf;

		$api = $this->apiAs(['read']);
		unset($conf->modules['babyfoot']);

		$this->assertSame(403, $this->codeOf(function () use ($api) {
			$api->getMe();
		}));
	}
}
