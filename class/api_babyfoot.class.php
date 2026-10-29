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
 * \brief   REST API of the babyfoot module: games, players, ranking and statistics.
 *
 * The API is another caller of Game, GameValidator, RatingEngine and the stats
 * repositories: it never implements a business rule of its own.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dirname(__FILE__).'/babyfootconfig.class.php';
require_once dirname(__FILE__).'/game.class.php';
require_once dirname(__FILE__).'/stats/gamerepository.class.php';
require_once dirname(__FILE__).'/stats/rankingrepository.class.php';
require_once dirname(__FILE__).'/stats/playerstatsrepository.class.php';
require_once dirname(__FILE__).'/stats/globalstatsrepository.class.php';

/**
 * REST API of the babyfoot module, served under /babyfoot.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Babyfoot extends DolibarrApi
{
	/** @var string[] Permissions of the module, as exposed by GET /me */
	const RIGHTS = ['read', 'create', 'modify_all', 'admin'];

	/** @var int Games returned in the last games of a player card */
	const PLAYER_LAST_GAMES = 10;

	/** @var int Rows returned by the duo and game gap tables of the statistics */
	const STATS_TOP_DUOS = 10;

	/** @var int Rows returned by the tightest and widest games tables */
	const STATS_GAP_GAMES = 5;

	/** @var string[] Columns GET /games may be sorted on */
	const GAME_SORT_FIELDS = ['t.rowid', 't.ref', 't.date_game', 't.mode', 't.status', 't.score_team1', 't.score_team2', 't.date_creation'];

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
	}

	/**
	 * Get the connected user, their babyfoot permissions and the module constants
	 *
	 * Needs no permission: a key restricted to the module cannot call /users/info.
	 *
	 * @return	array<string,mixed>	User, permissions and module information
	 *
	 * @url GET /me
	 *
	 * @throws	RestException	403 Module disabled
	 */
	public function getMe()
	{
		if (!isModEnabled('babyfoot')) {
			throw new RestException(403, 'Module babyfoot is disabled');
		}

		require_once dirname(__FILE__).'/../core/modules/modBabyfoot.class.php';

		$apiUser = DolibarrApiAccess::$user;
		$rights = [];
		foreach (self::RIGHTS as $right) {
			$rights[$right] = (bool) $apiUser->hasRight('babyfoot', $right);
		}
		$descriptor = new modBabyfoot($this->db);
		$config = BabyfootConfig::resolve();

		return [
			'id' => (int) $apiUser->id,
			'login' => (string) $apiUser->login,
			'firstname' => (string) $apiUser->firstname,
			'lastname' => (string) $apiUser->lastname,
			'name' => (string) dolGetFirstLastname((string) $apiUser->firstname, (string) $apiUser->lastname),
			'rights' => $rights,
			'module_version' => (string) $descriptor->version,
			'score_max' => BabyfootConfig::SCORE_MAX,
			'game_modes' => BabyfootConfig::gameModes(),
			'rating_modes' => BabyfootConfig::ratingModes(),
			'periods' => BabyfootConfig::periods(),
			'default_mode' => $config['default_mode'],
		];
	}

	/**
	 * List games, with their players and the players names
	 *
	 * @param	string	$sortfield	Sort field, or several separated by a comma, among t.rowid, t.ref, t.date_game, t.mode, t.status, t.score_team1, t.score_team2, t.date_creation
	 * @param	string	$sortorder	Sort order
	 * @param	int		$limit		Limit for list
	 * @param	int		$page		Page number
	 * @param	string	$mode		Game mode filter, '1v1' or '2v2'
	 * @param	int		$status		Status filter: 0 = cancelled, 1 = validated, -1 = all
	 * @param	int		$fk_user	Only the games this user played
	 * @param	int		$date_start	Only the games played at or after this timestamp
	 * @param	int		$date_end	Only the games played at or before this timestamp
	 * @param	string	$search		Text searched in the game ref and in the name or login of its players
	 * @param	string	$sqlfilters	Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'BF2610-%')"
	 * @return	array<int,array<string,mixed>>	List of games
	 *
	 * @url GET /games
	 *
	 * @throws	RestException	400 Invalid filter
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	503 Database error
	 */
	public function listGames($sortfield = 't.date_game', $sortorder = 'DESC', $limit = 100, $page = 0, $mode = '', $status = -1, $fk_user = 0, $date_start = 0, $date_end = 0, $search = '', $sqlfilters = '')
	{
		$this->requireRight('read');
		if ($mode !== '' && !in_array($mode, BabyfootConfig::gameModes(), true)) {
			throw new RestException(400, 'Unknown mode '.$mode);
		}
		foreach (explode(',', (string) $sortfield) as $field) {
			if (!in_array(trim($field), self::GAME_SORT_FIELDS, true)) {
				throw new RestException(400, 'Unknown sortfield '.trim($field).', expected one of '.implode(', ', self::GAME_SORT_FIELDS));
			}
		}

		$extraSql = '';
		if ($sqlfilters) {
			$errormessage = '';
			$extraSql = forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$filters = [
			'mode' => $mode,
			'status' => (int) $status,
			'fk_user' => (int) $fk_user,
			'date_start' => (int) $date_start,
			'date_end' => (int) $date_end,
			'search' => (string) $search,
		];

		try {
			return $this->gameRepository()->search($filters, (string) $sortfield, (string) $sortorder, (int) $limit, (int) $page, $extraSql);
		} catch (Exception $e) {
			throw new RestException(503, 'Error when retrieving the game list');
		}
	}

	/**
	 * Get a game with its players, their names and their Elo changes
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,mixed>	Game
	 *
	 * @url GET /games/{id}
	 *
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	503 Database error
	 */
	public function getGame($id)
	{
		$this->requireRight('read');

		return $this->readGameOrFail((int) $id);
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
		$this->requireRight('create');
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
		$this->requireRight('modify_all');
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

		return $this->readGameOrFail((int) $id);
	}

	/**
	 * Cancel a game: it stays in the history but no longer counts
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,mixed>	Cancelled game
	 *
	 * @url POST /games/{id}/cancel
	 *
	 * @throws	RestException	400 Game already cancelled
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	500 Error while saving
	 */
	public function cancelGame($id)
	{
		$this->requireRight('modify_all');

		$game = $this->fetchGameOrFail((int) $id);
		if ((int) $game->status !== Game::STATUS_VALIDATED) {
			throw new RestException(400, 'Game is already cancelled');
		}
		if ($game->cancel(DolibarrApiAccess::$user) <= 0) {
			$this->failOnGameError($game);
		}

		return $this->readGameOrFail((int) $id);
	}

	/**
	 * Reopen a cancelled game: it counts again
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,mixed>	Reopened game
	 *
	 * @url POST /games/{id}/reopen
	 *
	 * @throws	RestException	400 Game is not cancelled
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	500 Error while saving
	 */
	public function reopenGame($id)
	{
		$this->requireRight('modify_all');

		$game = $this->fetchGameOrFail((int) $id);
		if ((int) $game->status !== Game::STATUS_CANCELED) {
			throw new RestException(400, 'Game is not cancelled');
		}
		if ($game->reopen(DolibarrApiAccess::$user) <= 0) {
			$this->failOnGameError($game);
		}

		return $this->readGameOrFail((int) $id);
	}

	/**
	 * Delete a game for good
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,array<string,mixed>>	Success message
	 *
	 * @url DELETE /games/{id}
	 *
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	500 Error while deleting
	 */
	public function deleteGame($id)
	{
		$this->requireRight('modify_all');

		$game = $this->fetchGameOrFail((int) $id);
		if ($game->delete(DolibarrApiAccess::$user) <= 0) {
			$this->failOnGameError($game);
		}

		return ['success' => ['code' => 200, 'message' => 'Game '.$game->ref.' deleted']];
	}

	/**
	 * List the players: every active internal user, including those who never played
	 *
	 * elo is null for a user who never played.
	 *
	 * @return	array<int,array<string,mixed>>	Players sorted by name
	 *
	 * @url GET /players
	 *
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	503 Database error
	 */
	public function listPlayers()
	{
		$this->requireRight('read');

		try {
			$rows = $this->rankingRepository()->getActiveInternalPlayers(getEntity('user'));
		} catch (Exception $e) {
			throw new RestException(503, 'Error when retrieving the players');
		}

		foreach ($rows as $index => $row) {
			$rows[$index]['name'] = (string) dolGetFirstLastname($row['firstname'], $row['lastname']);
		}

		return $rows;
	}

	/**
	 * Get a player card: ratings per mode, teammates and opponents, last games
	 *
	 * @param	int		$id		Id of the Dolibarr user
	 * @return	array<string,mixed>	Player card
	 *
	 * @url GET /players/{id}
	 *
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Player not found
	 * @throws	RestException	503 Database error
	 */
	public function getPlayer($id)
	{
		$this->requireRight('read');

		$id = (int) $id;
		$stats = new PlayerStatsRepository($this->db, $this->entity());
		$ratings = $stats->getRatings($id);
		$this->requirePlayer($id, $ratings);

		$relations = [
			'best_teammate' => $stats->getBestTeammate($id),
			'nemesis' => $stats->getNemesis($id),
			'favourite_victim' => $stats->getFavouriteVictim($id),
		];

		$userIds = [$id];
		foreach ($relations as $relation) {
			if (!is_null($relation)) {
				$userIds[] = (int) $relation['fk_user'];
			}
		}
		$labels = $this->playerLabels($userIds);

		$card = [
			'player' => $labels[$id],
			'ratings' => (object) $ratings,
		];
		foreach ($relations as $key => $relation) {
			$card[$key] = is_null($relation) ? null : array_merge($labels[(int) $relation['fk_user']], $relation);
		}
		$card['last_games'] = $stats->getLastGames($id, self::PLAYER_LAST_GAMES);

		return $card;
	}

	/**
	 * Get the Elo history of a player, one point per game, oldest first
	 *
	 * @param	int		$id		Id of the Dolibarr user
	 * @param	string	$mode	'1v1', '2v2' or 'all'
	 * @return	array<int,array{date:int,elo:int}>	Elo after each game
	 *
	 * @url GET /players/{id}/elo-history
	 *
	 * @throws	RestException	400 Unknown mode
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	404 Player not found
	 */
	public function getPlayerEloHistory($id, $mode = 'all')
	{
		$this->requireRight('read');
		if (!in_array($mode, BabyfootConfig::ratingModes(), true)) {
			throw new RestException(400, 'Unknown mode '.$mode);
		}

		$id = (int) $id;
		$stats = new PlayerStatsRepository($this->db, $this->entity());
		$this->requirePlayer($id, $stats->getRatings($id));

		return $stats->getEloHistory($id, (string) $mode);
	}

	/**
	 * Get the ranking of one mode
	 *
	 * With a period, the counters cover the period while elo stays the current
	 * one, elo_variation giving its change over the period.
	 *
	 * @param	string	$mode		'1v1', '2v2' or 'all'
	 * @param	string	$period		'30', '90', '365' (days) or 'all'
	 * @return	array<int,array<string,mixed>>	Ranked players, best first
	 *
	 * @url GET /ranking
	 *
	 * @throws	RestException	400 Unknown mode or period
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	503 Database error
	 */
	public function getRanking($mode = 'all', $period = 'all')
	{
		$this->requireRight('read');
		if (!in_array($mode, BabyfootConfig::ratingModes(), true)) {
			throw new RestException(400, 'Unknown mode '.$mode);
		}
		$dateFrom = $this->periodStartOrFail((string) $period);

		$repository = $this->rankingRepository();
		$ranking = $repository->getRanking((string) $mode, 0, $dateFrom, 0);
		$variations = $repository->getRankVariationSinceLastGame((string) $mode, $ranking);

		// Decision D11: the streak shown is always the overall one, whatever the mode
		$overallStreaks = [];
		if ($mode !== BabyfootConfig::MODE_ALL) {
			foreach ($repository->getRanking(BabyfootConfig::MODE_ALL, 0, 0, 0) as $row) {
				$overallStreaks[(int) $row['fk_user']] = (int) $row['current_streak'];
			}
		}

		$labels = $this->playerLabels(array_column($ranking, 'fk_user'));

		$result = [];
		foreach ($ranking as $position => $row) {
			$userId = (int) $row['fk_user'];
			if (isset($overallStreaks[$userId])) {
				$row['current_streak'] = $overallStreaks[$userId];
			}
			$result[] = array_merge(
				['rank' => $position + 1, 'rank_variation' => isset($variations[$userId]) ? (int) $variations[$userId] : 0],
				$labels[$userId],
				$row
			);
		}

		return $result;
	}

	/**
	 * Get the collective statistics
	 *
	 * Without a period, most_active_player covers the last 30 days.
	 *
	 * @param	string	$period		'30', '90', '365' (days) or 'all'
	 * @return	array<string,mixed>	Statistics
	 *
	 * @url GET /stats
	 *
	 * @throws	RestException	400 Unknown period
	 * @throws	RestException	403 Access denied
	 * @throws	RestException	503 Database error
	 */
	public function getStats($period = 'all')
	{
		$this->requireRight('read');
		$dateFrom = $this->periodStartOrFail((string) $period);

		$repository = new GlobalStatsRepository($this->db, $this->entity());
		$mostActiveFrom = ($dateFrom > 0) ? $dateFrom : (dol_now() - BabyfootConfig::MOST_ACTIVE_DEFAULT_DAYS * 24 * 3600);
		$mostActive = $repository->getMostActivePlayer($mostActiveFrom);
		$duos = $repository->getTopDuos(self::STATS_TOP_DUOS, $dateFrom);
		$fannies = $repository->getFannyTable($dateFrom);

		$userIds = array_merge(array_column($duos, 'user1'), array_column($duos, 'user2'), array_column($fannies, 'fk_user'));
		if (!is_null($mostActive)) {
			$userIds[] = (int) $mostActive['fk_user'];
		}
		$labels = $this->playerLabels($userIds);

		foreach ($duos as $index => $duo) {
			$duos[$index]['player1'] = $labels[(int) $duo['user1']];
			$duos[$index]['player2'] = $labels[(int) $duo['user2']];
		}
		foreach ($fannies as $index => $fanny) {
			$fannies[$index] = array_merge($labels[(int) $fanny['fk_user']], $fanny);
		}

		$byWeekday = [];
		foreach ($repository->getDistributionByWeekday($dateFrom) as $weekday => $nb) {
			$byWeekday[] = ['weekday' => (int) $weekday, 'nb' => (int) $nb];
		}
		$byHour = [];
		foreach ($repository->getDistributionByHour($dateFrom) as $hour => $nb) {
			$byHour[] = ['hour' => (int) $hour, 'nb' => (int) $nb];
		}

		return [
			'period' => (string) $period,
			'date_start' => $dateFrom,
			'totals' => $repository->getTotals($dateFrom),
			'most_active_player' => is_null($mostActive) ? null : array_merge($labels[(int) $mostActive['fk_user']], $mostActive),
			'most_active_since' => $mostActiveFrom,
			'top_duos' => $duos,
			'tightest_games' => $repository->getTightestGames(self::STATS_GAP_GAMES, $dateFrom),
			'widest_games' => $repository->getWidestGames(self::STATS_GAP_GAMES, $dateFrom),
			'fannies' => $fannies,
			'by_weekday' => $byWeekday,
			'by_hour' => $byHour,
		];
	}

	/**
	 * Refuse the call when the API user lacks a babyfoot permission.
	 *
	 * @param	string	$right	Permission code
	 * @return	void
	 *
	 * @throws	RestException	403 Access denied
	 */
	private function requireRight(string $right): void
	{
		if (!DolibarrApiAccess::$user->hasRight('babyfoot', $right)) {
			throw new RestException(403);
		}
	}

	/**
	 * Refuse an id that is neither a ranked player of the entity nor one of its users.
	 *
	 * A deleted user keeps their card as long as their games count in the ranking.
	 *
	 * @param	int		$id			Id of the Dolibarr user
	 * @param	array	$ratings	Ranking rows of the player in the current entity
	 * @return	void
	 *
	 * @throws	RestException	404 Player not found
	 * @throws	RestException	503 Database error
	 */
	private function requirePlayer(int $id, array $ratings): void
	{
		if (!empty($ratings)) {
			return;
		}
		try {
			$found = $this->rankingRepository()->findUserId(getEntity('user'), $id, '', false);
		} catch (Exception $e) {
			throw new RestException(503, 'Error when retrieving the player');
		}
		if ($found <= 0) {
			throw new RestException(404, 'Player not found');
		}
	}

	/**
	 * Resolve a period code into its start timestamp.
	 *
	 * @param	string	$period		Period code
	 * @return	int					Start timestamp, 0 for the whole history
	 *
	 * @throws	RestException	400 Unknown period
	 */
	private function periodStartOrFail(string $period): int
	{
		if (!in_array($period, BabyfootConfig::periods(), true)) {
			throw new RestException(400, 'Unknown period '.$period.', expected one of '.implode(', ', BabyfootConfig::periods()));
		}

		return BabyfootConfig::periodStart($period);
	}

	/**
	 * Resolve player labels, mapping a database error to a 503.
	 *
	 * @param	int[]	$userIds	User ids
	 * @return	array<int,array<string,mixed>>	Labels indexed by user id
	 *
	 * @throws	RestException	503 Database error
	 */
	private function playerLabels(array $userIds): array
	{
		try {
			return $this->gameRepository()->getPlayerLabels($userIds);
		} catch (Exception $e) {
			throw new RestException(503, 'Error when retrieving the player names');
		}
	}

	/**
	 * Read one game through the repository, with player names.
	 *
	 * @param	int		$id		Id of the game
	 * @return	array<string,mixed>	Game
	 *
	 * @throws	RestException	404 Game not found
	 * @throws	RestException	503 Database error
	 */
	private function readGameOrFail(int $id): array
	{
		try {
			$games = $this->gameRepository()->getByIds([$id]);
		} catch (Exception $e) {
			throw new RestException(503, 'Error when retrieving the game');
		}
		if (empty($games)) {
			throw new RestException(404, 'Game not found');
		}

		return $games[0];
	}

	/**
	 * Load a game of the current entity with its players, for a write.
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
		if ($result === 0 || (int) $game->entity !== $this->entity()) {
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
	 * Only the active internal users of the entity can play, as on the quick entry screen.
	 *
	 * @param	mixed	$players	Players of the request
	 * @return	array<int,array{fk_user:int,team:int}>	Resolved players
	 *
	 * @throws	RestException	400 Invalid player
	 * @throws	RestException	503 Database error
	 */
	private function resolvePlayers($players): array
	{
		if (!is_array($players) || empty($players)) {
			throw new RestException(400, 'players must be a non empty list');
		}

		$result = [];
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

			try {
				$userId = $this->rankingRepository()->findUserId(getEntity('user'), $fkUser, $login, true);
			} catch (Exception $e) {
				throw new RestException(503, 'Error when retrieving players['.$index.']');
			}
			if ($userId <= 0) {
				throw new RestException(400, 'players['.$index.'] is not an active internal user of this entity');
			}

			$result[] = ['fk_user' => $userId, 'team' => $team];
		}

		return $result;
	}

	/**
	 * Raise the exception matching the failure of a write on a game.
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
			$messages = [];
			foreach ($game->validationErrors as $errorKey) {
				$messages[] = $errorKey.': '.$langs->transnoentitiesnoconv($errorKey, BabyfootConfig::SCORE_MAX);
			}
			throw new RestException(400, implode(' | ', $messages));
		}

		$error = !empty($game->error) ? $game->error : 'BabyfootErrValidation';
		throw new RestException(500, $error.': '.$langs->transnoentitiesnoconv($error));
	}

	/**
	 * @return	int		Entity the statistics are computed on
	 */
	private function entity(): int
	{
		global $conf;

		return (int) $conf->entity;
	}

	/**
	 * @return	GameRepository	Game reader on the current entity
	 */
	private function gameRepository(): GameRepository
	{
		return new GameRepository($this->db, $this->entity());
	}

	/**
	 * @return	RankingRepository	Ranking reader on the current entity
	 */
	private function rankingRepository(): RankingRepository
	{
		return new RankingRepository($this->db, $this->entity());
	}
}
