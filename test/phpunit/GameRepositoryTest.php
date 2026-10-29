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
 * \file    test/phpunit/GameRepositoryTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the game reader used by the REST API.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/stats/gamerepository.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class GameRepositoryTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class GameRepositoryTest extends CommonClassTest
{
	/**
	 * Store a 1v1 game between users 1 and 2.
	 *
	 * @return	int		Id of the game
	 */
	private function storeGame(): int
	{
		global $db, $user;

		$game = new Game($db);
		$game->date_game = dol_now() - 60;
		$game->mode = '1v1';
		$game->score_team1 = 10;
		$game->score_team2 = 3;
		$game->setPlayers([['fk_user' => 1, 'team' => 1], ['fk_user' => 2, 'team' => 2]]);
		$id = $game->create($user);
		$this->assertGreaterThan(0, $id, 'create() failed: '.$game->error);

		return (int) $id;
	}

	/**
	 * @return	GameRepository	Repository on the current entity
	 */
	private function repository(): GameRepository
	{
		global $db, $conf;

		return new GameRepository($db, (int) $conf->entity);
	}

	/**
	 * getByIds() keeps the order asked, skips unknown ids and labels every player.
	 *
	 * @return void
	 */
	public function testGetByIdsKeepsOrderAndLabelsPlayers()
	{
		$first = $this->storeGame();
		$second = $this->storeGame();

		$games = $this->repository()->getByIds([$second, 999999999, $first]);
		$this->assertSame([$second, $first], array_column($games, 'id'));
		foreach ($games as $game) {
			$this->assertCount(2, $game['players']);
			foreach ($game['players'] as $player) {
				$this->assertNotSame('', $player['name']);
			}
		}
	}

	/**
	 * The search matches the login of a player, and nothing for an unknown text.
	 *
	 * @return void
	 */
	public function testSearchMatchesPlayerLogin()
	{
		global $db;

		$id = $this->storeGame();
		$other = new User($db);
		$this->assertGreaterThan(0, $other->fetch(2));

		$this->assertContains($id, array_column($this->repository()->search(['search' => $other->login], 't.rowid', 'DESC', 0), 'id'));
		$this->assertSame([], $this->repository()->search(['search' => 'no-such-player-'.uniqid()], 't.rowid', 'DESC', 0));
	}

	/**
	 * A user that no longer exists still gets a label.
	 *
	 * @return void
	 */
	public function testPlayerLabelOfMissingUser()
	{
		$labels = $this->repository()->getPlayerLabels([999999999]);

		$this->assertSame('#999999999', $labels[999999999]['name']);
	}
}
