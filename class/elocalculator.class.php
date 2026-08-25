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

	/** @var float Score of a drawn game */
	const RESULT_DRAW = 0.5;

	/** @var float Score of a lost game */
	const RESULT_LOSS = 0.0;

	/** @var int Rating gap giving a ten to one win expectancy */
	const RATING_SCALE = 400;

	/** @var int K factor of confirmed players */
	private $kStandard;

	/** @var int K factor of novice players */
	private $kNovice;

	/** @var int Number of games played before leaving the novice status */
	private $noviceGames;

	/** @var bool Whether K is weighted by the goal difference */
	private $marginEnabled;

	/**
	 * Constructor
	 *
	 * @param	int		$kStandard		K factor of confirmed players (BABYFOOT_ELO_K)
	 * @param	int		$kNovice		K factor of novice players (BABYFOOT_ELO_K_NOVICE)
	 * @param	int		$noviceGames	Games threshold (BABYFOOT_ELO_NOVICE_GAMES)
	 * @param	bool	$marginEnabled	Weight K by the goal difference (BABYFOOT_ELO_MARGIN)
	 */
	public function __construct(int $kStandard, int $kNovice, int $noviceGames, bool $marginEnabled)
	{
		$this->kStandard = $kStandard;
		$this->kNovice = $kNovice;
		$this->noviceGames = $noviceGames;
		$this->marginEnabled = $marginEnabled;
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
	 * K factor of one player, based on how many games they already played (RG-15).
	 *
	 * @param	int		$nbGamesPlayed	Games already played by this player in this mode
	 * @return	int						K factor to apply
	 */
	public function coefficient(int $nbGamesPlayed): int
	{
		return ($nbGamesPlayed < $this->noviceGames) ? $this->kNovice : $this->kStandard;
	}

	/**
	 * Weighting of K by the goal difference (RG-16, decision D14).
	 *
	 * Floored at 1.0 so a tight game never shrinks K below its nominal value.
	 *
	 * @param	int		$scoreFor		Goals scored by the player side
	 * @param	int		$scoreAgainst	Goals scored by the other side
	 * @return	float					Multiplier, always greater than or equal to 1.0
	 */
	public function marginFactor(int $scoreFor, int $scoreAgainst): float
	{
		if (!$this->marginEnabled) {
			return 1.0;
		}

		$gap = abs($scoreFor - $scoreAgainst);
		if ($gap <= 1) {
			return 1.0;
		}

		return 1 + ($gap - 1) / 10;
	}

	/**
	 * Elo delta of one player for one game (RG-13, RG-14, RG-15, decision D10).
	 *
	 * The expected score comes from the team ratings, so both players of a 2v2
	 * side share the same expectancy; K stays individual, so their deltas may
	 * differ in magnitude but never in sign.
	 *
	 * The individual rating of the player is deliberately not a parameter: it
	 * only matters through the team average and through its own K factor.
	 *
	 * @param	float	$ownTeamElo			Rating of the player team (RG-12)
	 * @param	float	$opponentTeamElo	Rating of the other team
	 * @param	float	$result				One of RESULT_WIN, RESULT_DRAW, RESULT_LOSS
	 * @param	int		$nbGamesPlayed		Games already played by this player in this mode
	 * @param	int		$scoreFor			Goals scored by the player side
	 * @param	int		$scoreAgainst		Goals scored by the other side
	 * @return	int							Elo delta, rounded to the nearest integer
	 */
	public function delta(float $ownTeamElo, float $opponentTeamElo, float $result, int $nbGamesPlayed, int $scoreFor, int $scoreAgainst): int
	{
		$expected = $this->expectedScore($ownTeamElo, $opponentTeamElo);
		$k = $this->coefficient($nbGamesPlayed) * $this->marginFactor($scoreFor, $scoreAgainst);

		return (int) round($k * ($result - $expected));
	}
}
