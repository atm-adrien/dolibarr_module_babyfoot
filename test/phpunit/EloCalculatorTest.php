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
 * \file    test/phpunit/EloCalculatorTest.php
 * \ingroup babyfoot
 * \brief   PHPUnit test for the EloCalculator class.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../../class/elocalculator.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Class EloCalculatorTest
 *
 * Covers rules RG-11 to RG-16 and the acceptance criterion of section 10.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class EloCalculatorTest extends CommonClassTest
{
	/**
	 * Build a calculator on the single K factor of the module.
	 *
	 * @return	EloCalculator	Calculator under test
	 */
	private function calculator()
	{
		return new EloCalculator(BabyfootConfig::ELO_K);
	}

	/**
	 * RG-11: equal ratings give an expected score of 0.5.
	 *
	 * @return void
	 */
	public function testExpectedScoreIsHalfForEqualRatings()
	{
		$this->assertEqualsWithDelta(0.5, $this->calculator()->expectedScore(1000, 1000), 0.0001);
	}

	/**
	 * RG-11: a 400 points gap gives roughly 0.909 to the stronger side.
	 *
	 * @return void
	 */
	public function testExpectedScoreForFourHundredPointsGap()
	{
		$calc = $this->calculator();
		$this->assertEqualsWithDelta(0.909091, $calc->expectedScore(1400, 1000), 0.0001);
		$this->assertEqualsWithDelta(0.090909, $calc->expectedScore(1000, 1400), 0.0001);
	}

	/**
	 * RG-11: the two expected scores of a game always sum to 1.
	 *
	 * @return void
	 */
	public function testExpectedScoresSumToOne()
	{
		$calc = $this->calculator();
		$sum = $calc->expectedScore(1230, 980) + $calc->expectedScore(980, 1230);
		$this->assertEqualsWithDelta(1.0, $sum, 0.0001);
	}

	/**
	 * RG-12: a team rating is the arithmetic mean of its players.
	 *
	 * @return void
	 */
	public function testTeamRatingIsArithmeticMean()
	{
		$calc = $this->calculator();
		$this->assertEqualsWithDelta(1000.0, $calc->teamRating(array(1000)), 0.0001);
		$this->assertEqualsWithDelta(1100.0, $calc->teamRating(array(1000, 1200)), 0.0001);
	}

	/**
	 * An empty team is a programming error, not a silent zero.
	 *
	 * @return void
	 */
	public function testTeamRatingRejectsEmptyTeam()
	{
		$this->expectException(Exception::class);
		$this->calculator()->teamRating(array());
	}

	/**
	 * Acceptance criterion (spec section 10): two players at 1000, K = 40,
	 * a win for team 1 gives exactly +20 / -20.
	 *
	 * @return void
	 */
	public function testAcceptanceCriterionTwentyPoints()
	{
		$calc = $this->calculator();
		$this->assertSame(20, $calc->delta(1000, 1000, EloCalculator::RESULT_WIN));
		$this->assertSame(-20, $calc->delta(1000, 1000, EloCalculator::RESULT_LOSS));
	}

	/**
	 * RG-13: a draw between equal players moves nobody.
	 *
	 * @return void
	 */
	public function testDrawBetweenEqualPlayersGivesZero()
	{
		$this->assertSame(0, $this->calculator()->delta(1000, 1000, EloCalculator::RESULT_DRAW));
	}

	/**
	 * RG-13: beating a much stronger side pays more than beating an equal one.
	 *
	 * @return void
	 */
	public function testUpsetPaysMoreThanExpectedWin()
	{
		$calc = $this->calculator();
		$upset = $calc->delta(1000, 1400, EloCalculator::RESULT_WIN);
		$expected = $calc->delta(1000, 1000, EloCalculator::RESULT_WIN);
		$this->assertGreaterThan($expected, $upset);
		$this->assertSame(36, $upset);
	}

	/**
	 * With one K for everybody, the two players of a 2v2 side always move by the
	 * same amount: the delta depends on the team ratings and on nothing else. This
	 * is what replaced decision D10.
	 *
	 * @return void
	 */
	public function testBothTeammatesGetTheSameDelta()
	{
		$calc = $this->calculator();
		$teamElo = $calc->teamRating(array(1200, 800));

		$first = $calc->delta($teamElo, 1000, EloCalculator::RESULT_WIN);
		$second = $calc->delta($teamElo, 1000, EloCalculator::RESULT_WIN);

		$this->assertSame($first, $second);
		$this->assertSame(20, $first, 'a 1200 and an 800 average out to 1000');
	}

	/**
	 * The formula stays zero sum between two equally rated sides.
	 *
	 * @return void
	 */
	public function testDeltasAreZeroSumBetweenEqualTeams()
	{
		$calc = $this->calculator();
		$winner = $calc->delta(1150, 1150, EloCalculator::RESULT_WIN);
		$loser = $calc->delta(1150, 1150, EloCalculator::RESULT_LOSS);

		$this->assertSame(0, $winner + $loser);
	}

	/**
	 * RG-13: deltas are rounded to the nearest integer.
	 *
	 * @return void
	 */
	public function testDeltaIsRoundedToNearestInteger()
	{
		$this->assertSame(23, $this->calculator()->delta(1000, 1050, EloCalculator::RESULT_WIN));
	}

	/**
	 * A win never produces a negative delta, whatever the rating gap.
	 *
	 * @return void
	 */
	public function testWinNeverGivesNegativeDelta()
	{
		$calc = $this->calculator();
		foreach (array(500, 1000, 1500, 2500) as $opponent) {
			$this->assertGreaterThanOrEqual(0, $calc->delta(1000, $opponent, EloCalculator::RESULT_WIN));
		}
	}
}
