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
 * \file    class/stats/gamerepository.class.php
 * \ingroup babyfoot
 * \brief   Read only access to games with their players and player labels.
 */

/**
 * Loads games in two queries whatever their number: one for the games, one for
 * every player of those games with their user label.
 */
class GameRepository
{
	/** @var int Largest page search() returns */
	const MAX_LIMIT = 500;

	/** @var DoliDB Database handler */
	private $db;

	/** @var int Entity the games belong to: games are never shared between entities */
	private $entity;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$entity		Entity to read the games of
	 */
	public function __construct(DoliDB $db, int $entity)
	{
		$this->db = $db;
		$this->entity = $entity;
	}

	/**
	 * Search games.
	 *
	 * @param	array{mode?:string,status?:int,fk_user?:int,date_start?:int,date_end?:int,search?:string}	$filters	Filters, all optional
	 * @param	string	$sortfield		Sort field, on the alias t of the game table
	 * @param	string	$sortorder		'ASC' or 'DESC'
	 * @param	int		$limit			Page size, capped to MAX_LIMIT, 0 meaning MAX_LIMIT
	 * @param	int		$page			Page number, from 0
	 * @param	string	$extraSql		SQL fragment starting with AND, already sanitized by forgeSQLFromUniversalSearchCriteria()
	 * @return	array<int,array<string,mixed>>	Games with their players, in the requested order
	 * @throws	Exception	On a database error
	 */
	public function search(array $filters, string $sortfield = 't.date_game', string $sortorder = 'DESC', int $limit = 100, int $page = 0, string $extraSql = ''): array
	{
		$sql = "SELECT t.rowid FROM ".$this->db->prefix()."babyfoot_game AS t";
		$sql .= " WHERE t.entity = ".((int) $this->entity);
		if (!empty($filters['mode'])) {
			$sql .= " AND t.mode = '".$this->db->escape((string) $filters['mode'])."'";
		}
		if (isset($filters['status']) && (int) $filters['status'] >= 0) {
			$sql .= " AND t.status = ".((int) $filters['status']);
		}
		if (!empty($filters['fk_user'])) {
			$sql .= " AND EXISTS (SELECT gp.rowid FROM ".$this->db->prefix()."babyfoot_game_player AS gp";
			$sql .= " WHERE gp.fk_game = t.rowid AND gp.fk_user = ".((int) $filters['fk_user']).")";
		}
		if (!empty($filters['date_start'])) {
			$sql .= " AND t.date_game >= '".$this->db->idate((int) $filters['date_start'])."'";
		}
		if (!empty($filters['date_end'])) {
			$sql .= " AND t.date_game <= '".$this->db->idate((int) $filters['date_end'])."'";
		}
		if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
			$like = "'%".$this->db->escape($this->db->escapeforlike(trim((string) $filters['search'])))."%'";
			$sql .= " AND (t.ref LIKE ".$like;
			$sql .= " OR EXISTS (SELECT gps.rowid FROM ".$this->db->prefix()."babyfoot_game_player AS gps";
			$sql .= " INNER JOIN ".$this->db->prefix()."user AS us ON us.rowid = gps.fk_user";
			$sql .= " WHERE gps.fk_game = t.rowid";
			$sql .= " AND (us.lastname LIKE ".$like." OR us.firstname LIKE ".$like." OR us.login LIKE ".$like;
			$sql .= " OR CONCAT(us.firstname, ' ', us.lastname) LIKE ".$like.")))";
		}
		$sql .= $extraSql;
		$sql .= $this->db->order($sortfield, $sortorder);
		$limit = ($limit > 0) ? min($limit, self::MAX_LIMIT) : self::MAX_LIMIT;
		$sql .= $this->db->plimit($limit, $limit * max(0, $page));

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameRepository::search '.$this->db->lasterror(), LOG_ERR);
			throw new Exception('GameRepository::search failed');
		}
		$ids = [];
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		return $this->getByIds($ids);
	}

	/**
	 * Load games by id, keeping the order of the ids.
	 *
	 * @param	int[]	$ids	Game ids
	 * @return	array<int,array<string,mixed>>	Games with their players; unknown or foreign ids are skipped
	 * @throws	Exception	On a database error
	 */
	public function getByIds(array $ids): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (empty($ids)) {
			return [];
		}

		$sql = "SELECT t.rowid, t.ref, t.date_game, t.mode, t.score_team1, t.score_team2, t.winner_team,";
		$sql .= " t.status, t.fk_user_creat, t.date_creation,";
		$sql .= " uc.login AS creat_login, uc.firstname AS creat_firstname, uc.lastname AS creat_lastname";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game AS t";
		$sql .= " LEFT JOIN ".$this->db->prefix()."user AS uc ON uc.rowid = t.fk_user_creat";
		$sql .= " WHERE t.rowid IN (".implode(',', $ids).")";
		$sql .= " AND t.entity = ".((int) $this->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameRepository::getByIds '.$this->db->lasterror(), LOG_ERR);
			throw new Exception('GameRepository::getByIds failed');
		}
		$games = [];
		while ($obj = $this->db->fetch_object($resql)) {
			$games[(int) $obj->rowid] = [
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'date_game' => (int) $this->db->jdate($obj->date_game),
				'mode' => (string) $obj->mode,
				'score_team1' => (int) $obj->score_team1,
				'score_team2' => (int) $obj->score_team2,
				'winner_team' => is_null($obj->winner_team) ? null : (int) $obj->winner_team,
				'status' => (int) $obj->status,
				'fk_user_creat' => (int) $obj->fk_user_creat,
				'user_creat_name' => $this->label((int) $obj->fk_user_creat, $obj->creat_login, $obj->creat_firstname, $obj->creat_lastname)['name'],
				'date_creation' => (int) $this->db->jdate($obj->date_creation),
				'players' => [],
			];
		}
		$this->db->free($resql);

		if (empty($games)) {
			return [];
		}

		$sql = "SELECT gp.fk_game, gp.fk_user, gp.team, gp.is_winner, gp.elo_before, gp.elo_after, gp.elo_delta,";
		$sql .= " gp.elo_all_before, gp.elo_all_after, gp.elo_all_delta,";
		$sql .= " u.login, u.firstname, u.lastname";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game_player AS gp";
		$sql .= " LEFT JOIN ".$this->db->prefix()."user AS u ON u.rowid = gp.fk_user";
		$sql .= " WHERE gp.fk_game IN (".implode(',', array_keys($games)).")";
		$sql .= " ORDER BY gp.fk_game ASC, gp.team ASC, gp.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameRepository::getByIds players '.$this->db->lasterror(), LOG_ERR);
			throw new Exception('GameRepository::getByIds failed');
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$games[(int) $obj->fk_game]['players'][] = array_merge(
				$this->label((int) $obj->fk_user, $obj->login, $obj->firstname, $obj->lastname),
				[
					'team' => (int) $obj->team,
					'is_winner' => (int) $obj->is_winner,
					'elo_before' => (int) $obj->elo_before,
					'elo_after' => (int) $obj->elo_after,
					'elo_delta' => (int) $obj->elo_delta,
					'elo_all_before' => (int) $obj->elo_all_before,
					'elo_all_after' => (int) $obj->elo_all_after,
					'elo_all_delta' => (int) $obj->elo_all_delta,
				]
			);
		}
		$this->db->free($resql);

		$ordered = [];
		foreach ($ids as $id) {
			if (isset($games[$id])) {
				$ordered[] = $games[$id];
			}
		}

		return $ordered;
	}

	/**
	 * Resolve the labels of a set of users in one query.
	 *
	 * A user deleted from Dolibarr still gets a label, '#id', since their games
	 * keep counting in the ranking.
	 *
	 * @param	int[]	$userIds	User ids
	 * @return	array<int,array{fk_user:int,login:string,firstname:string,lastname:string,name:string}>	Labels indexed by user id
	 * @throws	Exception	On a database error
	 */
	public function getPlayerLabels(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		$labels = [];
		if (empty($userIds)) {
			return $labels;
		}

		$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".$this->db->prefix()."user AS u";
		$sql .= " WHERE u.rowid IN (".implode(',', $userIds).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('GameRepository::getPlayerLabels '.$this->db->lasterror(), LOG_ERR);
			throw new Exception('GameRepository::getPlayerLabels failed');
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$labels[(int) $obj->rowid] = $this->label((int) $obj->rowid, $obj->login, $obj->firstname, $obj->lastname);
		}
		$this->db->free($resql);

		foreach ($userIds as $userId) {
			if (!isset($labels[$userId])) {
				$labels[$userId] = $this->label($userId, null, null, null);
			}
		}

		return $labels;
	}

	/**
	 * Build the label of one player.
	 *
	 * @param	int			$userId		User id
	 * @param	string|null	$login		Login, null when the user no longer exists
	 * @param	string|null	$firstname	First name
	 * @param	string|null	$lastname	Last name
	 * @return	array{fk_user:int,login:string,firstname:string,lastname:string,name:string}	Label
	 */
	private function label(int $userId, $login, $firstname, $lastname): array
	{
		$name = trim(dolGetFirstLastname((string) $firstname, (string) $lastname));

		return [
			'fk_user' => $userId,
			'login' => (string) $login,
			'firstname' => (string) $firstname,
			'lastname' => (string) $lastname,
			'name' => ($name !== '') ? $name : (($login !== null && $login !== '') ? (string) $login : '#'.$userId),
		];
	}
}
