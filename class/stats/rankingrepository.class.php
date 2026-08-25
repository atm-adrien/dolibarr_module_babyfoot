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
 * \file    class/stats/rankingrepository.class.php
 * \ingroup babyfoot
 * \brief   Read only aggregates feeding the ranking screen.
 */

require_once dirname(__FILE__).'/../babyfootconfig.class.php';
require_once dirname(__FILE__).'/../game.class.php';

/**
 * Builds the ranking, either straight from the cache table or aggregated over a
 * restricted period.
 *
 * Read only: this class never writes anything, and never replays any Elo.
 */
class RankingRepository
{
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
	 * Return the ranked players of one mode.
	 *
	 * Without a period, the rows come straight from the cache table, with no
	 * computation at all. With a period, the counters are aggregated over the
	 * games, while the displayed Elo stays the current one, completed by the Elo
	 * variation of the period.
	 *
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	int		$minGames		Games required to be ranked (RG-19)
	 * @param	int		$dateFrom		Start timestamp, 0 for no lower bound
	 * @param	int		$dateTo			End timestamp, 0 for no upper bound
	 * @return	array					Ranked rows, best first
	 */
	public function getRanking(string $mode, int $minGames, int $dateFrom = 0, int $dateTo = 0): array
	{
		if ($dateFrom <= 0 && $dateTo <= 0) {
			return $this->getRankingFromCache($mode, $minGames, false);
		}

		return $this->getRankingForPeriod($mode, $minGames, $dateFrom, $dateTo);
	}

	/**
	 * Return the players not ranked yet, below the minimum number of games (RG-19).
	 *
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	int		$minGames		Games required to be ranked
	 * @return	array					Unranked rows
	 */
	public function getUnranked(string $mode, int $minGames): array
	{
		return $this->getRankingFromCache($mode, $minGames, true);
	}

	/**
	 * Return the rank variation of every player since their own last game (D12).
	 *
	 * The reference is the rank the player held just before their last game: the
	 * previous Elo is the current one minus the delta of that game. One query,
	 * no replay. A player who did not play in this mode stays at 0.
	 *
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	array	$currentRanking	Result of getRanking(), in order
	 * @return	array<int,int>			Rank variation per user: positive means moved up
	 */
	public function getRankVariationSinceLastGame(string $mode, array $currentRanking): array
	{
		$variations = array();
		if (empty($currentRanking)) {
			return $variations;
		}

		$lastDeltas = $this->getLastGameDeltas($mode);

		// Rebuild the state that preceded each player last game
		$previous = array();
		foreach ($currentRanking as $row) {
			$userId = (int) $row['fk_user'];
			$delta = isset($lastDeltas[$userId]) ? (int) $lastDeltas[$userId] : 0;
			$previous[] = array(
				'fk_user' => $userId,
				'elo' => (int) $row['elo'] - $delta,
				'ratio' => isset($row['ratio']) ? (float) $row['ratio'] : 0.0,
				'goal_diff' => isset($row['goal_diff']) ? (int) $row['goal_diff'] : 0,
				'nb_games' => isset($row['nb_games']) ? (int) $row['nb_games'] : 0,
			);
		}
		$previous = $this->sortRanking($previous);

		$previousPositions = array();
		foreach ($previous as $position => $row) {
			$previousPositions[(int) $row['fk_user']] = $position;
		}

		foreach ($currentRanking as $position => $row) {
			$userId = (int) $row['fk_user'];
			if (!isset($previousPositions[$userId])) {
				$variations[$userId] = 0;
				continue;
			}
			// A smaller index is a better rank, hence the subtraction order
			$variations[$userId] = $previousPositions[$userId] - $position;
		}

		return $variations;
	}

