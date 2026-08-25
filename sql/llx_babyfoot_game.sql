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

CREATE TABLE llx_babyfoot_game(
	rowid			INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity			INTEGER DEFAULT 1 NOT NULL,
	ref				VARCHAR(32) NOT NULL,
	date_game		DATETIME NOT NULL,
	mode			VARCHAR(8) NOT NULL,
	score_team1		SMALLINT NOT NULL,
	score_team2		SMALLINT NOT NULL,
	winner_team		SMALLINT NULL,
	status			SMALLINT DEFAULT 1 NOT NULL,
	note_private	TEXT NULL,
	fk_user_creat	INTEGER NOT NULL,
	fk_user_modif	INTEGER NULL,
	date_creation	DATETIME NOT NULL,
	tms				TIMESTAMP
) ENGINE=innodb;
