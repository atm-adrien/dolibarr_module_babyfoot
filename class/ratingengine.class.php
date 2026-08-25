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
 * \file    class/ratingengine.class.php
 * \ingroup babyfoot
 * \brief   Orchestration of the Elo ranking: incremental update and full rebuild.
 */

require_once dirname(__FILE__).'/babyfootconfig.class.php';
require_once dirname(__FILE__).'/elocalculator.class.php';
require_once dirname(__FILE__).'/rating.class.php';
require_once dirname(__FILE__).'/ratingset.class.php';
require_once dirname(__FILE__).'/gameplayer.class.php';
require_once dirname(__FILE__).'/game.class.php';

/**
 * Applies games on the ranking table.
 *
 * This is the only class allowed to write into babyfoot_rating and into the
 * elo_* columns of babyfoot_game_player.
 *
 * There are deliberately only two code paths: an incremental update when the
 * game continues the timeline, and a full rebuild otherwise. No partial
 * recompute exists, which removes an entire class of divergence bugs.
 */
class RatingEngine
{
	/** @var string Mode of the sentinel row used as a module wide write lock */
	const LOCK_MODE = '__lock__';

	/** @var int Number of games loaded per page during a full rebuild */
	const REPLAY_PAGE_SIZE = 500;

	/** @var DoliDB Database handler */
	private $db;

	/** @var int Entity to work on */
	private $entity;

	/** @var array Resolved settings */
	private $config;

	/** @var EloCalculator Pure Elo computation */
	private $calculator;

	/** @var string Last error message */
	public $error = '';

	/**
	 * Constructor
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	int			$entity		Entity to work on
	 * @param	array|null	$config		Resolved settings, resolved again when null
	 */
	public function __construct(DoliDB $db, int $entity, ?array $config = null)
	{
		$this->db = $db;
		$this->entity = $entity;
		$this->config = is_null($config) ? BabyfootConfig::resolve() : $config;
		$this->calculator = BabyfootConfig::createCalculator($this->config);
	}

	/**
	 * Acquire the module wide write lock.
	 *
	 * Every writer takes this sentinel row FOR UPDATE inside its own transaction,
	 * so two concurrent entries can never read the same Elo and overwrite each
	 * other. Portable on MySQL/InnoDB and on PostgreSQL, unlike GET_LOCK().
	 *
	 * @param	int		$depth	Internal recursion guard
	 * @return	int				1 if OK, -1 if KO
	 */
	public function acquireLock(int $depth = 0): int
	{
		if ($depth > 1) {
			$this->error = 'BabyfootErrLockFailed';
			dol_syslog('RatingEngine::acquireLock could not obtain the sentinel row', LOG_ERR);
			return -1;
		}

		$sql = "SELECT r.rowid FROM ".$this->db->prefix()."babyfoot_rating as r";
		$sql .= " WHERE r.entity = ".((int) $this->entity);
		$sql .= " AND r.fk_user = 0";
		$sql .= " AND r.mode = '".$this->db->escape(self::LOCK_MODE)."'";
		$sql .= " FOR UPDATE";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'BabyfootErrLockFailed';
			dol_syslog('RatingEngine::acquireLock '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}
		$found = $this->db->num_rows($resql);
		$this->db->free($resql);

		if ($found === 0) {
			// First writer ever: create the sentinel row, then take it
			$sql = "INSERT INTO ".$this->db->prefix()."babyfoot_rating (entity, fk_user, mode, elo, elo_peak)";
			$sql .= " VALUES (".((int) $this->entity).", 0, '".$this->db->escape(self::LOCK_MODE)."', 0, 0)";
			if (!$this->db->query($sql)) {
				// A concurrent writer created it at the same time: not an error
				dol_syslog('RatingEngine::acquireLock sentinel row already created concurrently', LOG_DEBUG);
			}

			return $this->acquireLock($depth + 1);
		}

		return 1;
	}

	/**
	 * Tell whether a stored game is the most recent validated one (RG-31).
	 *
	 * @param	Game	$game	Game to test, already stored
	 * @return	bool			True when no validated game is more recent
	 */
	public function isLatest(Game $game): bool
	{
		return $this->noGameNewerThan((int) $game->date_game, (int) $game->id);
	}

