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
 * \file    test/phpunit/RatingEngineTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the rating engine, including the acceptance criteria.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/ratingengine.class.php';
require_once dirname(__FILE__).'/../../class/ratingset.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class RatingEngineTest
 *
 * Every test starts from an empty history: wipeHistory() removes the games of
 * the entity, so a test never depends on what the previous one left behind. The
 * whole class runs inside the transaction opened by CommonClassTest, which is
 * rolled back afterwards.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class RatingEngineTest extends CommonClassTest
{
	/**
	 * Remove every game and every ranking row of the entity.
	 *
	 * @return	void
	 */
	private function wipeHistory()
	{
		global $db, $conf;

		$db->query("DELETE gp FROM ".$db->prefix()."babyfoot_game_player as gp"
			." INNER JOIN ".$db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game"
			." WHERE g.entity = ".((int) $conf->entity));
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
	 * @return	Game					Stored game
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

		return $game;
	}

	/**
	 * Read one ranking row.
	 *
	 * @param	int			$userId		Rowid of the Dolibarr user
	 * @param	string		$mode		'1v1', '2v2' or 'all'
	 * @return	object|null				Row, or null when the player has none in that mode
	 */
	private function rating($userId, $mode)
	{
		global $db, $conf;

		$sql = "SELECT elo, nb_games, nb_wins, nb_losses, nb_draws, goals_for, goals_against,";
		$sql .= " nb_fanny_given, nb_fanny_taken, current_streak, best_streak, elo_peak";
		$sql .= " FROM ".$db->prefix()."babyfoot_rating";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND fk_user = ".((int) $userId);
		$sql .= " AND mode = '".$db->escape($mode)."'";

		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ? $obj : null;
	}

	/**
	 * Snapshot the whole ranking table, so two states can be compared.
	 *
	 * @return	array	Rows indexed by user and mode
	 */
	private function snapshotRatings()
	{
		global $db, $conf;

		$rows = array();

		$sql = "SELECT fk_user, mode, elo, nb_games, nb_wins, nb_losses, nb_draws,";
		$sql .= " goals_for, goals_against, nb_fanny_given, nb_fanny_taken,";
		$sql .= " current_streak, best_streak, elo_peak";
		$sql .= " FROM ".$db->prefix()."babyfoot_rating";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_user > 0";
		$sql .= " ORDER BY fk_user ASC, mode ASC";

		$resql = $db->query($sql);
		while ($obj = $db->fetch_object($resql)) {
			$rows[$obj->fk_user.'|'.$obj->mode] = (array) $obj;
		}
		$db->free($resql);

		return $rows;
	}

	/**
	 * Build an engine working on the current entity.
	 *
	 * @return	RatingEngine	Engine under test
	 */
	private function engine()
	{
		global $db, $conf;

		return new RatingEngine($db, (int) $conf->entity);
	}

	/**
	 * Acceptance criterion (section 10): two fresh players, K = 40, a win for
	 * team 1 gives exactly +20 / -20.
	 *
	 * @return void
	 */
	public function testFirstGameGivesPlusAndMinusTwenty()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		$winner = $this->rating(1, '1v1');
		$loser = $this->rating(2, '1v1');
		$this->assertNotNull($winner);
		$this->assertNotNull($loser);
		$this->assertSame(1020, (int) $winner->elo);
		$this->assertSame(980, (int) $loser->elo);
	}

	/**
	 * RG-10: a ranking row is created on the fly at the first game.
	 *
	 * @return void
	 */
	public function testRatingRowsAreCreatedOnFirstGame()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		$this->assertNotNull($this->rating(1, '1v1'));
		$this->assertNotNull($this->rating(1, 'all'));
	}

	/**
	 * Section 9: a player who only played 1v1 has no 2v2 ranking row at all, and
	 * must never be shown at the initial Elo in that mode.
	 *
	 * @return void
	 */
	public function testNoRatingRowForAnUnplayedMode()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		$this->assertNull($this->rating(1, '2v2'));
	}

	/**
	 * RG-17: a 1v1 game feeds both the 1v1 and the overall rankings.
	 *
	 * @return void
	 */
	public function testGameFeedsModeAndOverallRankings()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		$this->assertSame(1020, (int) $this->rating(1, '1v1')->elo);
		$this->assertSame(1020, (int) $this->rating(1, 'all')->elo);
	}

	/**
	 * RG-18 and decision D9: the participant row stores the mode snapshots in
	 * elo_* and the overall ones in elo_all_*.
	 *
	 * @return void
	 */
	public function testPlayerRowStoresBothEloSnapshots()
	{
		$this->wipeHistory();
		$game = $this->playGame(1, 2, 10, 3);
		$game->fetchLines();

		$checked = false;
		foreach ($game->lines as $line) {
			if ((int) $line->fk_user === 1) {
				$this->assertSame(1000, (int) $line->elo_before);
				$this->assertSame(1020, (int) $line->elo_after);
				$this->assertSame(20, (int) $line->elo_delta);
				$this->assertSame(1000, (int) $line->elo_all_before);
				$this->assertSame(1020, (int) $line->elo_all_after);
				$this->assertSame(20, (int) $line->elo_all_delta);
				$checked = true;
			}
		}
		$this->assertTrue($checked, 'player 1 not found among the game lines');
	}

	/**
	 * Counters are fed: games, wins, losses and goals.
	 *
	 * @return void
	 */
	public function testCountersAreUpdated()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		$winner = $this->rating(1, '1v1');
		$this->assertSame(1, (int) $winner->nb_games);
		$this->assertSame(1, (int) $winner->nb_wins);
		$this->assertSame(0, (int) $winner->nb_losses);
		$this->assertSame(10, (int) $winner->goals_for);
		$this->assertSame(3, (int) $winner->goals_against);
	}

	/**
	 * A 10-0 win counts as a fanny given for the winner and taken for the loser.
	 *
	 * @return void
	 */
	public function testFannyCounters()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 0);

		$this->assertSame(1, (int) $this->rating(1, '1v1')->nb_fanny_given);
		$this->assertSame(0, (int) $this->rating(1, '1v1')->nb_fanny_taken);
		$this->assertSame(1, (int) $this->rating(2, '1v1')->nb_fanny_taken);
		$this->assertSame(0, (int) $this->rating(2, '1v1')->nb_fanny_given);
	}

	/**
	 * Streaks: positive on wins, negative after a loss, best streak kept.
	 *
	 * @return void
	 */
	public function testStreaksAreTracked()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 300);
		$this->playGame(1, 2, 10, 4, 240);

		$this->assertSame(2, (int) $this->rating(1, 'all')->current_streak);
		$this->assertSame(2, (int) $this->rating(1, 'all')->best_streak);

		$this->playGame(1, 2, 2, 10, 180);

		$this->assertSame(-1, (int) $this->rating(1, 'all')->current_streak);
		$this->assertSame(2, (int) $this->rating(1, 'all')->best_streak);
		$this->assertSame(1, (int) $this->rating(2, 'all')->current_streak);
	}

	/**
	 * elo_peak keeps the highest Elo ever reached and never decreases.
	 *
	 * @return void
	 */
	public function testEloPeakNeverDecreases()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 300);
		$peak = (int) $this->rating(1, '1v1')->elo_peak;

		$this->playGame(1, 2, 1, 10, 240);

		$this->assertSame($peak, (int) $this->rating(1, '1v1')->elo_peak);
		$this->assertLessThan($peak, (int) $this->rating(1, '1v1')->elo);
	}

	/**
	 * Decision D10: in 2v2, a novice and a confirmed teammate get different
	 * deltas, of the same sign.
	 *
	 * @return void
	 */
	public function testTeammatesWithDifferentKGetDifferentDeltas()
	{
		global $db, $user, $conf;

		$this->wipeHistory();

		// User 3 already played 20 games in 2v2, so they left the novice status
		$sql = "INSERT INTO ".$db->prefix()."babyfoot_rating (entity, fk_user, mode, elo, nb_games, elo_peak)";
		$sql .= " VALUES (".((int) $conf->entity).", 3, '2v2', 1000, 20, 1000)";
		$this->assertNotFalse($db->query($sql));

		$game = new Game($db);
		$game->date_game = dol_now() - 60;
		$game->mode = '2v2';
		$game->score_team1 = 10;
		$game->score_team2 = 5;
		$game->setPlayers(array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 3, 'team' => 1),
			array('fk_user' => 2, 'team' => 2),
			array('fk_user' => 4, 'team' => 2),
		));
		$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		$game->fetchLines();

		$deltas = array();
		foreach ($game->lines as $line) {
			$deltas[(int) $line->fk_user] = (int) $line->elo_delta;
		}

		$this->assertSame(20, $deltas[1], 'novice should move by round(40 * 0.5)');
		$this->assertSame(12, $deltas[3], 'confirmed should move by round(24 * 0.5)');
		$this->assertGreaterThan($deltas[3], $deltas[1]);
	}

	/**
	 * RG-20: a cancelled game is neutral, it must not appear in any counter.
	 *
	 * @return void
	 */
	public function testCancelledGameIsNeutral()
	{
		global $user;

		$this->wipeHistory();
		$game = $this->playGame(1, 2, 10, 3);
		$this->assertGreaterThan(0, $game->cancel($user), 'cancel() failed: '.$game->error);

		$this->assertNull($this->rating(1, '1v1'), 'a player with only cancelled games has no ranking row');
	}

	/**
	 * Acceptance criterion (section 10): running the full recompute twice in a
	 * row gives exactly the same result.
	 *
	 * @return void
	 */
	public function testRecomputeAllIsIdempotent()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);
		$this->playGame(1, 3, 4, 10, 400);

		$engine = $this->engine();
		$engine->recomputeAll();
		$first = $this->snapshotRatings();
		$engine->recomputeAll();
		$second = $this->snapshotRatings();

		$this->assertNotEmpty($first);
		$this->assertEquals($first, $second);
	}

	/**
	 * The incremental path and the full recompute must agree.
	 *
	 * @return void
	 */
	public function testIncrementalMatchesFullRecompute()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);
		$this->playGame(1, 3, 4, 10, 400);

		$incremental = $this->snapshotRatings();
		$this->engine()->recomputeAll();

		$this->assertEquals($incremental, $this->snapshotRatings());
	}

	/**
	 * Acceptance criterion (section 10), the one that matters most: deleting an
	 * old game then recomputing gives exactly the ranking that game never existed.
	 *
	 * @return void
	 */
	public function testDeletingAnOldGameRestoresTheRankingWithoutIt()
	{
		global $user;

		// Reference state: two games, without the one we will delete
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 400);
		$this->engine()->recomputeAll();
		$reference = $this->snapshotRatings();
		$this->assertNotEmpty($reference);

		// Same history, plus an extra game inserted in the middle
		$extra = $this->playGame(1, 3, 10, 2, 500);
		$this->engine()->recomputeAll();
		$this->assertNotEquals($reference, $this->snapshotRatings());

		// Remove it and recompute: we must land exactly back on the reference
		$this->assertGreaterThan(0, $extra->delete($user), 'delete() failed: '.$extra->error);
		$this->engine()->recomputeAll();

		$this->assertEquals($reference, $this->snapshotRatings());
	}

	/**
	 * RG-30: a backdated game triggers a rebuild, so the chain stays correct.
	 *
	 * @return void
	 */
	public function testBackdatedGameTriggersRecompute()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 300);
		$afterFirst = (int) $this->rating(1, '1v1')->elo;

		// A game played BEFORE the first one, entered afterwards
		$this->playGame(1, 2, 10, 5, 900);

		$this->assertSame(2, (int) $this->rating(1, '1v1')->nb_games);
		$this->assertNotSame($afterFirst, (int) $this->rating(1, '1v1')->elo);

		// The stored ranking must equal a full recompute
		$incremental = $this->snapshotRatings();
		$this->engine()->recomputeAll();
		$this->assertEquals($incremental, $this->snapshotRatings());
	}

	/**
	 * Section 8.3: the replay order is deterministic, two games sharing the same
	 * second are always processed in the same sequence.
	 *
	 * @return void
	 */
	public function testReplayOrderIsDeterministicOnEqualDates()
	{
		global $db, $user;

		$this->wipeHistory();
		$sameDate = dol_now() - 3600;

		for ($i = 0; $i < 3; $i++) {
			$game = new Game($db);
			$game->date_game = $sameDate;
			$game->mode = '1v1';
			$game->score_team1 = 10;
			$game->score_team2 = $i;
			$game->setPlayers(array(
				array('fk_user' => 1, 'team' => 1),
				array('fk_user' => 2, 'team' => 2),
			));
			$this->assertGreaterThan(0, $game->create($user), 'create() failed: '.$game->error);
		}

		$engine = $this->engine();
		$engine->recomputeAll();
		$first = $this->snapshotRatings();
		$engine->recomputeAll();

		$this->assertEquals($first, $this->snapshotRatings());
	}

	/**
	 * checkConsistency() reports nothing on a freshly recomputed table.
	 *
	 * @return void
	 */
	public function testCheckConsistencyIsSilentAfterRecompute()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);
		$this->playGame(2, 3, 10, 8, 500);

		$engine = $this->engine();
		$engine->recomputeAll();

		$this->assertSame(array(), $engine->checkConsistency());
	}

	/**
	 * checkConsistency() reports a drift without fixing it (section 6.8).
	 *
	 * @return void
	 */
	public function testCheckConsistencyReportsDriftWithoutFixingIt()
	{
		global $db, $conf;

		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3, 600);

		$engine = $this->engine();
		$engine->recomputeAll();

		$sql = "UPDATE ".$db->prefix()."babyfoot_rating SET elo = 1500";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_user = 1 AND mode = '1v1'";
		$this->assertNotFalse($db->query($sql));

		$drifts = $engine->checkConsistency();
		$this->assertNotEmpty($drifts);
		$this->assertSame(1500, (int) $this->rating(1, '1v1')->elo, 'checkConsistency must not fix anything');
	}

	/**
	 * Updating the score of a game updates the ranking accordingly.
	 *
	 * @return void
	 */
	public function testUpdatingAScoreUpdatesTheRanking()
	{
		global $user;

		$this->wipeHistory();
		$game = $this->playGame(1, 2, 10, 3, 600);
		$this->assertSame(1020, (int) $this->rating(1, '1v1')->elo);

		$game->score_team1 = 3;
		$game->score_team2 = 10;
		$this->assertGreaterThan(0, $game->update($user), 'update() failed: '.$game->error);

		$this->assertSame(980, (int) $this->rating(1, '1v1')->elo);
		$this->assertSame(1020, (int) $this->rating(2, '1v1')->elo);
	}

	/**
	 * The sentinel lock row is never exposed as a player.
	 *
	 * @return void
	 */
	public function testLockRowIsNotAPlayer()
	{
		$this->wipeHistory();
		$this->playGame(1, 2, 10, 3);

		foreach (array_keys($this->snapshotRatings()) as $key) {
			$this->assertStringStartsNotWith('0|', $key);
			$this->assertStringNotContainsString(RatingEngine::LOCK_MODE, $key);
		}
	}
}
