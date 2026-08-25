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
 * \file    test/phpunit/GameValidatorTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the GameValidator class (rules RG-01 to RG-08).
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../../class/gamevalidator.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class GameValidatorTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class GameValidatorTest extends CommonClassTest
{
	/**
	 * Build a validator using the default settings of the spec (section 7).
	 *
	 * @param	array			$overrides	Settings to override
	 * @return	GameValidator				Validator under test
	 */
	private function validator($overrides = array())
	{
		global $db, $conf;

		$config = array_merge(array(
			'score_max' => 10,
			'score_exact' => true,
			'allow_draw' => false,
		), $overrides);

		return new GameValidator($db, (int) $conf->entity, $config);
	}

	/**
	 * Two players, one per team.
	 *
	 * @return	array	Player list
	 */
	private function players1v1()
	{
		return array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 2),
		);
	}

	/**
	 * Four players, two per team.
	 *
	 * @return	array	Player list
	 */
	private function players2v2()
	{
		return array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 1),
			array('fk_user' => 3, 'team' => 2),
			array('fk_user' => 4, 'team' => 2),
		);
	}

	/**
	 * A nominal 1v1 game passes every rule.
	 *
	 * @return void
	 */
	public function testValidGame1v1()
	{
		$errors = $this->validator()->validate('1v1', 10, 7, dol_now() - 60, $this->players1v1());
		$this->assertSame(array(), $errors);
	}

	/**
	 * A nominal 2v2 game passes every rule.
	 *
	 * @return void
	 */
	public function testValidGame2v2()
	{
		$errors = $this->validator()->validate('2v2', 3, 10, dol_now() - 60, $this->players2v2());
		$this->assertSame(array(), $errors);
	}

	/**
	 * RG-01: a 1v1 game with three players is rejected.
	 *
	 * @return void
	 */
	public function testRejectsWrongPlayerCountIn1v1()
	{
		$players = $this->players1v1();
		$players[] = array('fk_user' => 3, 'team' => 2);
		$errors = $this->validator()->validate('1v1', 10, 7, dol_now() - 60, $players);
		$this->assertContains('BabyfootErrPlayerCount', $errors);
	}

	/**
	 * RG-01: a 2v2 game with unbalanced teams is rejected.
	 *
	 * @return void
	 */
	public function testRejectsUnbalancedTeamsIn2v2()
	{
		$players = array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 1),
			array('fk_user' => 3, 'team' => 1),
			array('fk_user' => 4, 'team' => 2),
		);
		$errors = $this->validator()->validate('2v2', 10, 7, dol_now() - 60, $players);
		$this->assertContains('BabyfootErrPlayerCount', $errors);
	}

	/**
	 * RG-01: an empty player list is rejected.
	 *
	 * @return void
	 */
	public function testRejectsEmptyPlayerList()
	{
		$errors = $this->validator()->validate('1v1', 10, 7, dol_now() - 60, array());
		$this->assertContains('BabyfootErrPlayerCount', $errors);
	}

	/**
	 * RG-02: the same user cannot play in both teams.
	 *
	 * @return void
	 */
	public function testRejectsDuplicatePlayer()
	{
		$players = array(
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 2, 'team' => 1),
			array('fk_user' => 1, 'team' => 2),
			array('fk_user' => 3, 'team' => 2),
		);
		$errors = $this->validator()->validate('2v2', 10, 7, dol_now() - 60, $players);
		$this->assertContains('BabyfootErrDuplicatePlayer', $errors);
	}

	/**
	 * RG-03: a negative score is rejected.
	 *
	 * @return void
	 */
	public function testRejectsNegativeScore()
	{
		$errors = $this->validator()->validate('1v1', 10, -1, dol_now() - 60, $this->players1v1());
		$this->assertContains('BabyfootErrScoreRange', $errors);
	}

	/**
	 * RG-03: a score above the maximum is rejected.
	 *
	 * @return void
	 */
	public function testRejectsScoreAboveMax()
	{
		$errors = $this->validator()->validate('1v1', 11, 3, dol_now() - 60, $this->players1v1());
		$this->assertContains('BabyfootErrScoreRange', $errors);
	}

	/**
	 * RG-04: with BABYFOOT_SCORE_EXACT on, the winner must reach the max score.
	 *
	 * @return void
	 */
	public function testRejectsShortGameWhenExactScoreRequired()
	{
		$errors = $this->validator()->validate('1v1', 8, 5, dol_now() - 60, $this->players1v1());
		$this->assertContains('BabyfootErrScoreExact', $errors);
	}

	/**
	 * RG-04: with BABYFOOT_SCORE_EXACT off, a short game is accepted.
	 *
	 * @return void
	 */
	public function testAcceptsShortGameWhenExactScoreNotRequired()
	{
		$validator = $this->validator(array('score_exact' => false));
		$errors = $validator->validate('1v1', 8, 5, dol_now() - 60, $this->players1v1());
		$this->assertSame(array(), $errors);
	}

	/**
	 * RG-05: draws are rejected by default.
	 *
	 * @return void
	 */
	public function testRejectsDrawByDefault()
	{
		$validator = $this->validator(array('score_exact' => false));
		$errors = $validator->validate('1v1', 7, 7, dol_now() - 60, $this->players1v1());
		$this->assertContains('BabyfootErrDrawNotAllowed', $errors);
	}

	/**
	 * RG-05: draws are accepted when BABYFOOT_ALLOW_DRAW is on.
	 *
	 * @return void
	 */
	public function testAcceptsDrawWhenAllowed()
	{
		$validator = $this->validator(array('score_exact' => false, 'allow_draw' => true));
		$errors = $validator->validate('1v1', 7, 7, dol_now() - 60, $this->players1v1());
		$this->assertSame(array(), $errors);
	}

	/**
	 * RG-06: a game cannot be played in the future.
	 *
	 * @return void
	 */
	public function testRejectsFutureDate()
	{
		$errors = $this->validator()->validate('1v1', 10, 7, dol_now() + 3600, $this->players1v1());
		$this->assertContains('BabyfootErrFutureDate', $errors);
	}

	/**
	 * An unknown mode is rejected rather than silently treated as 1v1.
	 *
	 * @return void
	 */
	public function testRejectsUnknownMode()
	{
		$errors = $this->validator()->validate('3v3', 10, 7, dol_now() - 60, $this->players1v1());
		$this->assertContains('BabyfootErrUnknownMode', $errors);
	}

	/**
	 * The winner is deduced from the score, never entered (spec section 6.1).
	 *
	 * @return void
	 */
	public function testDeduceWinner()
	{
		$this->assertSame(1, GameValidator::deduceWinner(10, 4));
		$this->assertSame(2, GameValidator::deduceWinner(4, 10));
		$this->assertNull(GameValidator::deduceWinner(7, 7));
	}

	/**
	 * RG-08: no duplicate is reported when the table holds no matching game.
	 *
	 * Uses dedicated player ids so the assertion does not depend on the games
	 * inserted by the other tests of this class: the transaction is only rolled
	 * back after the last one.
	 *
	 * @return void
	 */
	public function testFindRecentDuplicateReturnsZeroWhenNoRecentGame()
	{
		$players = array(
			array('fk_user' => 900091, 'team' => 1),
			array('fk_user' => 900092, 'team' => 2),
		);
		$this->assertSame(0, $this->validator()->findRecentDuplicate('1v1', 10, 7, $players));
	}

	/**
	 * RG-08: an empty player list can never be a duplicate.
	 *
	 * @return void
	 */
	public function testFindRecentDuplicateReturnsZeroOnEmptyPlayerList()
	{
		$this->assertSame(0, $this->validator()->findRecentDuplicate('1v1', 10, 7, array()));
	}

	/**
	 * RG-08: a game entered moments ago with the same teams and score is found.
	 *
	 * @return void
	 */
	public function testFindRecentDuplicateFindsAnIdenticalGame()
	{
		global $db, $conf;

		$now = dol_print_date(dol_now(), 'standard');
		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game";
		$sql .= " (entity, ref, date_game, mode, score_team1, score_team2, winner_team, status, fk_user_creat, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", 'BFTEST-0001', '".$db->escape($now)."', '1v1', 10, 7, 1, 1, 1, '".$db->escape($now)."')";
		$this->assertNotFalse($db->query($sql));
		$gameId = (int) $db->last_insert_id($db->prefix().'babyfoot_game');

		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game_player (fk_game, fk_user, team, is_winner)";
		$sql .= " VALUES (".$gameId.", 1, 1, 1), (".$gameId.", 2, 2, 0)";
		$this->assertNotFalse($db->query($sql));

		$found = $this->validator()->findRecentDuplicate('1v1', 10, 7, $this->players1v1());
		$this->assertSame($gameId, $found);
	}

	/**
	 * RG-08: the same score with different players is not a duplicate.
	 *
	 * @return void
	 */
	public function testFindRecentDuplicateIgnoresDifferentPlayers()
	{
		global $db, $conf;

		$now = dol_print_date(dol_now(), 'standard');
		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game";
		$sql .= " (entity, ref, date_game, mode, score_team1, score_team2, winner_team, status, fk_user_creat, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", 'BFTEST-0002', '".$db->escape($now)."', '1v1', 10, 7, 1, 1, 1, '".$db->escape($now)."')";
		$this->assertNotFalse($db->query($sql));
		$gameId = (int) $db->last_insert_id($db->prefix().'babyfoot_game');

		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game_player (fk_game, fk_user, team, is_winner)";
		$sql .= " VALUES (".$gameId.", 900093, 1, 1), (".$gameId.", 900094, 2, 0)";
		$this->assertNotFalse($db->query($sql));

		// Same mode and same score, but a composition nothing in this class uses
		$other = array(
			array('fk_user' => 900095, 'team' => 1),
			array('fk_user' => 900096, 'team' => 2),
		);
		$this->assertSame(0, $this->validator()->findRecentDuplicate('1v1', 10, 7, $other));
	}

	/**
	 * RG-08: the team composition is compared regardless of the input order.
	 *
	 * @return void
	 */
	public function testFindRecentDuplicateIsOrderIndependent()
	{
		global $db, $conf;

		$now = dol_print_date(dol_now(), 'standard');
		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game";
		$sql .= " (entity, ref, date_game, mode, score_team1, score_team2, winner_team, status, fk_user_creat, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", 'BFTEST-0003', '".$db->escape($now)."', '2v2', 10, 4, 1, 1, 1, '".$db->escape($now)."')";
		$this->assertNotFalse($db->query($sql));
		$gameId = (int) $db->last_insert_id($db->prefix().'babyfoot_game');

		$sql = "INSERT INTO ".$db->prefix()."babyfoot_game_player (fk_game, fk_user, team, is_winner)";
		$sql .= " VALUES (".$gameId.", 1, 1, 1), (".$gameId.", 2, 1, 1), (".$gameId.", 3, 2, 0), (".$gameId.", 4, 2, 0)";
		$this->assertNotFalse($db->query($sql));

		// Same composition, players given in a different order
		$shuffled = array(
			array('fk_user' => 2, 'team' => 1),
			array('fk_user' => 4, 'team' => 2),
			array('fk_user' => 1, 'team' => 1),
			array('fk_user' => 3, 'team' => 2),
		);
		$this->assertSame($gameId, $this->validator()->findRecentDuplicate('2v2', 10, 4, $shuffled));
	}
}