	/**
	 * Tell whether a date would land at the end of the timeline.
	 *
	 * Meant to be called BEFORE storing a game, so the entry screen can warn the
	 * user that saving is about to adjust the ranking (section 9). Once stored,
	 * the game is part of the timeline and isLatest() must be used instead.
	 *
	 * @param	int		$dateGame	Timestamp of the game about to be stored
	 * @return	bool				True when no validated game is more recent
	 */
	public function isLatestDate(int $dateGame): bool
	{
		return $this->noGameNewerThan($dateGame, 0);
	}

	/**
	 * Tell whether no validated game is more recent than the given position.
	 *
	 * The comparison follows the replay order of section 8.3: date_game first,
	 * then rowid. A rowid of 0 means the game does not exist yet, so only the
	 * date is compared.
	 *
	 * @param	int		$dateGame	Timestamp to compare against
	 * @param	int		$gameId		Rowid of the game, 0 when it is not stored yet
	 * @return	bool				True when nothing is more recent
	 */
	private function noGameNewerThan(int $dateGame, int $gameId): bool
	{
		$date = $this->db->idate($dateGame);

		$sql = "SELECT g.rowid";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		if ($gameId > 0) {
			$sql .= " AND g.rowid <> ".((int) $gameId);
			$sql .= " AND (g.date_game > '".$date."'";
			$sql .= " OR (g.date_game = '".$date."' AND g.rowid > ".((int) $gameId)."))";
		} else {
			// Not stored yet: it will get the highest rowid, so an equal date still
			// leaves it at the end of the timeline
			$sql .= " AND g.date_game > '".$date."'";
		}
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RatingEngine::noGameNewerThan '.$this->db->lasterror(), LOG_ERR);
			// Safest answer: pretend it is not the latest, which forces a full rebuild
			return false;
		}
		$isLatest = ($this->db->num_rows($resql) === 0);
		$this->db->free($resql);

