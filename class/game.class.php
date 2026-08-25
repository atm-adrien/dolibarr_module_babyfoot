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
 * \file    class/game.class.php
 * \ingroup babyfoot
 * \brief   One table football game between two teams.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once dirname(__FILE__).'/babyfootconfig.class.php';
require_once dirname(__FILE__).'/gamevalidator.class.php';
require_once dirname(__FILE__).'/gameplayer.class.php';

/**
 * One game: two teams, a final score, and its participants.
 *
 * The winning team is always deduced from the score, never entered.
 */
class Game extends CommonObject
{
	/** @var int Cancelled game: neutral for every computation (RG-20) */
	const STATUS_CANCELED = 0;

	/** @var int Validated game */
	const STATUS_VALIDATED = 1;

	/** @var string Element name */
	public $element = 'babyfootgame';

	/** @var string Table name, without the database prefix */
	public $table_element = 'babyfoot_game';

	/** @var string Module name */
	public $module = 'babyfoot';

	/** @var string Picto of the object */
	public $picto = 'babyfoot@babyfoot';

	/** @var int Does this object support extrafields */
	public $isextrafieldmanaged = 0;

	/** @var GamePlayer[] Participants of the game */
	public $lines = array();

	/** @var string[] Translation keys of the last validation failure */
	public $validationErrors = array();

	/** @var int Timestamp of the game */
	public $date_game;

	/** @var string Game mode, '1v1' or '2v2' */
	public $mode;

	/** @var int Goals of team 1 */
	public $score_team1;

	/** @var int Goals of team 2 */
	public $score_team2;

	/** @var int|null Winning team: 1, 2, or null on a draw */
	public $winner_team;

	/**
	 * @var array<string,array<string,mixed>> Field definitions
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'index' => 1),
		'ref' => array('type' => 'varchar(32)', 'label' => 'Ref', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'searchall' => 1, 'showoncombobox' => 1, 'noteditable' => 1),
		'date_game' => array('type' => 'datetime', 'label' => 'BabyfootDateGame', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1),
		'mode' => array('type' => 'varchar(8)', 'label' => 'BabyfootMode', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1, 'arrayofkeyval' => array('1v1' => 'Babyfoot1v1', '2v2' => 'Babyfoot2v2')),
		'score_team1' => array('type' => 'integer', 'label' => 'BabyfootScoreTeam1', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1),
		'score_team2' => array('type' => 'integer', 'label' => 'BabyfootScoreTeam2', 'enabled' => 1, 'position' => 41, 'notnull' => 1, 'visible' => 1),
		'winner_team' => array('type' => 'integer', 'label' => 'BabyfootWinnerTeam', 'enabled' => 1, 'position' => 50, 'notnull' => 0, 'visible' => 1, 'noteditable' => 1),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 501, 'notnull' => 0, 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 510, 'notnull' => 1, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 511, 'notnull' => 0, 'visible' => -2),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 2000, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'arrayofkeyval' => array(0 => 'BabyfootStatusCanceled', 1 => 'BabyfootStatusValidated')),
	);

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->status = self::STATUS_VALIDATED;
	}

	/**
	 * Replace the participants of the game.
	 *
	 * @param	array	$players	List of array{fk_user:int, team:int}
	 * @return	void
	 */
	public function setPlayers(array $players): void
	{
		$this->lines = array();
		if (empty($players)) {
			return;
		}

		foreach ($players as $player) {
			$line = new GamePlayer($this->db);
			$line->fk_user = (int) $player['fk_user'];
			$line->team = (int) $player['team'];
			$this->lines[] = $line;
		}
	}

	/**
	 * Return the participants of one team.
	 *
	 * @param	int		$team	Team number, 1 or 2
	 * @return	GamePlayer[]	Participants of that team
	 */
	public function getPlayersByTeam(int $team): array
	{
		$result = array();
		if (is_array($this->lines) && !empty($this->lines)) {
			foreach ($this->lines as $line) {
				if ((int) $line->team === $team) {
					$result[] = $line;
				}
			}
		}

		return $result;
	}

