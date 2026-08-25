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
 * \file    core/modules/modBabyfoot.class.php
 * \ingroup babyfoot
 * \brief   Module descriptor of the babyfoot module.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descriptor of the babyfoot module.
 *
 * Records table football games and derives an Elo ranking from them.
 */
class modBabyfoot extends DolibarrModules
{
	/**
	 * Constructor. Defines names, constants, directories, boxes, permissions.
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		$this->numero = 500042;
		$this->rights_class = 'babyfoot';
		$this->family = 'other';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'BabyfootModuleDescription';
		$this->descriptionlong = 'BabyfootModuleDescriptionLong';
		$this->editor_name = 'ATM Consulting';
		$this->editor_url = 'https://www.atm-consulting.fr';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'babyfoot@babyfoot';

		// Url to the file with your last numberversion of this module
		require_once __DIR__.'/../../class/techatm.class.php';
		$this->url_last_version = \ATM\Babyfoot\TechATM::getLastModuleVersionUrl($this);

		// This module produces no document at all (spec section 1: no PDF, no ODT)
		$this->dirs = array();

		$this->module_parts = array(
			'css' => array('/babyfoot/css/babyfoot.css.php'),
			'js' => array('/babyfoot/js/babyfoot.js.php'),
			'models' => 1,
			'hooks' => array(),
			// 'restapi' => 1,	// v2: see class/api_babyfoot.class.php
		);

		$this->config_page_url = array('setup.php@babyfoot');
		$this->langfiles = array('babyfoot@babyfoot');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(22, 0);

		// Settings created on activation, never removed on deactivation (spec section 7)
		$this->const = array(
			array('BABYFOOT_SCORE_MAX', 'chaine', '10', 'Max score of a game', 0, 'current', 0),
			array('BABYFOOT_SCORE_EXACT', 'chaine', '1', 'Winner must reach exactly the max score', 0, 'current', 0),
			array('BABYFOOT_ALLOW_DRAW', 'chaine', '0', 'Allow draws', 0, 'current', 0),
			array('BABYFOOT_ELO_INITIAL', 'chaine', '1000', 'Initial Elo rating', 0, 'current', 0),
			array('BABYFOOT_ELO_K', 'chaine', '24', 'K factor of confirmed players', 0, 'current', 0),
			array('BABYFOOT_ELO_K_NOVICE', 'chaine', '40', 'K factor of novice players', 0, 'current', 0),
			array('BABYFOOT_ELO_NOVICE_GAMES', 'chaine', '15', 'Games before leaving novice status', 0, 'current', 0),
			array('BABYFOOT_ELO_MARGIN', 'chaine', '0', 'Weight K by goal difference', 0, 'current', 0),
			array('BABYFOOT_MIN_GAMES_RANKED', 'chaine', '5', 'Min games to appear in ranking', 0, 'current', 0),
			array('BABYFOOT_EDIT_DELAY', 'chaine', '24', 'Author edit delay in hours', 0, 'current', 0),
			array('BABYFOOT_PREFILL_CURRENT_USER', 'chaine', '1', 'Prefill current user as first player', 0, 'current', 0),
			array('BABYFOOT_DEFAULT_MODE', 'chaine', '2v2', 'Default game mode', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->dictionaries = array();

		$this->boxes = array(
			0 => array(
				'file' => 'box_babyfoot_ranking.php@babyfoot',
				'note' => 'BabyfootBoxRankingNote',
				'enabledbydefaulton' => 'Home',
			),
		);

		$this->cronjobs = array();

		$this->rights = array();
		$this->loadRights();

		$this->menu = array();
		$this->loadMenus();
	}

	/**
	 * Declare the module permissions.
	 *
	 * Single level permissions: they are checked with $user->hasRight('babyfoot', 'read'),
	 * hence the empty fifth entry.
	 *
	 * @return	void
	 */
	private function loadRights(): void
	{
		$r = 0;

		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'BabyfootRightRead';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'BabyfootRightCreate';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'create';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero.'03';
		$this->rights[$r][1] = 'BabyfootRightModifyOwn';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'modify_own';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero.'04';
		$this->rights[$r][1] = 'BabyfootRightModifyAll';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'modify_all';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero.'05';
		$this->rights[$r][1] = 'BabyfootRightAdmin';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'admin';
		$this->rights[$r][5] = '';
	}

	/**
	 * Declare the module menus: one top entry and four left entries.
	 *
	 * The top entry points to the quick entry screen and not to a dashboard:
	 * recording a game is the purpose of the module (spec section 1).
	 *
	 * @return	void
	 */
	private function loadMenus(): void
	{
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'BabyfootMenuTop',
			'prefix' => img_picto('', 'babyfoot@babyfoot', 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'babyfoot',
			'leftmenu' => '',
			'url' => '/babyfoot/game_quickadd.php',
			'langs' => 'babyfoot@babyfoot',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("babyfoot")',
			'perms' => '$user->hasRight("babyfoot", "read")',
			'target' => '',
			'user' => 0,
		);

		$leftEntries = array(
			array('BabyfootMenuNewGame', 'babyfoot_new', '/babyfoot/game_quickadd.php', 'create'),
			array('BabyfootMenuGames', 'babyfoot_games', '/babyfoot/game_list.php', 'read'),
			array('BabyfootMenuRanking', 'babyfoot_ranking', '/babyfoot/ranking.php', 'read'),
			array('BabyfootMenuStats', 'babyfoot_stats', '/babyfoot/stats.php', 'read'),
		);

		foreach ($leftEntries as $entry) {
			$this->menu[$r++] = array(
				'fk_menu' => 'fk_mainmenu=babyfoot',
				'type' => 'left',
				'titre' => $entry[0],
				'mainmenu' => 'babyfoot',
				'leftmenu' => $entry[1],
				'url' => $entry[2],
				'langs' => 'babyfoot@babyfoot',
				'position' => 1000 + $r,
				'enabled' => 'isModEnabled("babyfoot")',
				'perms' => '$user->hasRight("babyfoot", "'.$entry[3].'")',
				'target' => '',
				'user' => 0,
			);
		}
	}

	/**
	 * Function called when module is enabled.
	 *
	 * Creates the tables, the permissions, the menus, the boxes and the settings.
	 *
	 * @param	string	$options	Options when enabling module ('', 'noboxes')
	 * @return	int					1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/babyfoot/sql/');
		if ($result < 0) {
			dol_syslog('modBabyfoot::init failed to load tables: '.$this->error, LOG_ERR);
			return -1;
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * Deliberately keeps every table, every row and every business setting: the
	 * spec requires that deactivating loses nothing and that reactivating finds
	 * the existing data intact.
	 *
	 * @param	string	$options	Options when disabling module
	 * @return	int					1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
