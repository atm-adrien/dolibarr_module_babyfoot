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
 * \file    class/elocalculator.class.php
 * \ingroup babyfoot
 * \brief   Pure Elo rating computation. This class MUST NOT access the database.
 */

/**
 * Computes Elo ratings, implementing rules RG-11 to RG-16 of the specification.
 *
 * Deliberately free of any database access and of any configuration lookup: the
 * settings are injected through the constructor. That is what makes this class
 * deterministic and unit-testable without any fixture. Never introduce a query
 * nor a getDolGlobal* call here.
 */
class EloCalculator
{
	/** @var float Score of a won game */
	const RESULT_WIN = 1.0;

	/**
	 * @var float Score of a drawn game. A draw can no longer be recorded, but the
	 *            formula stays defined for it and the stored counters keep their column.
	 */
	const RESULT_DRAW = 0.5;

	/** @var float Score of a lost game */
	const RESULT_LOSS = 0.0;

	/** @var int Rating gap giving a ten to one win expectancy */
	const RATING_SCALE = 400;

	/** @var int K factor applied to every player alike */
	private $k;

	/**
	 * Constructor
	 *
	 * @param	int		$k	K factor of the formula (BabyfootConfig::ELO_K)
	 */
	public function __construct(int $k)
	{
		$this->k = $k;
	}

	/**
	 * Expected score of side A against side B (RG-11).
	 *
	 * @param	float	$ratingA	Rating of side A
	 * @param	float	$ratingB	Rating of side B
	 * @return	float				Expected score, within [0,1]
	 */
	public function expectedScore(float $ratingA, float $ratingB): float
	{
		return 1 / (1 + pow(10, ($ratingB - $ratingA) / self::RATING_SCALE));
	}

	/**
	 * Rating of a team: the arithmetic mean of its players (RG-12).
	 *
	 * In 1v1 the team holds a single player, so the same code covers both modes.
	 *
	 * @param	int[]	$elos	Elo ratings of the team players
	 * @return	float			Team rating
	 * @throws	Exception		If the team is empty
	 */
	public function teamRating(array $elos): float
	{
		if (empty($elos)) {
			throw new Exception('EloCalculator::teamRating called with an empty team');
		}

		return array_sum($elos) / count($elos);
	}

	/**
	 * Elo delta of one player for one game (RG-13, RG-14).
	 *
	 * The expected score comes from the team ratings, so both players of a 2v2
	 * side get the same delta. The goal difference never weighs in.
	 *
	 * The individual rating of the player is deliberately not a parameter: it only
	 * matters through the team average.
	 *
	 * @param	float	$ownTeamElo			Rating of the player team (RG-12)
	 * @param	float	$opponentTeamElo	Rating of the other team
	 * @param	float	$result				One of RESULT_WIN, RESULT_DRAW, RESULT_LOSS
	 * @return	int							Elo delta, rounded to the nearest integer
	 */
	public function delta(float $ownTeamElo, float $opponentTeamElo, float $result): int
	{
		$expected = $this->expectedScore($ownTeamElo, $opponentTeamElo);

		return (int) round($this->k * ($result - $expected));
	}
}