	/**
	 * Create the game and its participants.
	 *
	 * @param	User	$user		User doing the creation
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					Id of the new game if OK, -1 if KO
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;

		$config = BabyfootConfig::resolve();
		$entity = !empty($this->entity) ? (int) $this->entity : (int) $conf->entity;

		// RG-01 to RG-06: refuse before touching the database
		$validator = new GameValidator($this->db, $entity, $config);
		$this->validationErrors = $validator->validate(
			(string) $this->mode,
			(int) $this->score_team1,
			(int) $this->score_team2,
			(int) $this->date_game,
			$this->playersAsArray()
		);
		if (!empty($this->validationErrors)) {
			$this->error = 'BabyfootErrValidation';
			dol_syslog('Game::create validation failed: '.implode(',', $this->validationErrors), LOG_WARNING);
			return -1;
		}

		$this->db->begin();

		$this->entity = $entity;
		$this->status = self::STATUS_VALIDATED;
		$this->winner_team = GameValidator::deduceWinner((int) $this->score_team1, (int) $this->score_team2);
		$this->date_creation = dol_now();
		$this->fk_user_creat = $user->id;

		$this->ref = $this->getNextNumRef();
		if ($this->ref === '') {
			$this->db->rollback();
			return -1;
		}

		$id = $this->createCommon($user, $notrigger);
		if ($id <= 0) {
			$this->db->rollback();
			return -1;
		}

		if ($this->storePlayers($user) < 0) {
			$this->db->rollback();
			return -1;
		}

		if ($this->applyOnRatings($user, $config) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return $id;
	}

	/**
	 * Update the game and replace its participants.
	 *
	 * @param	User	$user		User doing the update
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					1 if OK, -1 if KO
	 */
	public function update(User $user, $notrigger = 0)
	{
		$config = BabyfootConfig::resolve();

		$validator = new GameValidator($this->db, (int) $this->entity, $config);
		$this->validationErrors = $validator->validate(
			(string) $this->mode,
			(int) $this->score_team1,
			(int) $this->score_team2,
			(int) $this->date_game,
			$this->playersAsArray()
		);
		if (!empty($this->validationErrors)) {
			$this->error = 'BabyfootErrValidation';
			dol_syslog('Game::update validation failed: '.implode(',', $this->validationErrors), LOG_WARNING);
			return -1;
		}

		$this->db->begin();

		$this->winner_team = GameValidator::deduceWinner((int) $this->score_team1, (int) $this->score_team2);
		$this->fk_user_modif = $user->id;

		if ($this->updateCommon($user, $notrigger) <= 0) {
			$this->db->rollback();
			return -1;
		}

		if (GamePlayer::deleteAllByGame($this->db, (int) $this->id) < 0) {
			$this->error = 'BabyfootErrValidation';
			$this->db->rollback();
			return -1;
		}
		// Reset the ids so storePlayers() inserts fresh rows
		foreach ($this->lines as $line) {
			$line->id = 0;
		}
		if ($this->storePlayers($user) < 0) {
			$this->db->rollback();
			return -1;
		}

		if ($this->refreshRatings($config) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Cancel the game: it stays in the history but becomes neutral (RG-20, RG-33).
	 *
	 * @param	User	$user	User doing the cancellation
	 * @return	int				1 if OK, -1 if KO
	 */
	public function cancel(User $user)
	{
		return $this->changeStatus($user, self::STATUS_CANCELED, 'BABYFOOT_GAME_CANCEL');
	}

	/**
	 * Reopen a cancelled game.
	 *
	 * @param	User	$user	User doing the reopening
	 * @return	int				1 if OK, -1 if KO
	 */
	public function reopen(User $user)
	{
		return $this->changeStatus($user, self::STATUS_VALIDATED, 'BABYFOOT_GAME_REOPEN');
	}

	/**
	 * Delete the game. Its participants are removed too (RG-33).
	 *
	 * @param	User	$user		User doing the deletion
	 * @param	int		$notrigger	1 = do not run triggers
	 * @return	int					1 if OK, -1 if KO
	 */
	public function delete(User $user, $notrigger = 0)
	{
		$config = BabyfootConfig::resolve();

		$this->db->begin();

		// Explicit deletion as well as the ON DELETE CASCADE, so the behaviour is
		// identical on a database where the constraint is not enforced
		if (GamePlayer::deleteAllByGame($this->db, (int) $this->id) < 0) {
			$this->error = 'BabyfootErrValidation';
			$this->db->rollback();
			return -1;
		}

		if ($this->deleteCommon($user, $notrigger) <= 0) {
			$this->db->rollback();
			return -1;
		}

		if ($this->refreshRatings($config) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Load a game from its id or its reference.
	 *
	 * @param	int			$id		Rowid to load
	 * @param	string|null	$ref	Reference to load
	 * @return	int					>0 if found (the rowid), 0 if not found, <0 on error
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * Load the participants of the game into $this->lines.
	 *
	 * @return	int		1 if OK, -1 if the game is not loaded
	 */
	public function fetchLines()
	{
		if (empty($this->id)) {
			return -1;
		}

		$this->lines = GamePlayer::fetchAllByGame($this->db, (int) $this->id);

		return 1;
	}

	/**
	 * Return the next unused reference, from the numbering model.
	 *
	 * @return	string	Next reference, or an empty string on error
	 */
	public function getNextNumRef(): string
	{
		require_once dirname(__FILE__).'/../core/modules/babyfoot/mod_game_standard.php';

		$model = new mod_game_standard();
		$ref = $model->getNextValue($this);
		if ($ref === '') {
			$this->error = $model->error;
			dol_syslog('Game::getNextNumRef '.$this->error, LOG_ERR);
		}

		return $ref;
	}

	/**
	 * Tell whether a user may edit, cancel or delete this game (RG-32).
	 *
	 * @param	User	$user	User to test
	 * @return	bool			True when the user is allowed
	 */
	public function canBeEditedBy(User $user): bool
	{
		if ($user->hasRight('babyfoot', 'modify_all')) {
			return true;
		}
		if (!$user->hasRight('babyfoot', 'modify_own')) {
			return false;
		}
		if ((int) $this->fk_user_creat !== (int) $user->id) {
			return false;
		}

		$config = BabyfootConfig::resolve();
		$deadline = (int) $this->date_creation + ((int) $config['edit_delay'] * 3600);

		return dol_now() <= $deadline;
	}

	/**
	 * Return the label of a status.
	 *
	 * @param	int		$mode	0=long label, 1=short label, 2=picto+short, 3=picto,
	 *							4=picto+long, 5=short+picto, 6=long+picto
	 * @return	string			Formatted label
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut((int) $this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Return the label of a given status.
	 *
	 * @param	int		$status		Status to render
	 * @param	int		$mode		0=long label, 1=short label, 2=picto+short, 3=picto,
	 *								4=picto+long, 5=short+picto, 6=long+picto
	 * @return	string				Formatted label
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		global $langs;

		$langs->load('babyfoot@babyfoot');

		$label = ((int) $status === self::STATUS_VALIDATED)
			? $langs->trans('BabyfootStatusValidated')
			: $langs->trans('BabyfootStatusCanceled');
		$statusType = ((int) $status === self::STATUS_VALIDATED) ? 'status4' : 'status9';

		return dolGetStatus($label, $label, '', $statusType, $mode);
	}

	/**
	 * Return a clickable link to the game card.
	 *
	 * @param	int		$withpicto					0=no picto, 1=picto and label, 2=picto only
	 * @param	string	$option						Unused, kept for signature compatibility
	 * @param	int		$notooltip					1=disable the tooltip
	 * @param	string	$morecss					Additional CSS classes
	 * @param	int		$save_lastsearch_value		-1=auto, 0=no, 1=save
	 * @return	string								HTML link
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $langs;

		$langs->load('babyfoot@babyfoot');

		$url = dol_buildpath('/babyfoot/game_card.php', 1).'?id='.((int) $this->id);
		$label = $langs->trans('BabyfootGame').' '.dol_escape_htmltag($this->ref);

		$result = '<a href="'.$url.'"';
		if (empty($notooltip)) {
			$result .= ' title="'.dol_escape_htmltag($label, 1).'" class="classfortooltip'.($morecss ? ' '.$morecss : '').'"';
		} elseif ($morecss) {
			$result .= ' class="'.$morecss.'"';
		}
		$result .= '>';
		if ($withpicto) {
			$result .= img_object('', $this->picto, 'class="paddingright"');
		}
		if ($withpicto != 2) {
			$result .= dol_escape_htmltag($this->ref);
		}
		$result .= '</a>';

		return $result;
	}

	/**
	 * Initialise the object with a sample game, for the numbering model preview.
	 *
	 * @return	int		1
	 */
	public function initAsSpecimen()
	{
		$this->ref = 'BF2608-0001';
		$this->date_game = dol_now();
		$this->mode = BabyfootConfig::MODE_2V2;
		$this->score_team1 = 10;
		$this->score_team2 = 7;
		$this->winner_team = 1;
		$this->status = self::STATUS_VALIDATED;

		return 1;
	}

	/**
	 * Return the participants as a plain array, for the validator.
	 *
	 * @return	array	List of array{fk_user:int, team:int}
	 */
	private function playersAsArray(): array
	{
		$players = array();
		if (is_array($this->lines) && !empty($this->lines)) {
			foreach ($this->lines as $line) {
				$players[] = array('fk_user' => (int) $line->fk_user, 'team' => (int) $line->team);
			}
		}

		return $players;
	}

	/**
	 * Persist the participants, setting their is_winner flag.
	 *
	 * @param	User	$user	User doing the operation
	 * @return	int				1 if OK, -1 if KO
	 */
	private function storePlayers(User $user): int
	{
		if (!is_array($this->lines) || empty($this->lines)) {
			$this->error = 'BabyfootErrPlayerCount';
			dol_syslog('Game::storePlayers called with no player', LOG_ERR);
			return -1;
		}

		foreach ($this->lines as $line) {
			$line->fk_game = (int) $this->id;
			$line->is_winner = ((int) $line->team === (int) $this->winner_team) ? 1 : 0;
			if ($line->create($user, 1) <= 0) {
				$this->error = $line->error;
				dol_syslog('Game::storePlayers '.$this->error, LOG_ERR);
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Change the status of the game and refresh the ranking.
	 *
	 * @param	User	$user			User doing the change
	 * @param	int		$status			New status
	 * @param	string	$triggerCode	Trigger code to run
	 * @return	int						1 if OK, -1 if KO
	 */
	private function changeStatus(User $user, int $status, string $triggerCode): int
	{
		$config = BabyfootConfig::resolve();

		$this->db->begin();

		if ($this->setStatusCommon($user, $status, 0, $triggerCode) <= 0) {
			$this->db->rollback();
			return -1;
		}

		if ($this->refreshRatings($config) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Apply a freshly created game on the ranking.
	 *
	 * The participants are reloaded from database first, so they carry their own
	 * rowid, which RatingEngine needs to write their Elo snapshots.
	 *
	 * @param	User	$user		User doing the operation
	 * @param	array	$config		Resolved settings
	 * @return	int					1 if OK, -1 if KO
	 */
	private function applyOnRatings(User $user, array $config): int
	{
		require_once dirname(__FILE__).'/ratingengine.class.php';

		$this->lines = GamePlayer::fetchAllByGame($this->db, (int) $this->id);

		$engine = new RatingEngine($this->db, (int) $this->entity, $config);
		if ($engine->onGameCreated($this) < 0) {
			$this->error = $engine->error;
			dol_syslog('Game::applyOnRatings '.$this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Rebuild the ranking after the game was changed, cancelled or deleted.
	 *
	 * @param	array	$config		Resolved settings
	 * @return	int					1 if OK, -1 if KO
	 */
	private function refreshRatings(array $config): int
	{
		require_once dirname(__FILE__).'/ratingengine.class.php';

		$engine = new RatingEngine($this->db, (int) $this->entity, $config);
		if ($engine->onGameChanged($this) < 0) {
			$this->error = $engine->error;
			dol_syslog('Game::refreshRatings '.$this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}
}
