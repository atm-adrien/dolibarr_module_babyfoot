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
		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = '^fa-futbol';

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
			'restapi' => 1,
		);

		$this->config_page_url = array('setup.php@babyfoot');
		$this->langfiles = array('babyfoot@babyfoot');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(21, 0);

		// Settings created on activation, never removed on deactivation (spec section 7).
		// The scoring rules and the K factor are NOT settings: see BabyfootConfig.
		$this->const = array(
			array('BABYFOOT_ELO_INITIAL', 'chaine', '1000', 'Initial Elo rating', 0, 'current', 0),
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
	 * Declare the module menus: no top entry, one left entry under the Tools main
	 * menu that opens the quick entry screen, and its children on a single level.
	 *
	 * @return	void
	 */
	private function loadMenus(): void
	{
		$r = 0;

		// Menubase only inserts a child once its parent is already in the list, so the
		// parent must be declared first and therefore keep the lowest position.
		$leftEntries = array(
			array('titre' => 'BabyfootMenuTop', 'leftmenu' => 'babyfoot', 'parent' => '', 'url' => '/babyfoot/game_quickadd.php', 'perm' => 'read'),
			array('titre' => 'BabyfootMenuNewGame', 'leftmenu' => 'babyfoot_new', 'parent' => 'babyfoot', 'url' => '/babyfoot/game_quickadd.php', 'perm' => 'create'),
			array('titre' => 'BabyfootMenuGames', 'leftmenu' => 'babyfoot_games', 'parent' => 'babyfoot', 'url' => '/babyfoot/game_list.php', 'perm' => 'read'),
			array('titre' => 'BabyfootMenuPlayers', 'leftmenu' => 'babyfoot_players', 'parent' => 'babyfoot', 'url' => '/babyfoot/player_list.php', 'perm' => 'read'),
			array('titre' => 'BabyfootMenuRanking', 'leftmenu' => 'babyfoot_ranking', 'parent' => 'babyfoot', 'url' => '/babyfoot/ranking.php', 'perm' => 'read'),
			array('titre' => 'BabyfootMenuStats', 'leftmenu' => 'babyfoot_stats', 'parent' => 'babyfoot', 'url' => '/babyfoot/stats.php', 'perm' => 'read'),
		);

		foreach ($leftEntries as $entry) {
			$this->menu[$r++] = array(
				'fk_menu' => 'fk_mainmenu=tools'.($entry['parent'] !== '' ? ',fk_leftmenu='.$entry['parent'] : ''),
				'type' => 'left',
				'titre' => $entry['titre'],
				'prefix' => ($entry['parent'] === '' ? 'fa-futbol' : ''),
				'mainmenu' => 'tools',
				'leftmenu' => $entry['leftmenu'],
				'url' => $entry['url'],
				'langs' => 'babyfoot@babyfoot',
				'position' => 1000 + $r,
				'enabled' => 'isModEnabled("babyfoot")',
				'perms' => '$user->hasRight("babyfoot", "'.$entry['perm'].'")',
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
