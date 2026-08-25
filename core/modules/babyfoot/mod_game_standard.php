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
 * \file    core/modules/babyfoot/mod_game_standard.php
 * \ingroup babyfoot
 * \brief   Standard numbering model for games: BF{yy}{mm}-{0000}
 */

require_once dirname(__FILE__).'/modules_game.php';

/**
 * Standard numbering model producing references such as BF2608-0042.
 *
 * The counter restarts every month, which get_next_value() handles natively
 * through the {yy}{mm} part of the mask.
 */
class mod_game_standard extends ModeleNumRefGame
{
	/** @var string Mask used to build the reference (spec section 11) */
	const MASK = 'BF{yy}{mm}-{0000}';

	/** @var string Name of the model */
	public $name = 'standard';

	/** @var string Version of the model */
	public $version = 'dolibarr';

	/** @var string Prefix of the produced references */
	public $prefix = 'BF';

	/** @var string Error message */
	public $error = '';

	/**
	 * Return the description of the model.
	 *
	 * @param	Translate	$langs	Translation object
	 * @return	string				Description shown on the setup screen
	 */
	public function info($langs)
	{
		$langs->load('babyfoot@babyfoot');

		return $langs->trans('BabyfootNumRefStandardDesc', self::MASK);
	}

	/**
	 * Return an example of the produced reference.
	 *
	 * @return	string	Example reference
	 */
	public function getExample()
	{
		return 'BF2608-0042';
	}

	/**
	 * Tell whether the model can be activated.
	 *
	 * @param	Game	$object		Game object
	 * @return	bool				True when the model can be used
	 */
	public function canBeActivated($object)
	{
		return true;
	}

	/**
	 * Return the next reference not yet used.
	 *
	 * get_next_value() expects a table name WITHOUT the database prefix: it adds
	 * it itself. This is the only place in the module where a table name is
	 * written without $db->prefix().
	 *
	 * @param	Game	$object		Game to build a reference for
	 * @return	string				Next reference, or an empty string on error
	 */
	public function getNextValue($object)
	{
		global $db;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

		$date = !empty($object->date_game) ? $object->date_game : dol_now();

		$numref = get_next_value($db, self::MASK, 'babyfoot_game', 'ref', '', '', $date);
		if (empty($numref)) {
			$this->error = $db->lasterror();
			dol_syslog('mod_game_standard::getNextValue '.$this->error, LOG_ERR);
			return '';
		}

		return $numref;
	}
}
