-- Copyright (C) 2026 ATM Consulting
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

CREATE TABLE llx_babyfoot_game_player(
	rowid			INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_game			INTEGER NOT NULL,
	fk_user			INTEGER NOT NULL,
	team			SMALLINT NOT NULL,
	is_winner		TINYINT DEFAULT 0 NOT NULL,
	elo_before		INTEGER DEFAULT 0 NOT NULL,
	elo_after		INTEGER DEFAULT 0 NOT NULL,
	elo_delta		INTEGER DEFAULT 0 NOT NULL,
	elo_all_before	INTEGER DEFAULT 0 NOT NULL,
	elo_all_after	INTEGER DEFAULT 0 NOT NULL,
	elo_all_delta	INTEGER DEFAULT 0 NOT NULL
) ENGINE=innodb;
