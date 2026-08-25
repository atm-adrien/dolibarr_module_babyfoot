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
	 * Build a calculator using the default settings of the spec (section 7).
	 *
	 * @param	bool			$margin		Enable the goal difference weighting (RG-16)
	 * @return	EloCalculator				Calculator under test
	 */
	private function calculator($margin = false)
	{
		return new EloCalculator(24, 40, 15, $margin);
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
	 * RG-15: K is the novice one strictly below the threshold.
	 *
	 * @return void
	 */
	public function testCoefficientSwitchesAtNoviceThreshold()
	{
		$calc = $this->calculator();
		$this->assertSame(40, $calc->coefficient(0));
		$this->assertSame(40, $calc->coefficient(14));
		$this->assertSame(24, $calc->coefficient(15));
		$this->assertSame(24, $calc->coefficient(200));
	}

	/**
	 * RG-16 and decision D14: the margin factor is floored at 1.0 and grows
	 * with the goal gap.
	 *
	 * @return void
	 */
	public function testMarginFactorIsFlooredAtOne()
	{
		$calc = $this->calculator(true);
		$this->assertEqualsWithDelta(1.0, $calc->marginFactor(5, 5), 0.0001);
		$this->assertEqualsWithDelta(1.0, $calc->marginFactor(10, 9), 0.0001);
		$this->assertEqualsWithDelta(1.1, $calc->marginFactor(10, 8), 0.0001);
		$this->assertEqualsWithDelta(1.9, $calc->marginFactor(10, 0), 0.0001);
	}

	/**
	 * RG-16: the margin factor is neutral when the option is off.
	 *
	 * @return void
	 */
	public function testMarginFactorIsNeutralWhenDisabled()
	{
		$this->assertEqualsWithDelta(1.0, $this->calculator(false)->marginFactor(10, 0), 0.0001);
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
		$winner = $calc->delta(1000, 1000, EloCalculator::RESULT_WIN, 0, 10, 5);
		$loser = $calc->delta(1000, 1000, EloCalculator::RESULT_LOSS, 0, 5, 10);
		$this->assertSame(20, $winner);
		$this->assertSame(-20, $loser);
	}

	/**
	 * RG-13: a draw between equal players moves nobody.
	 *
	 * @return void
	 */
	public function testDrawBetweenEqualPlayersGivesZero()
	{
		$calc = $this->calculator();
		$this->assertSame(0, $calc->delta(1000, 1000, EloCalculator::RESULT_DRAW, 0, 5, 5));
	}

	/**
	 * RG-13: beating a much stronger side pays more than beating an equal one.
	 *
	 * @return void
	 */
	public function testUpsetPaysMoreThanExpectedWin()
	{
		$calc = $this->calculator();
		$upset = $calc->delta(1000, 1400, EloCalculator::RESULT_WIN, 0, 10, 8);
		$expected = $calc->delta(1000, 1000, EloCalculator::RESULT_WIN, 0, 10, 8);
		$this->assertGreaterThan($expected, $upset);
		$this->assertSame(36, $upset);
	}

	/**
	 * Decision D10 and RG-15: within one team, a novice moves more than a
	 * confirmed player, because K stays individual while the expected score is
	 * shared. Both deltas keep the same sign.
	 *
	 * @return void
	 */
	public function testNoviceAndConfirmedTeammatesGetDifferentDeltas()
	{
		$calc = $this->calculator();
		$novice = $calc->delta(1100, 1000, EloCalculator::RESULT_WIN, 3, 10, 7);
		$confirmed = $calc->delta(1100, 1000, EloCalculator::RESULT_WIN, 40, 10, 7);
		$this->assertGreaterThan($confirmed, $novice);
		$this->assertGreaterThan(0, $confirmed);
	}

	/**
	 * RG-13: deltas are rounded to the nearest integer.
	 *
	 * @return void
	 */
	public function testDeltaIsRoundedToNearestInteger()
	{
		$calc = $this->calculator();
		$delta = $calc->delta(1000, 1050, EloCalculator::RESULT_WIN, 0, 10, 6);
		$this->assertSame(23, $delta);
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
			$this->assertGreaterThanOrEqual(0, $calc->delta(1000, $opponent, EloCalculator::RESULT_WIN, 0, 10, 3));
		}
	}
}
