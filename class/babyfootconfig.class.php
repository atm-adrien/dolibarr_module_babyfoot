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
	 * @return	array{score_max:int,score_exact:bool,allow_draw:bool,elo_initial:int,elo_k:int,elo_k_novice:int,elo_novice_games:int,elo_margin:bool,min_games_ranked:int,edit_delay:int,prefill_current_user:bool,default_mode:string}	Resolved settings
	 */
	public static function resolve(): array
	{
		$defaultMode = getDolGlobalString('BABYFOOT_DEFAULT_MODE', self::MODE_2V2);
		if (!in_array($defaultMode, self::gameModes(), true)) {
			$defaultMode = self::MODE_2V2;
		}

		return array(
			'score_max' => getDolGlobalInt('BABYFOOT_SCORE_MAX', 10),
			'score_exact' => (bool) getDolGlobalInt('BABYFOOT_SCORE_EXACT', 1),
			'allow_draw' => (bool) getDolGlobalInt('BABYFOOT_ALLOW_DRAW', 0),
			'elo_initial' => getDolGlobalInt('BABYFOOT_ELO_INITIAL', 1000),
			'elo_k' => getDolGlobalInt('BABYFOOT_ELO_K', 24),
			'elo_k_novice' => getDolGlobalInt('BABYFOOT_ELO_K_NOVICE', 40),
			'elo_novice_games' => getDolGlobalInt('BABYFOOT_ELO_NOVICE_GAMES', 15),
			'elo_margin' => (bool) getDolGlobalInt('BABYFOOT_ELO_MARGIN', 0),
			'min_games_ranked' => getDolGlobalInt('BABYFOOT_MIN_GAMES_RANKED', 5),
			'edit_delay' => getDolGlobalInt('BABYFOOT_EDIT_DELAY', 24),
			'prefill_current_user' => (bool) getDolGlobalInt('BABYFOOT_PREFILL_CURRENT_USER', 1),
			'default_mode' => $defaultMode,
		);
	}

	/**
	 * Build an EloCalculator from the resolved settings.
	 *
	 * @param	array|null		$config		Result of resolve(), resolved again when null
	 * @return	EloCalculator				Calculator ready to use
	 */
	public static function createCalculator(?array $config = null): EloCalculator
	{
		if (is_null($config)) {
			$config = self::resolve();
		}

		return new EloCalculator(
			(int) $config['elo_k'],
			(int) $config['elo_k_novice'],
			(int) $config['elo_novice_games'],
			(bool) $config['elo_margin']
		);
	}
}
