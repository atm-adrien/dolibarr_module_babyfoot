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
 * \file    core/boxes/box_babyfoot_ranking.php
 * \ingroup babyfoot
 * \brief   Dashboard widget: top 5, own rank, last three games.
 */

require_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
require_once dirname(__FILE__).'/../../class/babyfootconfig.class.php';
require_once dirname(__FILE__).'/../../class/game.class.php';
require_once dirname(__FILE__).'/../../class/stats/rankingrepository.class.php';

/**
 * Compact dashboard widget of the babyfoot module.
 *
 * Reads the ranking cache and the last games only: never computes any Elo.
 */
class box_babyfoot_ranking extends ModeleBoxes
{
	/** @var int How many players of the ranking are shown */
	const TOP_PLAYERS = 5;

	/** @var int How many recent games are shown */
	const LAST_GAMES = 3;

	/** @var string Box identifier */
	public $boxcode = 'babyfootranking';

	/** @var string Picto of the box */
	public $boximg = 'babyfoot@babyfoot';

	/** @var string Label of the box */
	public $boxlabel;

	/** @var string[] Modules the box depends on */
	public $depends = array('babyfoot');

	/** @var DoliDB Database handler */
	public $db;

	/** @var array Box header */
	public $info_box_head = array();

	/** @var array Box content */
	public $info_box_contents = array();

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$param		More parameters
	 */
	public function __construct(DoliDB $db, $param = '')
	{
		global $langs;

		$langs->load('babyfoot@babyfoot');

		$this->db = $db;
		$this->boxlabel = $langs->trans('BabyfootBoxRankingLabel');
	}

