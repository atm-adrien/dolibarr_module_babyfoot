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
 * \file    class/api_babyfoot.class.php
 * \ingroup babyfoot
 * \brief   REST API of the babyfoot module: list, read, record and correct games.
 *
 * The API is another caller of Game, GameValidator and RatingEngine: it never
 * implements a business rule of its own.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dirname(__FILE__).'/babyfootconfig.class.php';
require_once dirname(__FILE__).'/game.class.php';

/**
 * REST API of the babyfoot module, served under /babyfoot.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Babyfoot extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
	}

	/**
	 * List games
	 *
	 * @param	string	$sortfield	Sort field
	 * @param	string	$sortorder	Sort order
	 * @param	int		$limit		Limit for list
	 * @param	int		$page		Page number
	 * @param	string	$mode		Game mode filter, '1v1' or '2v2'
	 * @param	int		$status		Status filter: 0 = cancelled, 1 = validated, -1 = all
	 * @param	int		$fk_user	Only the games this user played
	 * @param	int		$date_start	Only the games played at or after this timestamp
	 * @param	int		$date_end	Only the games played at or before this timestamp
	 * @param	string	$sqlfilters	Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'BF2610-%')"
	 * @return	array<int,array<string,mixed>>	List of games
	 *
	 * @url GET /games
	 *
	 * @throws	RestException	400 Invalid filter
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	503 Database error
	 */
	public function listGames($sortfield = 't.date_game', $sortorder = 'DESC', $limit = 100, $page = 0, $mode = '', $status = -1, $fk_user = 0, $date_start = 0, $date_end = 0, $sqlfilters = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('babyfoot', 'read')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game AS t";
		$sql .= " WHERE t.entity IN (".getEntity('babyfootgame').")";
		if ($mode !== '') {
			if (!in_array($mode, BabyfootConfig::gameModes(), true)) {
				throw new RestException(400, 'Unknown mode '.$mode);
			}
			$sql .= " AND t.mode = '".$this->db->escape($mode)."'";
		}
		if ((int) $status >= 0) {
			$sql .= " AND t.status = ".((int) $status);
		}
		if ((int) $fk_user > 0) {
			$sql .= " AND EXISTS (SELECT gp.rowid FROM ".$this->db->prefix()."babyfoot_game_player AS gp";
			$sql .= " WHERE gp.fk_game = t.rowid AND gp.fk_user = ".((int) $fk_user).")";
		}
		if ((int) $date_start > 0) {
			$sql .= " AND t.date_game >= '".$this->db->idate((int) $date_start)."'";
		}
		if ((int) $date_end > 0) {
			$sql .= " AND t.date_game <= '".$this->db->idate((int) $date_end)."'";
		}
		if ($sqlfilters) {
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			$page = max(0, (int) $page);
			$sql .= $this->db->plimit((int) $limit, (int) $limit * $page);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('Babyfoot::listGames '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Error when retrieving the game list');
		}

		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$result = array();
		foreach ($ids as $id) {
			$result[] = $this->formatGame($this->fetchGameOrFail($id));
		}

		return $result;
	}

	/**
	 * Get a game with its players and their Elo changes
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,mixed>	Game
	 *
	 * @url GET /games/{id}
	 *
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 */
	public function getGame($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('babyfoot', 'read')) {
			throw new RestException(403);
		}

		return $this->formatGame($this->fetchGameOrFail((int) $id));
	}

	/**
	 * Record a game
	 *
	 * Each player is given as {"fk_user": 12, "team": 1} or {"login": "jdoe", "team": 2};
	 * fk_user wins when both are given. date_game is a timestamp, now when omitted.
	 *
	 * @param	array	$request_data	{"mode": "2v2", "score_team1": 10, "score_team2": 7, "date_game": 1793000000, "players": [...]}
	 * @return	int						Id of the new game
	 *
	 * @url POST /games
	 *
	 * @throws	RestException	400 Invalid game
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	500 Error while saving
	 */
	public function postGame($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('babyfoot', 'create')) {
			throw new RestException(403);
		}
		if (!is_array($request_data)) {
			throw new RestException(400, 'Missing request data');
		}

		$game = new Game($this->db);
		$game->mode = isset($request_data['mode']) ? (string) $request_data['mode'] : '';
		$game->score_team1 = isset($request_data['score_team1']) ? (int) $request_data['score_team1'] : 0;
		$game->score_team2 = isset($request_data['score_team2']) ? (int) $request_data['score_team2'] : 0;
		$game->date_game = !empty($request_data['date_game']) ? (int) $request_data['date_game'] : dol_now();
		$game->setPlayers($this->resolvePlayers(isset($request_data['players']) ? $request_data['players'] : null));

		$id = $game->create(DolibarrApiAccess::$user);
		if ($id <= 0) {
			$this->failOnGameError($game);
		}

		return (int) $id;
	}

	/**
	 * Correct a game: scores, date and players
	 *
	 * Only the given fields change. Giving players replaces all of them. The whole
	 * ranking is rebuilt afterwards.
	 *
	 * @param	int		$id				Id of the game
	 * @param	array	$request_data	{"score_team1": 10, "score_team2": 8, "date_game": 1793000000, "players": [...]}
	 * @return	array<string,mixed>		Updated game
	 *
	 * @url PUT /games/{id}
	 *
	 * @throws	RestException	400 Invalid game
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	500 Error while saving
	 */
	public function putGame($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('babyfoot', 'modify_all')) {
			throw new RestException(403);
		}
		if (!is_array($request_data)) {
			throw new RestException(400, 'Missing request data');
		}

		$game = $this->fetchGameOrFail((int) $id);

		if (isset($request_data['score_team1'])) {
			$game->score_team1 = (int) $request_data['score_team1'];
		}
		if (isset($request_data['score_team2'])) {
			$game->score_team2 = (int) $request_data['score_team2'];
		}
		if (!empty($request_data['date_game'])) {
			$game->date_game = (int) $request_data['date_game'];
		}
		if (isset($request_data['players'])) {
			$game->setPlayers($this->resolvePlayers($request_data['players']));
		}

		if ($game->update(DolibarrApiAccess::$user) <= 0) {
			$this->failOnGameError($game);
		}

		return $this->formatGame($this->fetchGameOrFail((int) $id));
	}

	/**
	 * Load a game of a visible entity with its players.
	 *
	 * @param	int		$id		Id of the game
	 * @return	Game			Loaded game
	 *
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	503 Database error
	 */
	private function fetchGameOrFail(int $id): Game
	{
		$game = new Game($this->db);
		$result = $game->fetch($id);
		if ($result < 0) {
			throw new RestException(503, 'Error when retrieving the game');
		}
		if ($result === 0 || !in_array((int) $game->entity, explode(',', getEntity('babyfootgame')))) {
			throw new RestException(404, 'Game not found');
		}
		if ($game->fetchLines() < 0) {
			throw new RestException(503, 'Error when retrieving the players of the game');
		}

		return $game;
	}

	/**
	 * Turn the players of a request into the array expected by Game::setPlayers().
	 *
	 * @param	mixed	$players	Players of the request
	 * @return	array<int,array{fk_user:int,team:int}>	Resolved players
	 *
	 * @throws	RestException	400 Invalid player
	 */
	private function resolvePlayers($players): array
	{
		if (!is_array($players) || empty($players)) {
			throw new RestException(400, 'players must be a non empty list');
		}

		$result = array();
		foreach ($players as $index => $player) {
			if (!is_array($player)) {
				throw new RestException(400, 'players['.$index.'] must be an object');
			}

			$team = isset($player['team']) ? (int) $player['team'] : 0;
			if ($team !== 1 && $team !== 2) {
				throw new RestException(400, 'players['.$index.'].team must be 1 or 2');
			}

			$fkUser = !empty($player['fk_user']) ? (int) $player['fk_user'] : 0;
			$login = !empty($player['login']) ? (string) $player['login'] : '';
			if ($fkUser <= 0 && $login === '') {
				throw new RestException(400, 'players['.$index.'] needs fk_user or login');
			}

			$playerUser = new User($this->db);
			$fetched = ($fkUser > 0) ? $playerUser->fetch($fkUser) : $playerUser->fetch(0, $login);
			if ($fetched < 0) {
				throw new RestException(503, 'Error when retrieving players['.$index.']');
			}
			if ($fetched === 0 || empty($playerUser->id)) {
				throw new RestException(400, 'players['.$index.'] is not a known user');
			}

			$result[] = array('fk_user' => (int) $playerUser->id, 'team' => $team);
		}

		return $result;
	}

	/**
	 * Raise the exception matching the failure of Game::create() or Game::update().
	 *
	 * @param	Game	$game	Game that failed to save
	 * @return	never
	 *
	 * @throws	RestException	400 Business rule broken, 500 otherwise
	 */
	private function failOnGameError(Game $game)
	{
		global $langs;

		$langs->load('babyfoot@babyfoot');

		if (!empty($game->validationErrors)) {
			$messages = array();
			foreach ($game->validationErrors as $errorKey) {
				$messages[] = $errorKey.': '.$langs->transnoentitiesnoconv($errorKey, BabyfootConfig::SCORE_MAX);
			}
			throw new RestException(400, implode(' | ', $messages));
		}

		$error = !empty($game->error) ? $game->error : 'BabyfootErrValidation';
		throw new RestException(500, $error.': '.$langs->transnoentitiesnoconv($error));
	}

	/**
	 * Expose a game as a plain array, without the internals of CommonObject.
	 *
	 * @param	Game	$game	Loaded game with its players
	 * @return	array<string,mixed>	Game data
	 */
	private function formatGame(Game $game): array
	{
		$players = array();
		if (is_array($game->lines) && !empty($game->lines)) {
			foreach ($game->lines as $line) {
				$players[] = array(
					'fk_user' => (int) $line->fk_user,
					'team' => (int) $line->team,
					'is_winner' => (int) $line->is_winner,
					'elo_before' => (int) $line->elo_before,
					'elo_after' => (int) $line->elo_after,
					'elo_delta' => (int) $line->elo_delta,
					'elo_all_before' => (int) $line->elo_all_before,
					'elo_all_after' => (int) $line->elo_all_after,
					'elo_all_delta' => (int) $line->elo_all_delta,
				);
			}
		}

		return array(
			'id' => (int) $game->id,
			'ref' => (string) $game->ref,
			'date_game' => (int) $game->date_game,
			'mode' => (string) $game->mode,
			'score_team1' => (int) $game->score_team1,
			'score_team2' => (int) $game->score_team2,
			'winner_team' => ($game->winner_team === null || $game->winner_team === '') ? null : (int) $game->winner_team,
			'status' => (int) $game->status,
			'fk_user_creat' => (int) $game->fk_user_creat,
			'date_creation' => (int) $game->date_creation,
			'players' => $players,
		);
	}
}