	/**
	 * Read the ranking straight from the cache table.
	 *
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	int		$minGames		Games required to be ranked
	 * @param	bool	$belowMinimum	true to get the unranked players instead
	 * @return	array					Rows, best first
	 */
	private function getRankingFromCache(string $mode, int $minGames, bool $belowMinimum): array
	{
		$rows = array();

		$sql = "SELECT r.fk_user, r.elo, r.nb_games, r.nb_wins, r.nb_losses, r.nb_draws,";
		$sql .= " r.goals_for, r.goals_against, r.current_streak, r.best_streak,";
		$sql .= " r.elo_peak, r.date_last_game";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		// fk_user > 0 also excludes the sentinel lock row
		$sql .= " AND r.fk_user > 0";
		$sql .= " AND r.mode = '".$this->db->escape($mode)."'";
		$sql .= $belowMinimum ? " AND r.nb_games < ".((int) $minGames) : " AND r.nb_games >= ".((int) $minGames);
		// Section 9 tie break: win ratio, then goal difference, then games played.
		// The final sort on fk_user keeps the order stable between two calls.
		$sql .= " ORDER BY r.elo DESC,";
		$sql .= " CASE WHEN r.nb_games > 0 THEN (r.nb_wins * 1.0 / r.nb_games) ELSE 0 END DESC,";
		$sql .= " (r.goals_for - r.goals_against) DESC,";
		$sql .= " r.nb_games DESC, r.fk_user ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RankingRepository::getRankingFromCache '.$this->db->lasterror(), LOG_ERR);
			return $rows;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$nbGames = (int) $obj->nb_games;
			$rows[] = array(
				'fk_user' => (int) $obj->fk_user,
				'elo' => (int) $obj->elo,
				'elo_peak' => (int) $obj->elo_peak,
				'nb_games' => $nbGames,
				'nb_wins' => (int) $obj->nb_wins,
				'nb_losses' => (int) $obj->nb_losses,
				'nb_draws' => (int) $obj->nb_draws,
				'ratio' => ($nbGames > 0) ? ((int) $obj->nb_wins / $nbGames) : 0.0,
				'goal_diff' => (int) $obj->goals_for - (int) $obj->goals_against,
				'current_streak' => (int) $obj->current_streak,
				'best_streak' => (int) $obj->best_streak,
				'date_last_game' => $this->db->jdate($obj->date_last_game),
				'elo_variation' => null,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Aggregate the counters of a restricted period.
	 *
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	int		$minGames		Games required to be ranked
	 * @param	int		$dateFrom		Start timestamp, 0 for no lower bound
	 * @param	int		$dateTo			End timestamp, 0 for no upper bound
	 * @return	array					Rows, best first
	 */
	private function getRankingForPeriod(string $mode, int $minGames, int $dateFrom, int $dateTo): array
	{
		$rows = array();
		$deltaColumn = ($mode === BabyfootConfig::MODE_ALL) ? 'gp.elo_all_delta' : 'gp.elo_delta';

		$sql = "SELECT gp.fk_user,";
		$sql .= " COUNT(*) as nb_games,";
		$sql .= " SUM(CASE WHEN gp.is_winner = 1 THEN 1 ELSE 0 END) as nb_wins,";
		$sql .= " SUM(CASE WHEN gp.is_winner = 0 AND g.winner_team IS NOT NULL THEN 1 ELSE 0 END) as nb_losses,";
		$sql .= " SUM(CASE WHEN g.winner_team IS NULL THEN 1 ELSE 0 END) as nb_draws,";
		$sql .= " SUM(CASE WHEN gp.team = 1 THEN g.score_team1 ELSE g.score_team2 END) as goals_for,";
		$sql .= " SUM(CASE WHEN gp.team = 1 THEN g.score_team2 ELSE g.score_team1 END) as goals_against,";
		$sql .= " SUM(".$deltaColumn.") as elo_variation";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		if ($mode !== BabyfootConfig::MODE_ALL) {
			$sql .= " AND g.mode = '".$this->db->escape($mode)."'";
		}
		if ($dateFrom > 0) {
			$sql .= " AND g.date_game >= '".$this->db->idate($dateFrom)."'";
		}
		if ($dateTo > 0) {
			$sql .= " AND g.date_game <= '".$this->db->idate($dateTo)."'";
		}
		$sql .= " GROUP BY gp.fk_user";
		$sql .= " HAVING COUNT(*) >= ".((int) $minGames);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RankingRepository::getRankingForPeriod '.$this->db->lasterror(), LOG_ERR);
			return $rows;
		}
		$aggregated = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$nbGames = (int) $obj->nb_games;
			$aggregated[(int) $obj->fk_user] = array(
				'fk_user' => (int) $obj->fk_user,
				'nb_games' => $nbGames,
				'nb_wins' => (int) $obj->nb_wins,
				'nb_losses' => (int) $obj->nb_losses,
				'nb_draws' => (int) $obj->nb_draws,
				'ratio' => ($nbGames > 0) ? ((int) $obj->nb_wins / $nbGames) : 0.0,
				'goal_diff' => (int) $obj->goals_for - (int) $obj->goals_against,
				'elo_variation' => (int) $obj->elo_variation,
			);
		}
		$this->db->free($resql);

		if (empty($aggregated)) {
			return $rows;
		}

		// The displayed Elo stays the current one: read it from the cache, in a
		// separate query, so the period never filters the cache table itself
		$current = $this->getCurrentEloAndStreaks($mode, array_keys($aggregated));

		foreach ($aggregated as $userId => $row) {
			$row['elo'] = isset($current[$userId]['elo']) ? (int) $current[$userId]['elo'] : 0;
			$row['elo_peak'] = isset($current[$userId]['elo_peak']) ? (int) $current[$userId]['elo_peak'] : 0;
			$row['current_streak'] = isset($current[$userId]['current_streak']) ? (int) $current[$userId]['current_streak'] : 0;
			$row['best_streak'] = isset($current[$userId]['best_streak']) ? (int) $current[$userId]['best_streak'] : 0;
			$row['date_last_game'] = isset($current[$userId]['date_last_game']) ? $current[$userId]['date_last_game'] : null;
			$rows[] = $row;
		}

		return $this->sortRanking($rows);
	}

