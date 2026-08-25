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

-- No foreign key on fk_user_creat: deleting a Dolibarr user must neither be
-- blocked nor destroy the game history (spec section 9).
ALTER TABLE llx_babyfoot_game ADD UNIQUE INDEX uk_babyfoot_game_ref (ref, entity);
ALTER TABLE llx_babyfoot_game ADD INDEX idx_babyfoot_game_replay (entity, status, date_game, rowid);
ALTER TABLE llx_babyfoot_game ADD INDEX idx_babyfoot_game_mode (entity, mode, status);
ALTER TABLE llx_babyfoot_game ADD INDEX idx_babyfoot_game_creat (fk_user_creat);