	/**
	 * Load the box content.
	 *
	 * @param	int		$max	Maximum number of records
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $conf, $langs, $user;

		$langs->load('babyfoot@babyfoot');

		$this->info_box_head = array('text' => $langs->trans('BabyfootBoxRankingLabel'));
		$this->info_box_contents = array();
		$line = 0;

		// Access control inside the box too: a box is a screen like any other
		if (!$user->hasRight('babyfoot', 'read')) {
			$this->info_box_contents[$line][0] = array(
				'td' => 'colspan="3"',
				'text' => $langs->trans('ReadPermissionNotAllowed'),
			);
			return;
		}

		$config = BabyfootConfig::resolve();
		$repository = new RankingRepository($this->db, (int) $conf->entity);
		$ranking = $repository->getRanking(BabyfootConfig::MODE_ALL, (int) $config['min_games_ranked'], 0, 0);

		// Empty state (section 9): a link to the entry screen, never an empty table
		if (empty($ranking)) {
			$this->info_box_contents[$line][0] = array(
				'td' => 'colspan="3" class="center"',
				'text' => $langs->trans('BabyfootRecordFirstGame'),
				'url' => dol_buildpath('/babyfoot/game_quickadd.php', 1),
			);
			return;
		}

		// Top players
		$position = 0;
		foreach ($ranking as $row) {
			if ($position >= self::TOP_PLAYERS) {
				break;
			}
			$position++;

			$this->info_box_contents[$line][0] = array(
				'td' => 'class="center" width="20"',
				'text' => (string) $position,
			);
			$this->info_box_contents[$line][1] = array(
				'td' => '',
				'text' => $this->playerName((int) $row['fk_user']),
				'url' => dol_buildpath('/babyfoot/player_card.php', 1).'?id='.((int) $row['fk_user']),
			);
			$this->info_box_contents[$line][2] = array(
				'td' => 'class="right"',
				'text' => (string) ((int) $row['elo']),
			);
			$line++;
		}

		// Rank and Elo of the current user, with their recent change
		$ownPosition = 0;
		$ownRow = null;
		foreach ($ranking as $index => $row) {
			if ((int) $row['fk_user'] === (int) $user->id) {
				$ownPosition = $index + 1;
				$ownRow = $row;
				break;
			}
		}

		if (!is_null($ownRow)) {
			$variations = $repository->getRankVariationSinceLastGame(BabyfootConfig::MODE_ALL, $ranking);
			$variation = isset($variations[(int) $user->id]) ? (int) $variations[(int) $user->id] : 0;
			$arrow = ($variation > 0) ? '&uarr; '.$variation : (($variation < 0) ? '&darr; '.abs($variation) : '=');

			$this->info_box_contents[$line][0] = array('td' => 'colspan="3"', 'text' => '<hr>', 'asis' => 1);
			$line++;
			$this->info_box_contents[$line][0] = array('td' => 'class="center"', 'text' => (string) $ownPosition);
			$this->info_box_contents[$line][1] = array('td' => '', 'text' => $langs->trans('BabyfootYourRank'));
			// asis: this cell carries markup, which showBox() strips otherwise
			$this->info_box_contents[$line][2] = array(
				'td' => 'class="right"',
				'text' => ((int) $ownRow['elo']).' <span class="opacitymedium">'.$arrow.'</span>',
				'asis' => 1,
			);
			$line++;
		}

		// Last games
		$lastGames = $this->fetchLastGames((int) $conf->entity, self::LAST_GAMES);
		if (!empty($lastGames)) {
			$this->info_box_contents[$line][0] = array('td' => 'colspan="3"', 'text' => '<hr>', 'asis' => 1);
			$line++;

			foreach ($lastGames as $game) {
				$this->info_box_contents[$line][0] = array(
					'td' => 'class="nowraponall"',
					'text' => $game['ref'],
					'url' => dol_buildpath('/babyfoot/game_card.php', 1).'?id='.((int) $game['rowid']),
				);
				$this->info_box_contents[$line][1] = array('td' => 'class="center"', 'text' => ((int) $game['score_team1']).' - '.((int) $game['score_team2']));
				$this->info_box_contents[$line][2] = array('td' => 'class="right nowraponall"', 'text' => dol_print_date($game['date_game'], 'day'));
				$line++;
			}
		}

		// Direct link to the entry screen
		if ($user->hasRight('babyfoot', 'create')) {
			$this->info_box_contents[$line][0] = array(
				'td' => 'colspan="3" class="center"',
				'text' => $langs->trans('BabyfootNewGame'),
				'url' => dol_buildpath('/babyfoot/game_quickadd.php', 1),
			);
		}
	}

	/**
	 * Render the box.
	 *
	 * @param	array|null	$head		Header of the box
	 * @param	array|null	$contents	Content of the box
	 * @param	int			$nooutput	1 to return the content instead of printing it
	 * @return	string					Rendered box when $nooutput is set
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}

	/**
	 * Return the display name of a player.
	 *
	 * Returns plain text: showBox() builds the anchor itself from the 'url' key,
	 * and strips any markup found in a 'text' key that lacks 'asis'.
	 *
	 * @param	int		$userId		Rowid of the Dolibarr user
	 * @return	string				Player name
	 */
	private function playerName(int $userId): string
	{
		global $langs;

		$player = new User($this->db);

		return ($player->fetch($userId) > 0) ? $player->getFullName($langs) : '#'.$userId;
	}

	/**
	 * Read the most recent validated games.
	 *
	 * @param	int		$entity		Entity to filter on
	 * @param	int		$limit		How many games to read
	 * @return	array				List of games, newest first
	 */
	private function fetchLastGames(int $entity, int $limit): array
	{
		$games = array();

		$sql = "SELECT g.rowid, g.ref, g.date_game, g.score_team1, g.score_team2";
		$sql .= " FROM ".$this->db->prefix()."babyfoot_game as g";
		$sql .= " WHERE g.entity = ".((int) $entity);
		$sql .= " AND g.status = ".((int) Game::STATUS_VALIDATED);
		$sql .= " ORDER BY g.date_game DESC, g.rowid DESC";
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('box_babyfoot_ranking::fetchLastGames '.$this->db->lasterror(), LOG_ERR);
			return $games;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$games[] = array(
				'rowid' => (int) $obj->rowid,
				'ref' => $obj->ref,
				'date_game' => $this->db->jdate($obj->date_game),
				'score_team1' => (int) $obj->score_team1,
				'score_team2' => (int) $obj->score_team2,
			);
		}
		$this->db->free($resql);

		return $games;
	}
}
