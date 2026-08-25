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
 * \file    class/ratingset.class.php
 * \ingroup babyfoot
 * \brief   Collection of ranking rows, indexed by user and mode.
 */

require_once dirname(__FILE__).'/rating.class.php';

/**
 * Holds the ranking rows a computation works on.
 *
 * This is the only class knowing where the rows come from. It can be fed from
 * database (incremental computation) or left empty and filled on the fly (full
 * recompute). RatingEngine is indifferent to which, and that indifference is
 * what makes both paths converge on the same result.
 */
class RatingSet
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var int Entity to work on */
	private $entity;

	/** @var int Starting Elo of a player with no history */
	private $eloInitial;

	/** @var array<int,array<string,Rating>> Ranking rows, indexed by user then mode */
	private $ratings = array();

	/** @var array<int,array<string,bool>> Which rows already exist in database */
	private $persisted = array();

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int		$entity			Entity to work on
	 * @param	int		$eloInitial		Starting Elo (BABYFOOT_ELO_INITIAL)
	 */
	public function __construct(DoliDB $db, int $entity, int $eloInitial)
	{
		$this->db = $db;
		$this->entity = $entity;
		$this->eloInitial = $eloInitial;
	}

	/**
	 * Load the ranking rows of the given users from database.
	 *
	 * @param	int[]	$userIds	Users to load
	 * @param	bool	$lock		Add FOR UPDATE, to serialise concurrent writers
	 * @return	int					1 if OK, -1 if KO
	 */
	public function loadForUsers(array $userIds, bool $lock = false): int
	{
		if (empty($userIds)) {
			return 1;
		}

		// Ascending order: a stable lock order prevents deadlocks between writers
		$ids = array_map('intval', $userIds);
		$ids = array_values(array_unique($ids));
		sort($ids);

		$sql = "SELECT r.rowid, r.entity, r.fk_user, r.mode, r.elo, r.nb_games, r.nb_wins,";
		$sql .= " r.nb_losses, r.nb_draws, r.goals_for, r.goals_against,";
		$sql .= " r.nb_fanny_given, r.nb_fanny_taken, r.current_streak, r.best_streak,";
		$sql .= " r.elo_peak, r.date_last_game";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		$sql .= " AND r.fk_user IN (".implode(',', $ids).")";
		$sql .= " ORDER BY r.fk_user ASC, r.mode ASC";
		if ($lock) {
			$sql .= " FOR UPDATE";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RatingSet::loadForUsers '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rating = new Rating($this->db);
			$rating->id = (int) $obj->rowid;
			$rating->entity = (int) $obj->entity;
			$rating->fk_user = (int) $obj->fk_user;
			$rating->mode = $obj->mode;
			$rating->elo = (int) $obj->elo;
			$rating->nb_games = (int) $obj->nb_games;
			$rating->nb_wins = (int) $obj->nb_wins;
			$rating->nb_losses = (int) $obj->nb_losses;
			$rating->nb_draws = (int) $obj->nb_draws;
			$rating->goals_for = (int) $obj->goals_for;
			$rating->goals_against = (int) $obj->goals_against;
			$rating->nb_fanny_given = (int) $obj->nb_fanny_given;
			$rating->nb_fanny_taken = (int) $obj->nb_fanny_taken;
			$rating->current_streak = (int) $obj->current_streak;
			$rating->best_streak = (int) $obj->best_streak;
			$rating->elo_peak = (int) $obj->elo_peak;
			$rating->date_last_game = $this->db->jdate($obj->date_last_game);

			$this->ratings[$rating->fk_user][$rating->mode] = $rating;
			$this->persisted[$rating->fk_user][$rating->mode] = true;
		}
		$this->db->free($resql);

		return 1;
	}

	/**
	 * Return the ranking row of a player in one mode, creating it on the fly (RG-10).
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @param	string	$mode		'1v1', '2v2' or 'all'
	 * @return	Rating				Ranking row, never null
	 */
	public function get(int $userId, string $mode): Rating
	{
		if (!isset($this->ratings[$userId][$mode])) {
			$this->ratings[$userId][$mode] = Rating::initFor($this->db, $this->entity, $userId, $mode, $this->eloInitial);
			$this->persisted[$userId][$mode] = false;
		}

		return $this->ratings[$userId][$mode];
	}

	/**
	 * Tell whether a player already has a ranking row in this mode.
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @param	string	$mode		'1v1', '2v2' or 'all'
	 * @return	bool				True when the row is held by the set
	 */
	public function has(int $userId, string $mode): bool
	{
		return isset($this->ratings[$userId][$mode]);
	}

	/**
	 * Return every ranking row held by the set.
	 *
	 * @return	Rating[]	Flat list
	 */
	public function all(): array
	{
		$flat = array();
		foreach ($this->ratings as $byMode) {
			foreach ($byMode as $rating) {
				$flat[] = $rating;
			}
		}

		return $flat;
	}

	/**
	 * Write every held ranking row to database, inserting or updating.
	 *
	 * @param	User	$user	User doing the write
	 * @return	int				1 if OK, -1 if KO
	 */
	public function flush(User $user): int
	{
		foreach ($this->ratings as $userId => $byMode) {
			foreach ($byMode as $mode => $rating) {
				if (!empty($this->persisted[$userId][$mode])) {
					if ($rating->update($user, 1) <= 0) {
						dol_syslog('RatingSet::flush update failed: '.$rating->error, LOG_ERR);
						return -1;
					}
				} else {
					if ($rating->create($user, 1) <= 0) {
						dol_syslog('RatingSet::flush create failed: '.$rating->error, LOG_ERR);
						return -1;
					}
					$this->persisted[$userId][$mode] = true;
				}
			}
		}

		return 1;
	}

	/**
	 * Empty the set, keeping its configuration.
	 *
	 * @return	void
	 */
	public function clear(): void
	{
		$this->ratings = array();
		$this->persisted = array();
	}
}
