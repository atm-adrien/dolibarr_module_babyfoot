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
 * \file    class/gameplayer.class.php
 * \ingroup babyfoot
 * \brief   Participant of a game: one Dolibarr user in one team.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * One participant of one game.
 *
 * The elo_* columns are snapshots written by RatingEngine only. They are stored
 * so the history can be displayed without replaying any computation, and so a
 * correction can be replayed chronologically.
 */
class GamePlayer extends CommonObject
{
	/** @var string Element name */
	public $element = 'babyfootgameplayer';

	/** @var string Table name, without the database prefix */
	public $table_element = 'babyfoot_game_player';

	/** @var string Module name */
	public $module = 'babyfoot';

	/** @var int Does this object support extrafields */
	public $isextrafieldmanaged = 0;

	/** @var int Rowid of the game */
	public $fk_game;

	/** @var int Rowid of the Dolibarr user */
	public $fk_user;

	/** @var int Team of the player, 1 or 2 */
	public $team;

	/** @var int 1 when the player won the game */
	public $is_winner;

	/** @var int Elo of the player in the game mode, before the game */
	public $elo_before;

	/** @var int Elo of the player in the game mode, after the game */
	public $elo_after;

	/** @var int Elo change in the game mode */
	public $elo_delta;

	/** @var int Elo of the player in the overall ranking, before the game */
	public $elo_all_before;

	/** @var int Elo of the player in the overall ranking, after the game */
	public $elo_all_after;

	/** @var int Elo change in the overall ranking */
	public $elo_all_delta;

	/**
	 * @var array<string,array<string,mixed>> Field definitions
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'fk_game' => array('type' => 'integer', 'label' => 'BabyfootGame', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0, 'index' => 1),
		'fk_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'BabyfootPlayer', 'picto' => 'user', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1),
		'team' => array('type' => 'integer', 'label' => 'BabyfootTeam', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1),
		'is_winner' => array('type' => 'integer', 'label' => 'BabyfootIsWinner', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'elo_before' => array('type' => 'integer', 'label' => 'BabyfootEloBefore', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'elo_after' => array('type' => 'integer', 'label' => 'BabyfootEloAfter', 'enabled' => 1, 'position' => 51, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'elo_delta' => array('type' => 'integer', 'label' => 'BabyfootEloDelta', 'enabled' => 1, 'position' => 52, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'elo_all_before' => array('type' => 'integer', 'label' => 'BabyfootEloAllBefore', 'enabled' => 1, 'position' => 60, 'notnull' => 1, 'visible' => 0, 'default' => '0'),
		'elo_all_after' => array('type' => 'integer', 'label' => 'BabyfootEloAllAfter', 'enabled' => 1, 'position' => 61, 'notnull' => 1, 'visible' => 0, 'default' => '0'),
		'elo_all_delta' => array('type' => 'integer', 'label' => 'BabyfootEloAllDelta', 'enabled' => 1, 'position' => 62, 'notnull' => 1, 'visible' => 0, 'default' => '0'),
	);

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		// Elo columns are NOT NULL: keep them numeric until RatingEngine fills them
		$this->is_winner = 0;
		$this->elo_before = 0;
		$this->elo_after = 0;
		$this->elo_delta = 0;
		$this->elo_all_before = 0;
		$this->elo_all_after = 0;
		$this->elo_all_delta = 0;
	}

	/**
	 * Create the participant in database.
	 *
	 * @param	User	$user		User doing the creation
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					Id of the new record if OK, negative value if KO
	 */
	public function create(User $user, $notrigger = 0)
	{
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load a participant from its id.
	 *
	 * @param	int		$id		Rowid to load
	 * @return	int				>0 if found (the rowid), 0 if not found, <0 on error
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Update the participant in database.
	 *
	 * @param	User	$user		User doing the update
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					1 if OK, negative value if KO
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete the participant from database.
	 *
	 * @param	User	$user		User doing the deletion
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					1 if OK, negative value if KO
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Load every participant of one game.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$gameId		Game rowid
	 * Returned as a numerically indexed list, and NOT indexed by rowid:
	 * CommonObject::__clone() iterates $this->lines from 0 to count - 1 when it
	 * builds oldcopy, so an associative array breaks setStatusCommon().
	 *
	 * @return	GamePlayer[]		Participants, ordered by team then rowid
	 */
	public static function fetchAllByGame(DoliDB $db, int $gameId): array
	{
		$lines = array();

		$sql = "SELECT gp.rowid, gp.fk_game, gp.fk_user, gp.team, gp.is_winner,";
		$sql .= " gp.elo_before, gp.elo_after, gp.elo_delta,";
		$sql .= " gp.elo_all_before, gp.elo_all_after, gp.elo_all_delta";
		$sql .= " FROM ".$db->prefix()."babyfoot_game_player as gp";
		$sql .= " WHERE gp.fk_game = ".((int) $gameId);
		$sql .= " ORDER BY gp.team ASC, gp.rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog('GamePlayer::fetchAllByGame '.$db->lasterror(), LOG_ERR);
			return $lines;
		}
		while ($obj = $db->fetch_object($resql)) {
			$line = new GamePlayer($db);
			$line->id = (int) $obj->rowid;
			$line->fk_game = (int) $obj->fk_game;
			$line->fk_user = (int) $obj->fk_user;
			$line->team = (int) $obj->team;
			$line->is_winner = (int) $obj->is_winner;
			$line->elo_before = (int) $obj->elo_before;
			$line->elo_after = (int) $obj->elo_after;
			$line->elo_delta = (int) $obj->elo_delta;
			$line->elo_all_before = (int) $obj->elo_all_before;
			$line->elo_all_after = (int) $obj->elo_all_after;
			$line->elo_all_delta = (int) $obj->elo_all_delta;

			$lines[] = $line;
		}
		$db->free($resql);

		return $lines;
	}

	/**
	 * Delete every participant of one game.
	 *
	 * Used when a game is updated: the participants are replaced rather than
	 * diffed, which is both simpler and safer.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$gameId		Game rowid
	 * @return	int					1 if OK, -1 if KO
	 */
	public static function deleteAllByGame(DoliDB $db, int $gameId): int
	{
		$sql = "DELETE FROM ".$db->prefix()."babyfoot_game_player WHERE fk_game = ".((int) $gameId);
		if (!$db->query($sql)) {
			dol_syslog('GamePlayer::deleteAllByGame '.$db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}
}
