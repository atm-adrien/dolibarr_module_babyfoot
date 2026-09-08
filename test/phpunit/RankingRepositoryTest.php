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
 */

/**
 * \file    test/phpunit/RankingRepositoryTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the read only aggregates of the ranking repository.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/stats/rankingrepository.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class RankingRepositoryTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class RankingRepositoryTest extends CommonClassTest
{
	/**
	 * Remove every game and every ranking row of the entity.
	 *
	 * @return	void
	 */
	private function wipeHistory()
	{
		global $db, $conf;

		$sql = "DELETE gp FROM ".$db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $conf->entity);
		$db->query($sql);
		$db->query("DELETE FROM ".$db->prefix()."babyfoot_game WHERE entity = ".((int) $conf->entity));
		$db->query("DELETE FROM ".$db->prefix()."babyfoot_rating WHERE entity = ".((int) $conf->entity));
	}

	/**
	 * Create and store a 1v1 game between two users.
	 *
	 * @param	int		$userA			Player of team 1
	 * @param	int		$userB			Player of team 2
	 * @param	int		$score1			Goals of team 1
	 * @param	int		$score2			Goals of team 2
	 * @param	int		$ageSeconds		How long ago the game was played
	 * @return	void
	 */
	private function playGame($userA, $userB, $score1, $score2, $ageSeconds = 60)
	{
		global $db, $user;

		$game = new Game($db);
		$game->date_game = dol_now() - $ageSeconds;
		$game->mode = '1v1';
		$game->score_team1 = $score1;
		$game->score_team2 = $score2;
		$game->setPlayers(array(
			array('fk_user' => $userA, 'team' => 1),
			array('fk_user' => $userB, 'team' => 2),
		));
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
	}

	/**
	 * Build the repository under test.
	 *
	 * @return	RankingRepository	Repository bound to the current entity
	 */
	private function repository()
	{
		global $db, $conf;

		return new RankingRepository($db, (int) $conf->entity);
	}

	/**
	 * A player exists in the directory as soon as they have played once.
	 *
	 * @return	void
	 */
	public function testGetPlayerListReturnsEveryPlayerWhoPlayed()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);

		$rows = $this->repository()->getPlayerList();

		$ids = array();
		foreach ($rows as $row) {
			$ids[] = $row['fk_user'];
		}
		sort($ids);

		$this->assertSame(array(1, 2, 3), $ids);
	}

	/**
	 * The lock sentinel row (fk_user = 0) must never surface in a listing.
	 *
	 * @return	void
	 */
	public function testGetPlayerListExcludesTheLockSentinel()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);

		foreach ($this->repository()->getPlayerList() as $row) {
			$this->assertGreaterThan(0, $row['fk_user']);
		}
	}

	/**
	 * Default order is alphabetical, which is what a directory is for.
	 *
	 * @return	void
	 */
	public function testGetPlayerListSortsByNameByDefault()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);

		$rows = $this->repository()->getPlayerList();

		$named = array();
		$unnamedSeen = false;
		foreach ($rows as $row) {
			if ($row['lastname'] === '' && $row['firstname'] === '') {
				$unnamedSeen = true;
				continue;
			}
			$this->assertFalse($unnamedSeen, 'a labelless player must never precede a named one');
			$named[] = $row['lastname'];
		}

		$sorted = $named;
		usort($sorted, 'strcasecmp');

		$this->assertSame($sorted, $named);
	}

	/**
	 * Elo order is the ranking order, best first when descending.
	 *
	 * @return	void
	 */
	public function testGetPlayerListSortsByEloDescending()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);

		$rows = $this->repository()->getPlayerList('elo', 'DESC');

		$this->assertCount(2, $rows);
		$this->assertGreaterThanOrEqual($rows[1]['elo'], $rows[0]['elo']);
		$this->assertSame(1, $rows[0]['fk_user'], 'the winner must come first');
	}

	/**
	 * A sort key outside the whitelist must never reach the SQL.
	 *
	 * @return	void
	 */
	public function testGetPlayerListRejectsUnknownSortField()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);

		$byName = $this->repository()->getPlayerList('name', 'ASC');
		$injected = $this->repository()->getPlayerList("r.elo DESC -- ", 'ASC');

		$this->assertSame($byName, $injected, 'an unknown sort key must fall back to the name order');
	}

	/**
	 * Anything but DESC is an ascending sort: the direction is never interpolated.
	 *
	 * @return	void
	 */
	public function testGetPlayerListRejectsUnknownSortOrder()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);

		$ascending = $this->repository()->getPlayerList('elo', 'ASC');
		$garbage = $this->repository()->getPlayerList('elo', '; DROP TABLE');

		$this->assertSame($ascending, $garbage);
	}
}