		return $isLatest;
	}

	/**
	 * Entry point called after a game has been created (RG-30, RG-31).
	 *
	 * @param	Game	$game	Game just created, with its participants loaded
	 * @return	int				1 if OK, -1 if KO
	 */
	public function onGameCreated(Game $game): int
	{
		global $user;

		if ($this->acquireLock() < 0) {
			return -1;
		}

		if (!$this->isLatest($game)) {
			// RG-30 and decision D4: a backdated game invalidates the whole chain
			dol_syslog('RatingEngine::onGameCreated backdated game '.$game->id.', running a full rebuild', LOG_NOTICE);
			return $this->rebuild();
		}

		$userIds = array();
		foreach ($game->lines as $line) {
			$userIds[] = (int) $line->fk_user;
		}

		$set = new RatingSet($this->db, $this->entity, (int) $this->config['elo_initial']);
		if ($set->loadForUsers($userIds, true) < 0) {
			$this->error = 'BabyfootErrRecomputeFailed';
			return -1;
		}
		if ($this->applyGame($game, $set) < 0) {
			return -1;
		}
		if ($set->flush($user) < 0) {
			$this->error = 'BabyfootErrRecomputeFailed';
			return -1;
		}

		return 1;
	}

	/**
	 * Entry point called after a game was updated, cancelled, reopened or deleted.
	 *
	 * There is deliberately no partial recompute (decision D4): any change that
	 * is not the strict continuation of the timeline triggers a full rebuild. One
	 * algorithm instead of two, for an identical result.
	 *
	 * @param	Game	$game				Game that changed
	 * @param	int		$previousDateGame	Former date_game, 0 when unchanged
	 * @return	int							1 if OK, -1 if KO
	 */
	public function onGameChanged(Game $game, int $previousDateGame = 0): int
	{
		if ($this->acquireLock() < 0) {
			return -1;
		}

		return $this->rebuild();
	}

	/**
	 * Rebuild the whole ranking table from the games (section 8.3).
	 *
	 * Every ranking row is dropped, then all validated games are replayed in a
	 * deterministic order: date_game first, then rowid, so two games sharing the
	 * same second are always processed in the same sequence. The Elo state is
	 * held in memory during the replay, so the cost stays linear.
	 *
	 * @return	array{nb_games:int,duration:float}	Games replayed and elapsed seconds
	 * @throws	Exception							On failure, after the transaction was rolled back
	 */
	public function recomputeAll(): array
	{
		$started = microtime(true);

		$this->db->begin();

		if ($this->acquireLock() < 0) {
			$this->db->rollback();
			throw new Exception('BabyfootErrLockFailed');
		}

		$result = $this->replayEverything(true);
		if ($result < 0) {
			$this->db->rollback();
			throw new Exception($this->error !== '' ? $this->error : 'BabyfootErrRecomputeFailed');
		}

		$this->db->commit();

		$duration = microtime(true) - $started;
		dol_syslog('RatingEngine::recomputeAll replayed '.$result.' games in '.round($duration, 3).'s', LOG_NOTICE);

		return array('nb_games' => $result, 'duration' => $duration);
	}

	/**
	 * Compare the stored ranking to a theoretical replay, without fixing anything.
	 *
	 * @return	array	List of array{fk_user:int,mode:string,field:string,stored:mixed,expected:mixed}
	 */
	public function checkConsistency(): array
	{
		$drifts = array();
		$fields = array('elo', 'nb_games', 'nb_wins', 'nb_losses', 'nb_draws', 'goals_for',
			'goals_against', 'nb_fanny_given', 'nb_fanny_taken', 'current_streak', 'best_streak', 'elo_peak');

		// 1. Replay in memory only, writing nothing at all
		$expected = new RatingSet($this->db, $this->entity, (int) $this->config['elo_initial']);
		if ($this->replayInto($expected, false) < 0) {
			return $drifts;
		}

		// 2. Compare with what is stored
		$userIds = array();
		foreach ($expected->all() as $rating) {
			$userIds[] = (int) $rating->fk_user;
		}
		$stored = new RatingSet($this->db, $this->entity, (int) $this->config['elo_initial']);
		$stored->loadForUsers($userIds, false);

		foreach ($expected->all() as $rating) {
			$userId = (int) $rating->fk_user;
			$mode = (string) $rating->mode;

			if (!$stored->has($userId, $mode)) {
				$drifts[] = array('fk_user' => $userId, 'mode' => $mode, 'field' => 'row',
					'stored' => null, 'expected' => 'exists');
				continue;
			}

			$current = $stored->get($userId, $mode);
			foreach ($fields as $field) {
				if ((int) $current->$field !== (int) $rating->$field) {
					$drifts[] = array('fk_user' => $userId, 'mode' => $mode, 'field' => $field,
						'stored' => (int) $current->$field, 'expected' => (int) $rating->$field);
				}
			}
		}

		return $drifts;
	}

	/**
	 * Apply a game on a set of ranking rows, and store the Elo snapshots of its
	 * participants.
	 *
	 * Behaves identically whether the set was loaded from database (incremental
	 * path) or built in memory (full rebuild). That indifference is what makes
	 * both paths converge on the same result.
	 *
	 * @param	Game		$game			Game to apply, participants already loaded
	 * @param	RatingSet	$set			Ranking rows to update in place
	 * @param	bool		$persistPlayers	Write the elo_* columns of the participants
	 * @return	int							1 if OK, -1 if KO
	 */
	public function applyGame(Game $game, RatingSet $set, bool $persistPlayers = true): int
	{
		if ((int) $game->status !== Game::STATUS_VALIDATED) {
			return 1;   // RG-20: a cancelled game is neutral
		}
		if (!is_array($game->lines) || empty($game->lines)) {
			$this->error = 'BabyfootErrNoPlayer';
			dol_syslog('RatingEngine::applyGame game '.$game->id.' has no participant', LOG_ERR);
			return -1;
		}

		$scores = array(1 => (int) $game->score_team1, 2 => (int) $game->score_team2);
		$modes = array((string) $game->mode, BabyfootConfig::MODE_ALL);
		$snapshots = array();

		foreach ($modes as $mode) {
			// 1. Team ratings BEFORE the game, collected before any mutation (RG-12)
			$teamElos = array(1 => array(), 2 => array());
			foreach ($game->lines as $line) {
				$team = (int) $line->team;
				$teamElos[$team][] = (int) $set->get((int) $line->fk_user, $mode)->elo;
			}
			if (empty($teamElos[1]) || empty($teamElos[2])) {
				$this->error = 'BabyfootErrPlayerCount';
				dol_syslog('RatingEngine::applyGame game '.$game->id.' has an empty team', LOG_ERR);
				return -1;
			}
			$teamRating = array(
				1 => $this->calculator->teamRating($teamElos[1]),
				2 => $this->calculator->teamRating($teamElos[2]),
			);

			// 2. One delta per player, with an individual K factor (decision D10)
			foreach ($game->lines as $line) {
				$team = (int) $line->team;
				$other = ($team === 1) ? 2 : 1;
				$rating = $set->get((int) $line->fk_user, $mode);

				$result = EloCalculator::RESULT_DRAW;
				if (!is_null($game->winner_team)) {
					$result = ((int) $game->winner_team === $team) ? EloCalculator::RESULT_WIN : EloCalculator::RESULT_LOSS;
				}

				$before = (int) $rating->elo;
				$delta = $this->calculator->delta(
					$teamRating[$team],
					$teamRating[$other],
					$result,
					(int) $rating->nb_games,
					$scores[$team],
					$scores[$other]
				);

				$this->applyOnRating($rating, $delta, $result, $scores[$team], $scores[$other], (int) $game->date_game);

				$snapshots[(int) $line->fk_user][$mode] = array(
					'before' => $before,
					'after' => (int) $rating->elo,
					'delta' => $delta,
				);
			}
		}

		// 3. Store the snapshots on the participants (RG-18 for the mode, D9 for 'all')
		$gameMode = (string) $game->mode;
		foreach ($game->lines as $line) {
			$userId = (int) $line->fk_user;
			$line->elo_before = $snapshots[$userId][$gameMode]['before'];
			$line->elo_after = $snapshots[$userId][$gameMode]['after'];
			$line->elo_delta = $snapshots[$userId][$gameMode]['delta'];
			$line->elo_all_before = $snapshots[$userId][BabyfootConfig::MODE_ALL]['before'];
			$line->elo_all_after = $snapshots[$userId][BabyfootConfig::MODE_ALL]['after'];
			$line->elo_all_delta = $snapshots[$userId][BabyfootConfig::MODE_ALL]['delta'];
		}

		if ($persistPlayers && $this->persistPlayerSnapshots($game) < 0) {
			return -1;
		}

		return 1;
	}

	/**
	 * Rebuild the ranking without opening a transaction of its own.
	 *
	 * Used by the entry points, which are already inside the transaction opened
	 * by Game::create() or Game::update().
	 *
	 * @return	int		1 if OK, -1 if KO
	 */
	private function rebuild(): int
	{
		$result = $this->replayEverything(true);
		if ($result < 0) {
			$this->error = ($this->error !== '') ? $this->error : 'BabyfootErrRecomputeFailed';
			return -1;
		}

		return 1;
	}

	/**
	 * Reset the ranking table and replay every validated game.
	 *
	 * @param	bool	$persistPlayers		Rewrite the elo_* columns of the participants
	 * @return	int							Number of games replayed, or -1 on error
	 */
	private function replayEverything(bool $persistPlayers): int
	{
		global $user;

		$sql = "DELETE FROM ".$this->db->prefix()."babyfoot_rating";
		$sql .= " WHERE entity = ".((int) $this->entity)." AND fk_user > 0";
		if (!$this->db->query($sql)) {
			$this->error = 'BabyfootErrRecomputeFailed';
			dol_syslog('RatingEngine::replayEverything reset failed: '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		$set = new RatingSet($this->db, $this->entity, (int) $this->config['elo_initial']);
		$nbGames = $this->replayInto($set, $persistPlayers);
		if ($nbGames < 0) {
			return -1;
		}

		if ($set->flush($user) < 0) {
			$this->error = 'BabyfootErrRecomputeFailed';
			return -1;
		}

		return $nbGames;
	}

	/**
	 * Replay every validated game into a ranking set.
	 *
	 * @param	RatingSet	$set				Set to feed
	 * @param	bool		$persistPlayers		Rewrite the elo_* columns of the participants
	 * @return	int								Number of games replayed, or -1 on error
	 */
	private function replayInto(RatingSet $set, bool $persistPlayers): int
	{
		$nbGames = 0;
		$offset = 0;

		while (true) {
			$games = $this->loadGamePage($offset, self::REPLAY_PAGE_SIZE);
			if (empty($games)) {
				break;
			}
			foreach ($games as $game) {
				if ($this->applyGame($game, $set, $persistPlayers) < 0) {
					return -1;
				}
				$nbGames++;
			}
			if (count($games) < self::REPLAY_PAGE_SIZE) {
				break;
			}
			$offset += self::REPLAY_PAGE_SIZE;
		}

		return $nbGames;
	}

	/**
	 * Load one page of validated games, participants included.
	 *
	 * The ORDER BY is the deterministic replay order of section 8.3.
	 *
	 * @param	int		$offset		Offset in the ordered list
	 * @param	int		$limit		Page size
	 * @return	Game[]				Games of the page, with their participants loaded
	 */
	private function loadGamePage(int $offset, int $limit): array
	{
		$games = array();

		$sql = "SELECT g.rowid, g.entity, g.ref, g.date_game, g.mode,";
		$sql .= " g.score_team1, g.score_team2, g.winner_team, g.status";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= " WHERE g.entity = ".((int) $this->entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		$sql .= " ORDER BY g.date_game ASC, g.rowid ASC";
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('RatingEngine::loadGamePage '.$this->db->lasterror(), LOG_ERR);
			return $games;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$game = new Game($this->db);
			$game->id = (int) $obj->rowid;
			$game->entity = (int) $obj->entity;
			$game->ref = $obj->ref;
			$game->date_game = $this->db->jdate($obj->date_game);
			$game->mode = $obj->mode;
			$game->score_team1 = (int) $obj->score_team1;
			$game->score_team2 = (int) $obj->score_team2;
			$game->winner_team = is_null($obj->winner_team) ? null : (int) $obj->winner_team;
			$game->status = (int) $obj->status;
			$game->lines = GamePlayer::fetchAllByGame($this->db, (int) $game->id);

			$games[] = $game;
		}
		$this->db->free($resql);

		return $games;
	}

	/**
	 * Apply one game result on one ranking row.
	 *
	 * @param	Rating	$rating			Ranking row to update in place
	 * @param	int		$delta			Elo delta to add
	 * @param	float	$result			RESULT_WIN, RESULT_DRAW or RESULT_LOSS
	 * @param	int		$scoreFor		Goals scored by the player side
	 * @param	int		$scoreAgainst	Goals scored by the other side
	 * @param	int		$dateGame		Timestamp of the game
	 * @return	void
	 */
	private function applyOnRating(Rating $rating, int $delta, float $result, int $scoreFor, int $scoreAgainst, int $dateGame): void
	{
		$scoreMax = (int) $this->config['score_max'];

		$rating->elo += $delta;
		$rating->nb_games++;
		$rating->goals_for += $scoreFor;
		$rating->goals_against += $scoreAgainst;

		if ($result === EloCalculator::RESULT_WIN) {
			$rating->nb_wins++;
			$rating->current_streak = ($rating->current_streak > 0) ? $rating->current_streak + 1 : 1;
			if ($scoreFor === $scoreMax && $scoreAgainst === 0) {
				$rating->nb_fanny_given++;
			}
		} elseif ($result === EloCalculator::RESULT_LOSS) {
			$rating->nb_losses++;
			$rating->current_streak = ($rating->current_streak < 0) ? $rating->current_streak - 1 : -1;
			if ($scoreAgainst === $scoreMax && $scoreFor === 0) {
				$rating->nb_fanny_taken++;
			}
		} else {
			$rating->nb_draws++;
			$rating->current_streak = 0;
		}

		// best_streak tracks the best WINNING streak only
		if ($rating->current_streak > 0 && $rating->current_streak > $rating->best_streak) {
			$rating->best_streak = $rating->current_streak;
		}
		if ($rating->elo > $rating->elo_peak) {
			$rating->elo_peak = $rating->elo;
		}
		$rating->date_last_game = $dateGame;
	}

	/**
	 * Write the Elo snapshots carried by the participants of a game.
	 *
	 * @param	Game	$game	Game whose participants carry the snapshots
	 * @return	int				1 if OK, -1 if KO
	 */
	private function persistPlayerSnapshots(Game $game): int
	{
		foreach ($game->lines as $line) {
			if (empty($line->id)) {
				$this->error = 'BabyfootErrRecomputeFailed';
				dol_syslog('RatingEngine::persistPlayerSnapshots participant without rowid on game '.$game->id, LOG_ERR);
				return -1;
			}

			$sql = "UPDATE ".$this->db->prefix()."babyfoot_game_player SET";
			$sql .= " elo_before = ".((int) $line->elo_before);
			$sql .= ", elo_after = ".((int) $line->elo_after);
			$sql .= ", elo_delta = ".((int) $line->elo_delta);
			$sql .= ", elo_all_before = ".((int) $line->elo_all_before);
			$sql .= ", elo_all_after = ".((int) $line->elo_all_after);
			$sql .= ", elo_all_delta = ".((int) $line->elo_all_delta);
			$sql .= " WHERE rowid = ".((int) $line->id);

			if (!$this->db->query($sql)) {
				$this->error = 'BabyfootErrRecomputeFailed';
				dol_syslog('RatingEngine::persistPlayerSnapshots '.$this->db->lasterror(), LOG_ERR);
				return -1;
			}
		}

		return 1;
	}
}
