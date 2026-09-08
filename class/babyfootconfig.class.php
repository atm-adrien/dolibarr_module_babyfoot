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
 * \file    class/babyfootconfig.class.php
 * \ingroup babyfoot
 * \brief   Single point of access to the module settings.
 */

require_once dirname(__FILE__).'/elocalculator.class.php';

/**
 * Resolves the module settings (spec section 7) into a plain array.
 *
 * This is the only place in the module allowed to read getDolGlobalString() and
 * getDolGlobalInt(), so the business classes receive a resolved array and stay
 * testable without a configuration bootstrap.
 */
class BabyfootConfig
{
	/** @var string Single game mode */
	const MODE_1V1 = '1v1';

	/** @var string Double game mode */
	const MODE_2V2 = '2v2';

	/** @var string Pseudo mode holding the overall ranking (RG-17) */
	const MODE_ALL = 'all';

	/** @var int Number of players per team in single mode */
	const PLAYERS_1V1 = 1;

	/** @var int Number of players per team in double mode */
	const PLAYERS_2V2 = 2;

	/** @var int Goals a game is always played to (RG-03, RG-04) */
	const SCORE_MAX = 10;

	/** @var int K factor of the Elo formula, identical for every player (RG-15) */
	const ELO_K = 40;

	/**
	 * Return the real game modes, excluding the overall pseudo mode.
	 *
	 * @return	string[]	Game modes
	 */
	public static function gameModes(): array
	{
		return array(self::MODE_1V1, self::MODE_2V2);
	}

	/**
	 * Return every mode a rating row may exist for.
	 *
	 * @return	string[]	Rating modes
	 */
	public static function ratingModes(): array
	{
		return array(self::MODE_1V1, self::MODE_2V2, self::MODE_ALL);
	}

	/**
	 * Number of players expected per team for a given mode (RG-01).
	 *
	 * @param	string	$mode	One of MODE_1V1 or MODE_2V2
	 * @return	int				1 or 2, and 0 when the mode is unknown
	 */
	public static function playersPerTeam(string $mode): int
	{
		if ($mode === self::MODE_1V1) {
			return self::PLAYERS_1V1;
		}
		if ($mode === self::MODE_2V2) {
			return self::PLAYERS_2V2;
		}

		return 0;
	}

	/**
	 * Resolve every module setting into a plain array.
	 *
	 * Only what an office may legitimately want to change lives here. The scoring
	 * rules and the K factor are constants of this class: a game is played to
	 * SCORE_MAX, the winner must reach it, a draw is impossible, and every player
	 * shares the same K.
	 *
	 * @return	array{elo_initial:int,prefill_current_user:bool,default_mode:string}	Resolved settings
	 */
	public static function resolve(): array
	{
		$defaultMode = getDolGlobalString('BABYFOOT_DEFAULT_MODE', self::MODE_2V2);
		if (!in_array($defaultMode, self::gameModes(), true)) {
			$defaultMode = self::MODE_2V2;
		}

		return array(
			'elo_initial' => getDolGlobalInt('BABYFOOT_ELO_INITIAL', 1000),
			'prefill_current_user' => (bool) getDolGlobalInt('BABYFOOT_PREFILL_CURRENT_USER', 1),
			'default_mode' => $defaultMode,
		);
	}

	/**
	 * Build an EloCalculator.
	 *
	 * @return	EloCalculator	Calculator ready to use
	 */
	public static function createCalculator(): EloCalculator
	{
		return new EloCalculator(self::ELO_K);
	}
}
