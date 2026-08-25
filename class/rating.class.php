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
 * \file    class/rating.class.php
 * \ingroup babyfoot
 * \brief   One ranking row: a player in one mode.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * One row of the ranking cache.
 *
 * This table is a derived cache: it must be entirely rebuildable from the games
 * and their participants. Never store anything here that cannot be recomputed,
 * and never write to it outside of RatingEngine.
 */
class Rating extends CommonObject
{
	/** @var string Element name */
	public $element = 'babyfootrating';

	/** @var string Table name, without the database prefix */
	public $table_element = 'babyfoot_rating';

	/** @var string Module name */
	public $module = 'babyfoot';

	/** @var int Does this object support extrafields */
	public $isextrafieldmanaged = 0;

	/** @var int Rowid of the Dolibarr user */
	public $fk_user;

	/** @var string Ranking mode: '1v1', '2v2' or 'all' */
	public $mode;

	/** @var int Current Elo rating */
	public $elo;

	/** @var int Games played */
	public $nb_games;

	/** @var int Games won */
	public $nb_wins;

	/** @var int Games lost */
	public $nb_losses;

	/** @var int Games drawn */
	public $nb_draws;

	/** @var int Goals scored */
	public $goals_for;

	/** @var int Goals conceded */
	public $goals_against;

	/** @var int Fannies inflicted */
	public $nb_fanny_given;

	/** @var int Fannies suffered */
	public $nb_fanny_taken;

	/** @var int Current streak: positive on wins, negative on losses */
	public $current_streak;

	/** @var int Best winning streak ever reached */
	public $best_streak;

	/** @var int Highest Elo ever reached */
	public $elo_peak;

	/** @var int|null Timestamp of the last game played */
	public $date_last_game;

	/**
	 * @var array<string,array<string,mixed>> Field definitions
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'index' => 1),
		'fk_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'BabyfootPlayer', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'index' => 1),
		'mode' => array('type' => 'varchar(8)', 'label' => 'BabyfootMode', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1),
		'elo' => array('type' => 'integer', 'label' => 'BabyfootElo', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1),
		'nb_games' => array('type' => 'integer', 'label' => 'BabyfootNbGames', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'nb_wins' => array('type' => 'integer', 'label' => 'BabyfootNbWins', 'enabled' => 1, 'position' => 41, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'nb_losses' => array('type' => 'integer', 'label' => 'BabyfootNbLosses', 'enabled' => 1, 'position' => 42, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'nb_draws' => array('type' => 'integer', 'label' => 'BabyfootNbDraws', 'enabled' => 1, 'position' => 43, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'goals_for' => array('type' => 'integer', 'label' => 'BabyfootGoalsFor', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'goals_against' => array('type' => 'integer', 'label' => 'BabyfootGoalsAgainst', 'enabled' => 1, 'position' => 51, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'nb_fanny_given' => array('type' => 'integer', 'label' => 'BabyfootFannyGiven', 'enabled' => 1, 'position' => 60, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'nb_fanny_taken' => array('type' => 'integer', 'label' => 'BabyfootFannyTaken', 'enabled' => 1, 'position' => 61, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'current_streak' => array('type' => 'integer', 'label' => 'BabyfootCurrentStreak', 'enabled' => 1, 'position' => 70, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'best_streak' => array('type' => 'integer', 'label' => 'BabyfootBestStreak', 'enabled' => 1, 'position' => 71, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'elo_peak' => array('type' => 'integer', 'label' => 'BabyfootEloPeak', 'enabled' => 1, 'position' => 80, 'notnull' => 1, 'visible' => 1),
		'date_last_game' => array('type' => 'datetime', 'label' => 'BabyfootDateLastGame', 'enabled' => 1, 'position' => 90, 'notnull' => 0, 'visible' => 1),
	);

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create the ranking row in database.
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
	 * Load a ranking row from its id.
	 *
	 * @param	int		$id		Rowid to load
	 * @return	int				>0 if found (the rowid), 0 if not found, <0 on error
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Update the ranking row in database.
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
	 * Delete the ranking row from database.
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
	 * Build a fresh ranking row for a player in one mode (RG-10).
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int		$entity			Entity
	 * @param	int		$userId			Rowid of the Dolibarr user
	 * @param	string	$mode			'1v1', '2v2' or 'all'
	 * @param	int		$eloInitial		Starting Elo (BABYFOOT_ELO_INITIAL)
	 * @return	Rating					Unsaved ranking row
	 */
	public static function initFor(DoliDB $db, int $entity, int $userId, string $mode, int $eloInitial): Rating
	{
		$rating = new Rating($db);
		$rating->entity = $entity;
		$rating->fk_user = $userId;
		$rating->mode = $mode;
		$rating->elo = $eloInitial;
		$rating->elo_peak = $eloInitial;
		$rating->nb_games = 0;
		$rating->nb_wins = 0;
		$rating->nb_losses = 0;
		$rating->nb_draws = 0;
		$rating->goals_for = 0;
		$rating->goals_against = 0;
		$rating->nb_fanny_given = 0;
		$rating->nb_fanny_taken = 0;
		$rating->current_streak = 0;
		$rating->best_streak = 0;
		$rating->date_last_game = null;

		return $rating;
	}
}
