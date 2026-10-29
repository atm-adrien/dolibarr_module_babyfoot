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
		return array(
			'mode' => '1v1',
			'score_team1' => 10,
			'score_team2' => 4,
			'players' => array(
				array('fk_user' => 1, 'team' => 1),
				array('fk_user' => 2, 'team' => 2),
			),
		);
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
		$api = $this->apiAs(array('read', 'create'));

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

		$api = $this->apiAs(array('read', 'create'));
		$data = $this->validGameData();
		$data['players'][1] = array('login' => $other->login, 'team' => 2);

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
		$api = $this->apiAs(array('read', 'create'));

		$badScore = $this->validGameData();
		$badScore['score_team1'] = 9;
		$this->assertSame(400, $this->codeOf(function () use ($api, $badScore) {
			$api->postGame($badScore);
		}));

		$unknownLogin = $this->validGameData();
		$unknownLogin['players'][1] = array('login' => 'no-such-login-'.uniqid(), 'team' => 2);
		$this->assertSame(400, $this->codeOf(function () use ($api, $unknownLogin) {
			$api->postGame($unknownLogin);
		}));

		$badTeam = $this->validGameData();
		$badTeam['players'][1]['team'] = 3;
		$this->assertSame(400, $this->codeOf(function () use ($api, $badTeam) {
			$api->postGame($badTeam);
		}));

		$readOnly = $this->apiAs(array('read'));
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
		$api = $this->apiAs(array('read', 'create', 'modify_all'));
		$id = $api->postGame($this->validGameData());

		$game = $api->putGame($id, array('score_team1' => 3, 'score_team2' => 10));
		$this->assertSame(3, $game['score_team1']);
		$this->assertSame(2, $game['winner_team']);
		$ids = array_column($game['players'], 'fk_user', 'team');
		$this->assertSame(array(1 => 1, 2 => 2), $ids);
	}

	/**
	 * PUT requires modify_all, and an unknown game is a 404.
	 *
	 * @return void
	 */
	public function testPutAndGetGuards()
	{
		$creator = $this->apiAs(array('read', 'create'));
		$id = $creator->postGame($this->validGameData());

		$this->assertSame(403, $this->codeOf(function () use ($creator, $id) {
			$creator->putGame($id, array('score_team1' => 3, 'score_team2' => 10));
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
		$api = $this->apiAs(array('read', 'create'));
		$id = $api->postGame($this->validGameData());

		$games = $api->listGames('t.date_game', 'DESC', 0, 0, '1v1', 1, 2);
		$this->assertContains($id, array_column($games, 'id'));
		foreach ($games as $game) {
			$this->assertContains(2, array_column($game['players'], 'fk_user'));
		}
	}
}
