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
 * \brief   Placeholder for the REST API. NOT implemented in v1 (spec section 2).
 *
 * The file exists so adding the API later needs no restructuring. To activate
 * it, uncomment 'restapi' => 1 in modBabyfoot::$module_parts and implement the
 * endpoints below.
 *
 * Planned endpoints:
 *   GET  /babyfoot/games            list the games
 *   POST /babyfoot/games            record a game
 *   GET  /babyfoot/ranking          read the ranking of one mode
 *   GET  /babyfoot/players/{id}     read the statistics of one player
 *
 * Whatever is implemented here MUST reuse Game, GameValidator and RatingEngine:
 * the API is another caller of the same business rules, never a second
 * implementation of them.
 */

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';

/**
 * API class for the babyfoot module. Reserved for a future version.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class BabyfootApi extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
	}
}
