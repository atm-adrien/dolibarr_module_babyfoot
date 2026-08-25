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
 * \file    class/stats/globalstatsrepository.class.php
 * \ingroup babyfoot
 * \brief   Read only aggregates feeding the collective statistics screen.
 */

require_once dirname(__FILE__).'/../babyfootconfig.class.php';
require_once dirname(__FILE__).'/../game.class.php';

/**
 * Collective statistics: the questions people actually ask around the table.
 *
 * Read only, every query filtered on the entity and on validated games (RG-20).
 */
class GlobalStatsRepository
{
	/** @var int Minimum games together before a duo is listed (section 6.6) */
	const MIN_DUO_GAMES = 5;

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
	 * Return the global totals.
	 *
	 * @return	array{nb_games:int,nb_goals:int,avg_goals:float}		Totals
	 */
	public function getTotals(): array
	{
		$totals = array('nb_games' => 0, 'nb_goals' => 0, 'avg_goals' => 0.0);

		$sql = "SELECT COUNT(*) as nb_games, COALESCE(SUM(g.score_team1 + g.score_team2), 0) as nb_goals";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= $this->baseWhere();

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getTotals '.$this->db->lasterror(), LOG_ERR);
			return $totals;
		}
		if ($obj = $this->db->fetch_object($resql)) {
			$totals['nb_games'] = (int) $obj->nb_games;
			$totals['nb_goals'] = (int) $obj->nb_goals;
			$totals['avg_goals'] = ($totals['nb_games'] > 0) ? ($totals['nb_goals'] / $totals['nb_games']) : 0.0;
		}
		$this->db->free($resql);

