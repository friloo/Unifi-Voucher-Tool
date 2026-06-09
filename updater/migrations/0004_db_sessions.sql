-- Updater-Migration: DB-gestützte Sessions zulassen.
-- user_id muss NULL erlauben (anonyme Sessions vor dem Login).
-- Idempotent genug: bei bereits NULL-barer Spalte ist das ein No-Op.

ALTER TABLE `sessions` MODIFY `user_id` INT NULL;
