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

-- Derived cache: fully rebuildable from the two other tables by
-- RatingEngine::recomputeAll(). Nothing may live here that cannot be recomputed.
CREATE TABLE llx_babyfoot_rating(
	rowid			INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity			INTEGER DEFAULT 1 NOT NULL,
	fk_user			INTEGER NOT NULL,
	mode			VARCHAR(8) NOT NULL,
	elo				INTEGER NOT NULL,
	nb_games		INTEGER DEFAULT 0 NOT NULL,
	nb_wins			INTEGER DEFAULT 0 NOT NULL,
	nb_losses		INTEGER DEFAULT 0 NOT NULL,
	nb_draws		INTEGER DEFAULT 0 NOT NULL,
	goals_for		INTEGER DEFAULT 0 NOT NULL,
	goals_against	INTEGER DEFAULT 0 NOT NULL,
	nb_fanny_given	INTEGER DEFAULT 0 NOT NULL,
	nb_fanny_taken	INTEGER DEFAULT 0 NOT NULL,
	current_streak	INTEGER DEFAULT 0 NOT NULL,
	best_streak		INTEGER DEFAULT 0 NOT NULL,
	elo_peak		INTEGER NOT NULL,
	date_last_game	DATETIME NULL
) ENGINE=innodb;