		return $totals;
	}

	/**
	 * Return the best performing 2v2 duos.
	 *
	 * LEAST and GREATEST make sure each pair is counted once only.
	 *
	 * @param	int		$limit		How many duos to return
	 * @return	array				List of array{user1:int,user2:int,nb:int,wins:int,ratio:float}
	 */
	public function getTopDuos(int $limit = 10): array
	{
		$duos = array();

		$sql = "SELECT LEAST(a.fk_user, b.fk_user) as user1, GREATEST(a.fk_user, b.fk_user) as user2,";
		$sql .= " COUNT(*) as nb, SUM(CASE WHEN a.is_winner = 1 THEN 1 ELSE 0 END) as wins";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as a";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game_player as b";
		$sql .= "   ON b.fk_game = a.fk_game AND b.team = a.team AND b.fk_user > a.fk_user";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = a.fk_game";
		$sql .= $this->baseWhere();
		$sql .= " AND g.mode = '".$this->db->escape(BabyfootConfig::MODE_2V2)."'";
		$sql .= " GROUP BY LEAST(a.fk_user, b.fk_user), GREATEST(a.fk_user, b.fk_user)";
		$sql .= " HAVING COUNT(*) >= ".((int) self::MIN_DUO_GAMES);
		$sql .= " ORDER BY (SUM(CASE WHEN a.is_winner = 1 THEN 1 ELSE 0 END) * 1.0 / COUNT(*)) DESC, nb DESC";
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getTopDuos '.$this->db->lasterror(), LOG_ERR);
			return $duos;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$nb = (int) $obj->nb;
			$duos[] = array(
				'user1' => (int) $obj->user1,
				'user2' => (int) $obj->user2,
				'nb' => $nb,
				'wins' => (int) $obj->wins,
				'ratio' => ($nb > 0) ? ((int) $obj->wins / $nb) : 0.0,
			);
		}
		$this->db->free($resql);

		return $duos;
	}

	/**
	 * Return the tightest recent games.
	 *
	 * @param	int		$limit		How many games to return
	 * @return	array				List of games, tightest first
	 */
	public function getTightestGames(int $limit = 5): array
	{
		return $this->getGamesByGap($limit, 'ASC');
	}

	/**
	 * Return the widest recent games.
	 *
	 * @param	int		$limit		How many games to return
	 * @return	array				List of games, widest first
	 */
	public function getWidestGames(int $limit = 5): array
	{
		return $this->getGamesByGap($limit, 'DESC');
	}

	/**
	 * Return the fanny table, read from the ranking cache (no recomputation).
	 *
	 * @return	array	List of array{fk_user:int,given:int,taken:int}
	 */
	public function getFannyTable(): array
	{
		$rows = array();

		$sql = "SELECT r.fk_user, r.nb_fanny_given, r.nb_fanny_taken";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		$sql .= " AND r.fk_user > 0";
		$sql .= " AND r.mode = '".$this->db->escape(BabyfootConfig::MODE_ALL)."'";
		$sql .= " AND (r.nb_fanny_given > 0 OR r.nb_fanny_taken > 0)";
		$sql .= " ORDER BY r.nb_fanny_given DESC, r.nb_fanny_taken ASC, r.fk_user ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getFannyTable '.$this->db->lasterror(), LOG_ERR);
			return $rows;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'fk_user' => (int) $obj->fk_user,
				'given' => (int) $obj->nb_fanny_given,
				'taken' => (int) $obj->nb_fanny_taken,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Return how many games were played per weekday.
	 *
	 * Every weekday is present, even those with no game, so the chart keeps its
	 * full scale.
	 *
	 * @return	array<int,int>	Games count indexed by weekday, 1 = Monday
	 */
	public function getDistributionByWeekday(): array
	{
		// Initialise every slot so an empty weekday still shows on the chart
		$distribution = array();
		for ($day = 1; $day <= 7; $day++) {
			$distribution[$day] = 0;
		}

		// DAYOFWEEK() returns 1 for Sunday, so shift it to an ISO weekday
		$sql = "SELECT DAYOFWEEK(g.date_game) as weekday, COUNT(*) as nb";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= $this->baseWhere();
		$sql .= " GROUP BY DAYOFWEEK(g.date_game)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getDistributionByWeekday '.$this->db->lasterror(), LOG_ERR);
			return $distribution;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$isoDay = ((int) $obj->weekday === 1) ? 7 : ((int) $obj->weekday - 1);
			$distribution[$isoDay] = (int) $obj->nb;
		}
		$this->db->free($resql);

		return $distribution;
	}

	/**
	 * Return how many games were played per hour of the day.
	 *
	 * @return	array<int,int>	Games count indexed by hour, 0 to 23
	 */
	public function getDistributionByHour(): array
	{
		$distribution = array();
		for ($hour = 0; $hour <= 23; $hour++) {
			$distribution[$hour] = 0;
		}

		$sql = "SELECT HOUR(g.date_game) as hourofday, COUNT(*) as nb";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= $this->baseWhere();
		$sql .= " GROUP BY HOUR(g.date_game)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getDistributionByHour '.$this->db->lasterror(), LOG_ERR);
			return $distribution;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$distribution[(int) $obj->hourofday] = (int) $obj->nb;
		}
		$this->db->free($resql);

		return $distribution;
	}

	/**
	 * Return the most active player over the last days.
	 *
	 * @param	int			$days	Window in days
	 * @return	array|null			array{fk_user:int, nb:int}, or null when nobody played
	 */
	public function getMostActivePlayer(int $days = 30): ?array
	{
		$since = dol_now() - ($days * 24 * 3600);

		$sql = "SELECT gp.fk_user, COUNT(*) as nb";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player as gp";
		$sql .= " INNER JOIN ".$this->db->prefix()."babyfoot_game as g ON g.rowid = gp.fk_game";
		$sql .= $this->baseWhere();
		$sql .= " AND g.date_game >= '".$this->db->idate($since)."'";
		$sql .= " GROUP BY gp.fk_user";
		$sql .= " ORDER BY nb DESC, gp.fk_user ASC";
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getMostActivePlayer '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$row = null;
		if ($obj = $this->db->fetch_object($resql)) {
			$row = array('fk_user' => (int) $obj->fk_user, 'nb' => (int) $obj->nb);
		}
		$this->db->free($resql);

		return $row;
	}

	/**
	 * Return recent games ordered by their goal gap.
	 *
	 * @param	int		$limit		How many games to return
	 * @param	string	$direction	'ASC' for the tightest, 'DESC' for the widest
	 * @return	array				List of games
	 */
	private function getGamesByGap(int $limit, string $direction): array
	{
		$games = array();
		$order = ($direction === 'DESC') ? 'DESC' : 'ASC';

		$sql = "SELECT g.rowid, g.ref, g.date_game, g.mode, g.score_team1, g.score_team2,";
		$sql .= " g.winner_team, ABS(g.score_team1 - g.score_team2) as gap";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= $this->baseWhere();
		$sql .= " ORDER BY gap ".$order.", g.date_game DESC";
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GlobalStatsRepository::getGamesByGap '.$this->db->lasterror(), LOG_ERR);
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
				'gap' => (int) $obj->gap,
			);
		}
		$this->db->free($resql);

		return $games;
	}

	/**
	 * Return the WHERE clause shared by every aggregate.
	 *
	 * Filtering on the entity is mandatory here too: section 9 requires the
	 * statistics to stay separated between entities.
	 *
	 * @return	string	SQL fragment starting with WHERE
	 */
	private function baseWhere(): string
	{
		return " WHERE g.entity = ".((int) $this->entity)." AND g.status = ".((int) Game::STATUS_VALIDATED);
	}
}
