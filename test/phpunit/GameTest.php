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
 * \file    test/phpunit/GameTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the Game and GamePlayer classes.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/gameplayer.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class GameTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class GameTest extends CommonClassTest
{
	/**
	 * Build a valid, unsaved 1v1 game between two users.
	 *
	 * @param	int		$score1			Goals of team 1
	 * @param	int		$score2			Goals of team 2
	 * @param	int		$ageSeconds		How long ago the game was played
	 * @return	Game					Unsaved game
	 */
	private function makeGame($score1 = 10, $score2 = 6, $ageSeconds = 3600)
	{
		global $db;

		$game = new Game($db);
		$game->date_game = dol_now() - $ageSeconds;
		$game->mode = '1v1';
		$game->score_team1 = $score1;
		$game->score_team2 = $score2;
		$game->setPlayers(array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 2),
		));

		return $game;
	}

	/**
	 * Count the games of the current entity.
	 *
	 * @return	int		Number of games
	 */
	private function countGames()
	{
		global $db, $conf;

		$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."babyfoot_game WHERE entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return (int) $obj->nb;
	}

	/**
	 * A valid game is stored and gets a reference matching the mask.
	 *
	 * @return void
	 */
	public function testCreateGeneratesReference()
	{
		global $user;

		$game = $this->makeGame();
		$id = $game->create($user);
		$this->assertGreaterThan(0, $id, 'create() failed: '.$game->error);
		$this->assertMatchesRegularExpression('/^BF[0-9]{4}-[0-9]{4}$/', $game->ref);
	}

	/**
	 * The winning team is deduced from the score, never entered (section 6.1).
	 *
	 * @return void
	 */
	public function testCreateDeducesWinnerTeam()
	{
		global $user;

		$game = $this->makeGame(10, 6);
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$this->assertSame(1, (int) $game->winner_team);

		$other = $this->makeGame(2, 10);
		$this->assertGreaterThan(0, $other->create($user), 'create() failed: '.$other->error);
		$this->assertSame(2, (int) $other->winner_team);
	}

	/**
	 * Players are stored with the right team and is_winner flag.
	 *
	 * @return void
	 */
	public function testCreateStoresPlayersWithWinnerFlag()
	{
		global $user, $db;

		$game = $this->makeGame(10, 6);
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);

		$reloaded = new Game($db);
		$this->assertGreaterThan(0, $reloaded->fetch($game->id));
		$this->assertSame(1, $reloaded->fetchLines());
		$this->assertCount(2, $reloaded->lines);

		foreach ($reloaded->lines as $line) {
			if ((int) $line->team === 1) {
				$this->assertSame(1, (int) $line->is_winner);
			} else {
				$this->assertSame(0, (int) $line->is_winner);
			}
		}
	}

	/**
	 * An invalid game is refused and reports the rules it violates.
	 *
	 * @return void
	 */
	public function testCreateRefusesInvalidGame()
	{
		global $user;

		$game = $this->makeGame(10, 6);
		$game->setPlayers(array(array('fk_user' => 1, 'team' => 1)));
		$this->assertSame(-1, $game->create($user));
		$this->assertContains('BabyfootErrPlayerCount', $game->validationErrors);
	}

	/**
	 * A refused game leaves nothing behind.
	 *
	 * @return void
	 */
	public function testRefusedGameStoresNothing()
	{
		global $user;

		$before = $this->countGames();
		$game = $this->makeGame(10, 10);
		$this->assertSame(-1, $game->create($user));
		$this->assertSame($before, $this->countGames());
	}

	/**
	 * A game played in the future is refused (RG-06).
	 *
	 * @return void
	 */
	public function testCreateRefusesFutureGame()
	{
		global $user;

		$game = $this->makeGame(10, 6, -3600);
		$this->assertSame(-1, $game->create($user));
		$this->assertContains('BabyfootErrFutureDate', $game->validationErrors);
	}

	/**
	 * Updating a game replaces its players and recomputes the winner.
	 *
	 * @return void
	 */
	public function testUpdateReplacesPlayers()
	{
		global $user, $db;

		$game = $this->makeGame(10, 6);
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);

		$game->score_team1 = 4;
		$game->score_team2 = 10;
		$game->setPlayers(array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 3, 'team' => 2),
		));
		$this->assertGreaterThan(0, $game->update($user), 'update() failed: '.$game->error);

		$reloaded = new Game($db);
		$reloaded->fetch($game->id);
		$reloaded->fetchLines();
		$this->assertSame(2, (int) $reloaded->winner_team);

		$userIds = array();
		foreach ($reloaded->lines as $line) {
			$userIds[] = (int) $line->fk_user;
		}
		sort($userIds);
		$this->assertSame(array(1, 3), $userIds);
	}

	/**
	 * Cancelling keeps the record but flips its status (RG-33).
	 *
	 * @return void
	 */
	public function testCancelKeepsTheRecord()
	{
		global $user, $db;

		$game = $this->makeGame();
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$this->assertGreaterThan(0, $game->cancel($user), 'cancel() failed: '.$game->error);

		$reloaded = new Game($db);
		$this->assertGreaterThan(0, $reloaded->fetch($game->id));
		$this->assertSame(Game::STATUS_CANCELED, (int) $reloaded->status);
	}

	/**
	 * A cancelled game can be reopened.
	 *
	 * @return void
	 */
	public function testReopenRestoresValidatedStatus()
	{
		global $user, $db;

		$game = $this->makeGame();
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$this->assertGreaterThan(0, $game->cancel($user), 'cancel() failed: '.$game->error);
		$this->assertGreaterThan(0, $game->reopen($user), 'reopen() failed: '.$game->error);

		$reloaded = new Game($db);
		$reloaded->fetch($game->id);
		$this->assertSame(Game::STATUS_VALIDATED, (int) $reloaded->status);
	}

	/**
	 * Deleting a game cascades on its players (RG-33).
	 *
	 * @return void
	 */
	public function testDeleteCascadesOnPlayers()
	{
		global $user, $db;

		$game = $this->makeGame();
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$gameId = (int) $game->id;
		$this->assertGreaterThan(0, $game->delete($user), 'delete() failed: '.$game->error);

		$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."babyfoot_game_player WHERE fk_game = ".$gameId;
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		$this->assertSame(0, (int) $obj->nb);
	}

	/**
	 * fetch() returns 0 on a missing record: 0 is a functional 404, not an error.
	 *
	 * @return void
	 */
	public function testFetchReturnsZeroWhenNotFound()
	{
		global $db;

		$game = new Game($db);
		$this->assertSame(0, $game->fetch(999999999));
	}

	/**
	 * getPlayersByTeam() splits the players of each side.
	 *
	 * @return void
	 */
	public function testGetPlayersByTeam()
	{
		global $user, $db;

		$game = new Game($db);
		$game->date_game = dol_now() - 3600;
		$game->mode = '2v2';
		$game->score_team1 = 10;
		$game->score_team2 = 8;
		$game->setPlayers(array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 1),
			array('fk_user' => 3, 'team' => 2),
			array('fk_user' => 4, 'team' => 2),
		));
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$game->fetchLines();

		$this->assertCount(2, $game->getPlayersByTeam(1));
		$this->assertCount(2, $game->getPlayersByTeam(2));
		$this->assertCount(0, $game->getPlayersByTeam(3));
	}

	/**
	 * RG-32: the author may edit within the delay, a stranger never may.
	 *
	 * @return void
	 */
	public function testCanBeEditedByAuthorWithinDelay()
	{
		global $user, $db;

		$game = $this->makeGame();
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$this->assertTrue($game->canBeEditedBy($user));

		$stranger = new User($db);
		$stranger->id = 999999;
		$stranger->rights = new stdClass();
		$this->assertFalse($game->canBeEditedBy($stranger));
	}

	/**
	 * RG-32: past the delay, the author alone can no longer edit.
	 *
	 * @return void
	 */
	public function testCannotBeEditedByAuthorPastDelay()
	{
		global $user, $db;

		$game = $this->makeGame();
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);

		// A user owning modify_own only, having created the game 48 hours ago
		$author = new User($db);
		$author->id = (int) $user->id;
		$author->rights = new stdClass();
		$author->rights->babyfoot = new stdClass();
		$author->rights->babyfoot->modify_own = 1;

		$game->date_creation = dol_now() - (48 * 3600);
		$this->assertFalse($game->canBeEditedBy($author));

		$game->date_creation = dol_now() - 3600;
		$this->assertTrue($game->canBeEditedBy($author));
	}
}
