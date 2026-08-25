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
 * \file    class/stats/playerstatsrepository.class.php
 * \ingroup babyfoot
 * \brief   Read only aggregates feeding the player card.
 */

require_once dirname(__FILE__).'/../babyfootconfig.class.php';
require_once dirname(__FILE__).'/../game.class.php';

/**
 * Aggregates the statistics of one player.
 *
 * Read only. The Elo history is read from the stored snapshots, never replayed:
 * that is exactly why the elo_all_* columns exist.
 */
class PlayerStatsRepository
{
	/** @var int Minimum games together before a relation is shown (section 6.5) */
	const MIN_SHARED_GAMES = 3;

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
	 * Return the ranking rows of one player, indexed by mode.
	 *
	 * A mode the player never played has no row at all: it must never be shown
	 * at the initial Elo (section 9).
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @return	array				Rows indexed by mode
	 */
	public function getRatings(int $userId): array
	{
		$ratings = array();

		$sql = "SELECT r.mode, r.elo, r.elo_peak, r.nb_games, r.nb_wins, r.nb_losses, r.nb_draws,";
		$sql .= " r.goals_for, r.goals_against, r.nb_fanny_given, r.nb_fanny_taken,";
		$sql .= " r.current_streak, r.best_streak, r.date_last_game";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		$sql .= " AND r.fk_user = ".((int) $userId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('PlayerStatsRepository::getRatings '.$this->db->lasterror(), LOG_ERR);
			return $ratings;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$nbGames = (int) $obj->nb_games;
			$ratings[$obj->mode] = array(
				'mode' => $obj->mode,
				'elo' => (int) $obj->elo,
				'elo_peak' => (int) $obj->elo_peak,
				'nb_games' => $nbGames,
				'nb_wins' => (int) $obj->nb_wins,
				'nb_losses' => (int) $obj->nb_losses,
				'nb_draws' => (int) $obj->nb_draws,
				'goals_for' => (int) $obj->goals_for,
				'goals_against' => (int) $obj->goals_against,
				'nb_fanny_given' => (int) $obj->nb_fanny_given,
				'nb_fanny_taken' => (int) $obj->nb_fanny_taken,
				'current_streak' => (int) $obj->current_streak,
				'best_streak' => (int) $obj->best_streak,
				'date_last_game' => $this->db->jdate($obj->date_last_game),
				'ratio' => ($nbGames > 0) ? ((int) $obj->nb_wins / $nbGames) : 0.0,
				'avg_goal_diff' => ($nbGames > 0) ? (((int) $obj->goals_for - (int) $obj->goals_against) / $nbGames) : 0.0,
			);
		}
		$this->db->free($resql);

		return $ratings;
	}

	/**
	 * Return the Elo history of one player, for the chart.
	 *
	 * Reads the stored snapshots: no computation at all (section 9 forbids
	 * replaying Elo at display time).
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @param	string	$mode		'1v1', '2v2' or 'all'
	 * @return	array				List of array{date:int, elo:int}
	 */
	public function getEloHistory(int $userId, string $mode): array
	{
		$history = array();
		$column = ($mode === BabyfootConfig::MODE_ALL) ? 'gp.elo_all_after' : 'gp.elo_after';

		$sql = "SELECT g.date_game, ".$column." as elo";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		$sql .= " AND gp.fk_user = ".((int) $userId);
		if ($mode !== BabyfootConfig::MODE_ALL) {
			$sql .= " AND g.mode = '".$this->db->escape($mode)."'";
		}
		$sql .= " ORDER BY g.date_game ASC, g.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('PlayerStatsRepository::getEloHistory '.$this->db->lasterror(), LOG_ERR);
			return $history;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$history[] = array(
				'date' => $this->db->jdate($obj->date_game),
				'elo' => (int) $obj->elo,
			);
		}
		$this->db->free($resql);

