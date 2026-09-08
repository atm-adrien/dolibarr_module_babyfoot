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
 * \file    class/gamevalidator.class.php
 * \ingroup babyfoot
 * \brief   Business rules RG-01 to RG-08 of the specification.
 */

require_once dirname(__FILE__).'/babyfootconfig.class.php';

/**
 * Validates a game before it is stored.
 *
 * Works on scalar values rather than on a Game object, so the entry screen can
 * call it before building anything, and so it stays unit-testable without
 * inserting a record.
 */
class GameValidator
{
	/** @var int Window in seconds used to detect a double submit (RG-08) */
	const DUPLICATE_WINDOW = 300;

	/** @var int Status of a validated game, the only one considered here */
	const STATUS_VALIDATED = 1;

	/** @var DoliDB Database handler */
	private $db;

	/** @var int Entity to filter on */
	private $entity;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$entity		Entity to filter on
	 */
	public function __construct(DoliDB $db, int $entity)
	{
		$this->db = $db;
		$this->entity = $entity;
	}

	/**
	 * Deduce the winning team from the score. Never entered by the user (section 6.1).
	 *
	 * @param	int			$scoreTeam1		Goals of team 1
	 * @param	int			$scoreTeam2		Goals of team 2
	 * @return	int|null					1, 2, or null on a draw
	 */
	public static function deduceWinner(int $scoreTeam1, int $scoreTeam2): ?int
	{
		if ($scoreTeam1 > $scoreTeam2) {
			return 1;
		}
		if ($scoreTeam2 > $scoreTeam1) {
			return 2;
		}

		return null;
	}

	/**
	 * Check a game against rules RG-01 to RG-06.
	 *
	 * @param	string		$mode			Game mode, '1v1' or '2v2'
	 * @param	int			$scoreTeam1		Goals of team 1
	 * @param	int			$scoreTeam2		Goals of team 2
	 * @param	int			$dateGame		Timestamp of the game
	 * @param	array		$players		List of array{fk_user:int, team:int}
	 * @return	string[]					Translation keys of the violated rules, empty when valid
	 */
	public function validate(string $mode, int $scoreTeam1, int $scoreTeam2, int $dateGame, array $players): array
	{
		$errors = array();

		// RG-01: known mode, and the exact expected number of players per team
		$expected = BabyfootConfig::playersPerTeam($mode);
		if ($expected === 0) {
			$errors[] = 'BabyfootErrUnknownMode';
		} else {
			$countByTeam = array(1 => 0, 2 => 0);
			$userIds = array();
			if (is_array($players) && !empty($players)) {
				foreach ($players as $player) {
					$team = (int) $player['team'];
					if ($team === 1 || $team === 2) {
						$countByTeam[$team]++;
					}
					$userIds[] = (int) $player['fk_user'];
				}
			}
			if ($countByTeam[1] !== $expected || $countByTeam[2] !== $expected) {
				$errors[] = 'BabyfootErrPlayerCount';
			}
			// RG-02: a user belongs to one single team of one single game
			if (count($userIds) !== count(array_unique($userIds))) {
				$errors[] = 'BabyfootErrDuplicatePlayer';
			}
		}

		// RG-03: scores are integers within [0, SCORE_MAX]
		$max = BabyfootConfig::SCORE_MAX;
		if ($scoreTeam1 < 0 || $scoreTeam2 < 0 || $scoreTeam1 > $max || $scoreTeam2 > $max) {
			$errors[] = 'BabyfootErrScoreRange';
		} elseif (max($scoreTeam1, $scoreTeam2) !== $max) {
			// RG-04: the winning team always has to reach the maximum score
			$errors[] = 'BabyfootErrScoreExact';
		}

		// RG-05: a draw is never a valid babyfoot result
		if ($scoreTeam1 === $scoreTeam2) {
			$errors[] = 'BabyfootErrDrawNotAllowed';
		}

		// RG-06: a game cannot be played in the future
		if ($dateGame > dol_now()) {
			$errors[] = 'BabyfootErrFutureDate';
		}

		return $errors;
	}

	/**
	 * Look for a strictly identical game entered in the last few minutes (RG-08).
	 *
	 * Non blocking: the caller only warns the user. The filter applies to
	 * date_creation and not to date_game, because what we catch here is a double
	 * submit, not the legitimate re-entry of an old game.
	 *
	 * @param	string	$mode			Game mode, '1v1' or '2v2'
	 * @param	int		$scoreTeam1		Goals of team 1
	 * @param	int		$scoreTeam2		Goals of team 2
	 * @param	array	$players		List of array{fk_user:int, team:int}
	 * @return	int						Rowid of the matching game, 0 when there is none
	 */
	public function findRecentDuplicate(string $mode, int $scoreTeam1, int $scoreTeam2, array $players): int
	{
		if (!is_array($players) || empty($players)) {
			return 0;
		}

		$since = dol_print_date(dol_now() - self::DUPLICATE_WINDOW, 'standard');

		$sql = "SELECT g.rowid";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) self::STATUS_VALIDATED);
		$sql .= " AND g.mode = '".$this->db->escape($mode)."'";
		$sql .= " AND g.score_team1 = ".((int) $scoreTeam1);
		$sql .= " AND g.score_team2 = ".((int) $scoreTeam2);
		$sql .= " AND g.date_creation >= '".$this->db->escape($since)."'";
		$sql .= " ORDER BY g.rowid DESC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameValidator::findRecentDuplicate '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$candidates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$candidates[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$wanted = $this->signature($players);
		foreach ($candidates as $candidateId) {
			if ($this->signature($this->fetchPlayers($candidateId)) === $wanted) {
				return $candidateId;
			}
		}

		return 0;
	}

	/**
	 * Build a comparable signature of a team composition.
	 *
	 * @param	array	$players	List of array{fk_user:int, team:int}
	 * @return	string				Signature, stable whatever the input order
	 */
	private function signature(array $players): string
	{
		$byTeam = array(1 => array(), 2 => array());
		if (is_array($players) && !empty($players)) {
			foreach ($players as $player) {
				$team = (int) $player['team'];
				if ($team === 1 || $team === 2) {
					$byTeam[$team][] = (int) $player['fk_user'];
				}
			}
		}
		sort($byTeam[1]);
		sort($byTeam[2]);

		return implode(',', $byTeam[1]).'|'.implode(',', $byTeam[2]);
	}

	/**
	 * Load the players of a stored game.
	 *
	 * @param	int		$gameId		Game rowid
	 * @return	array				List of array{fk_user:int, team:int}
	 */
	private function fetchPlayers(int $gameId): array
	{
		$players = array();

		$sql = "SELECT gp.fk_user, gp.team";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " WHERE gp.fk_game = ".((int) $gameId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameValidator::fetchPlayers '.$this->db->lasterror(), LOG_ERR);
			return $players;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$players[] = array('fk_user' => (int) $obj->fk_user, 'team' => (int) $obj->team);
		}
		$this->db->free($resql);

		return $players;
	}
}
