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
 * \file    test/phpunit/GlobalStatsRepositoryTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the period filter of the collective statistics.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/stats/globalstatsrepository.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class GlobalStatsRepositoryTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class GlobalStatsRepositoryTest extends CommonClassTest
{
	/**
	 * Leave the entity with two games: a fanny today, an ordinary game 100 days ago.
	 *
	 * @return	GlobalStatsRepository	Repository on the current entity
	 */
	private function seed(): GlobalStatsRepository
	{
		global $db, $conf, $user;

		$sql = "DELETE gp FROM ".$db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $conf->entity);
		$db->query($sql);
		$db->query("DELETE FROM ".$db->prefix()."babyfoot_game WHERE entity = ".((int) $conf->entity));
		$db->query("DELETE FROM ".$db->prefix()."babyfoot_rating WHERE entity = ".((int) $conf->entity));

		foreach ([[5, 10, 100 * 86400], [10, 0, 60]] as $spec) {
			$game = new Game($db);
			$game->date_game = dol_now() - $spec[2];
			$game->mode = '1v1';
			$game->score_team1 = $spec[0];
			$game->score_team2 = $spec[1];
			$game->setPlayers([['fk_user' => 1, 'team' => 1], ['fk_user' => 2, 'team' => 2]]);
			$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		}

		return new GlobalStatsRepository($db, (int) $conf->entity);
	}

	/**
	 * Every aggregate only counts the games of the period.
	 *
	 * @return void
	 */
	public function testAggregatesFollowThePeriod()
	{
		$repository = $this->seed();
		$last30 = dol_now() - 30 * 86400;

		$this->assertSame(2, $repository->getTotals()['nb_games']);
		$this->assertSame(1, $repository->getTotals($last30)['nb_games']);
		$this->assertSame(1, array_sum($repository->getDistributionByWeekday($last30)));
		$this->assertSame(1, array_sum($repository->getDistributionByHour($last30)));
		$this->assertSame(1, $repository->getMostActivePlayer($last30)['nb']);
		$this->assertCount(1, $repository->getTightestGames(5, $last30));
	}

	/**
	 * Aggregated over the games, the fanny table matches the cached one.
	 *
	 * @return void
	 */
	public function testFannyTableForPeriodMatchesTheCache()
	{
		$repository = $this->seed();

		$expected = [
			['fk_user' => 1, 'given' => 1, 'taken' => 0],
			['fk_user' => 2, 'given' => 0, 'taken' => 1],
		];
		$this->assertSame($expected, $repository->getFannyTable());
		$this->assertSame($expected, $repository->getFannyTable(dol_now() - 30 * 86400));
	}
}
