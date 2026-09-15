-- Adds a B3/B4/B5 review step to object logs, mirroring the receipt flow:
-- a matched object now lands as 'pending' with a short confirm window
-- instead of being saved 'confirmed' immediately. Run this once against an
-- EXISTING homelogger database (already-applied fresh installs of
-- homelogger.sql as of this file's date already include it).
--
-- Apply via phpMyAdmin: select the `homelogger` database -> SQL tab -> paste
-- this file's contents -> Go.

USE homelogger;

ALTER TABLE object_logs
  MODIFY COLUMN status ENUM('pending', 'confirmed', 'needs_review', 'rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN confirm_deadline TIMESTAMP NULL AFTER status,
  ADD COLUMN resolved_at TIMESTAMP NULL AFTER confirm_deadline,
  ADD KEY object_logs_status_deadline_idx (status, confirm_deadline);