		return $history;
	}

	/**
	 * Return the teammate with the best win ratio (section 6.5).
	 *
	 * @param	int			$userId		Rowid of the Dolibarr user
	 * @return	array|null				Best teammate, null when there is not enough data
	 */
	public function getBestTeammate(int $userId): ?array
	{
		return $this->pickRelation($this->getRelations($userId, true), true);
	}

	/**
	 * Return the opponent with the worst win ratio: the nemesis (section 6.5).
	 *
	 * @param	int			$userId		Rowid of the Dolibarr user
	 * @return	array|null				Nemesis, null when there is not enough data
	 */
	public function getNemesis(int $userId): ?array
	{
		return $this->pickRelation($this->getRelations($userId, false), false);
	}

	/**
	 * Return the opponent with the best win ratio: the favourite victim.
	 *
	 * @param	int			$userId		Rowid of the Dolibarr user
	 * @return	array|null				Favourite victim, null when there is not enough data
	 */
	public function getFavouriteVictim(int $userId): ?array
	{
		return $this->pickRelation($this->getRelations($userId, false), true);
	}

	/**
	 * Return the last games of one player, with their Elo change.
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @param	int		$limit		How many games to return
	 * @return	array				List of games, newest first
	 */
	public function getLastGames(int $userId, int $limit = 10): array
	{
		$games = array();

		$sql = "SELECT g.rowid, g.ref, g.date_game, g.mode, g.score_team1, g.score_team2,";
		$sql .= " g.winner_team, gp.team, gp.is_winner, gp.elo_delta, gp.elo_after";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		$sql .= " AND gp.fk_user = ".((int) $userId);
		$sql .= " ORDER BY g.date_game DESC, g.rowid DESC";
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('PlayerStatsRepository::getLastGames '.$this->db->lasterror(), LOG_ERR);
			return $games;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$games[] = array(
				'rowid' => (int) $obj->rowid,
				'ref' => $obj->ref,
				'date_game' => $this->db->jdate($obj->date_game),
				'mode' => $obj->mode,
				'score_team1' => (int) $obj->score_team1,
				'score_team2' => (int) $obj->score_team2,
				'winner_team' => is_null($obj->winner_team) ? null : (int) $obj->winner_team,
				'team' => (int) $obj->team,
				'is_winner' => (int) $obj->is_winner,
				'elo_delta' => (int) $obj->elo_delta,
				'elo_after' => (int) $obj->elo_after,
			);
		}
		$this->db->free($resql);

		return $games;
	}

	/**
	 * Aggregate the record of a player alongside or against every other player.
	 *
	 * The three relation methods share this single parameterised query: same
	 * self join, only the team comparison and the final sort differ.
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @param	bool	$sameTeam	true for teammates, false for opponents
	 * @return	array				List of array{fk_user:int, nb:int, wins:int, ratio:float}
	 */
	private function getRelations(int $userId, bool $sameTeam): array
	{
		$relations = array();

		$sql = "SELECT other.fk_user, COUNT(*) as nb,";
		$sql .= " SUM(CASE WHEN mine.is_winner = 1 THEN 1 ELSE 0 END) as wins";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as mine";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game_player as other ON other.fk_game = mine.fk_game";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = mine.fk_game";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		$sql .= " AND mine.fk_user = ".((int) $userId);
		$sql .= " AND other.fk_user <> ".((int) $userId);
		$sql .= $sameTeam ? " AND other.team = mine.team" : " AND other.team <> mine.team";
		$sql .= " GROUP BY other.fk_user";
		$sql .= " HAVING COUNT(*) >= ".((int) self::MIN_SHARED_GAMES);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('PlayerStatsRepository::getRelations '.$this->db->lasterror(), LOG_ERR);
			return $relations;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$nb = (int) $obj->nb;
			$relations[] = array(
				'fk_user' => (int) $obj->fk_user,
				'nb' => $nb,
				'wins' => (int) $obj->wins,
				'ratio' => ($nb > 0) ? ((int) $obj->wins / $nb) : 0.0,
			);
		}
		$this->db->free($resql);

		return $relations;
	}

	/**
	 * Pick the relation with the highest or the lowest win ratio.
	 *
	 * @param	array		$relations	Result of getRelations()
	 * @param	bool		$highest	true for the highest ratio, false for the lowest
	 * @return	array|null				Selected relation, null when the list is empty
	 */
	private function pickRelation(array $relations, bool $highest): ?array
	{
		if (empty($relations)) {
			return null;
		}

		$best = null;
		foreach ($relations as $relation) {
			if (is_null($best)) {
				$best = $relation;
				continue;
			}
			if ($highest && $relation['ratio'] > $best['ratio']) {
				$best = $relation;
			}
			if (!$highest && $relation['ratio'] < $best['ratio']) {
				$best = $relation;
			}
		}

		return $best;
	}
}