	/**
	 * Read the current Elo and streaks of the given players.
	 *
	 * @param	string	$mode		'1v1', '2v2' or 'all'
	 * @param	int[]	$userIds	Players to read
	 * @return	array				Rows indexed by user id
	 */
	private function getCurrentEloAndStreaks(string $mode, array $userIds): array
	{
		$current = array();
		if (empty($userIds)) {
			return $current;
		}

		$ids = array_map('intval', $userIds);

		$sql = "SELECT r.fk_user, r.elo, r.elo_peak, r.current_streak, r.best_streak, r.date_last_game";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		$sql .= " AND r.fk_user IN (".implode(',', $ids).")";
		$sql .= " AND r.mode = '".$this->db->escape($mode)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RankingRepository::getCurrentEloAndStreaks '.$this->db->lasterror(), LOG_ERR);
			return $current;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$current[(int) $obj->fk_user] = array(
				'elo' => (int) $obj->elo,
				'elo_peak' => (int) $obj->elo_peak,
				'current_streak' => (int) $obj->current_streak,
				'best_streak' => (int) $obj->best_streak,
				'date_last_game' => $this->db->jdate($obj->date_last_game),
			);
		}
		$this->db->free($resql);

		return $current;
	}

	/**
	 * Read the Elo delta of the last game of every player in one mode.
	 *
	 * The NOT EXISTS clause uses the same chronological order as the replay
	 * engine: date_game first, then rowid.
	 *
	 * @param	string	$mode	'1v1', '2v2' or 'all'
	 * @return	array<int,int>	Delta indexed by user id
	 */
	private function getLastGameDeltas(string $mode): array
	{
		$deltas = array();
		$deltaColumn = ($mode === BabyfootConfig::MODE_ALL) ? 'gp.elo_all_delta' : 'gp.elo_delta';

		$sql = "SELECT gp.fk_user, ".$deltaColumn." as last_delta";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		if ($mode !== BabyfootConfig::MODE_ALL) {
			$sql .= " AND g.mode = '".$this->db->escape($mode)."'";
		}
		$sql .= " AND NOT EXISTS (";
		$sql .= " SELECT 1 FROM ".$this->db->prefix()."babyfoot_game_player as gp2";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g2 ON g2.rowid = gp2.fk_game";
		$sql .= " WHERE gp2.fk_user = gp.fk_user";
		$sql .= " AND g2.entity = g.entity";
		$sql .= " AND g2.status = ".((int) Game::STATUS_VALIDATED);
		if ($mode !== BabyfootConfig::MODE_ALL) {
			$sql .= " AND g2.mode = '".$this->db->escape($mode)."'";
		}
		$sql .= " AND (g2.date_game > g.date_game OR (g2.date_game = g.date_game AND g2.rowid > g.rowid))";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RankingRepository::getLastGameDeltas '.$this->db->lasterror(), LOG_ERR);
			return $deltas;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$deltas[(int) $obj->fk_user] = (int) $obj->last_delta;
		}
		$this->db->free($resql);

		return $deltas;
	}

	/**
	 * Sort a ranking in PHP, applying the tie break of section 9.
	 *
	 * @param	array	$rows	Rows to sort
	 * @return	array			Sorted rows, best first
	 */
	private function sortRanking(array $rows): array
	{
		usort($rows, function ($a, $b) {
			if ((int) $a['elo'] !== (int) $b['elo']) {
				return ((int) $b['elo']) <=> ((int) $a['elo']);
			}
			$ratioA = isset($a['ratio']) ? (float) $a['ratio'] : 0.0;
			$ratioB = isset($b['ratio']) ? (float) $b['ratio'] : 0.0;
			if (abs($ratioA - $ratioB) > 0.000001) {
				return $ratioB <=> $ratioA;
			}
			$diffA = isset($a['goal_diff']) ? (int) $a['goal_diff'] : 0;
			$diffB = isset($b['goal_diff']) ? (int) $b['goal_diff'] : 0;
			if ($diffA !== $diffB) {
				return $diffB <=> $diffA;
			}
			$gamesA = isset($a['nb_games']) ? (int) $a['nb_games'] : 0;
			$gamesB = isset($b['nb_games']) ? (int) $b['nb_games'] : 0;
			if ($gamesA !== $gamesB) {
				return $gamesB <=> $gamesA;
			}

			// Stable final criterion
			return ((int) $a['fk_user']) <=> ((int) $b['fk_user']);
		});

		return $rows;
	}
}
