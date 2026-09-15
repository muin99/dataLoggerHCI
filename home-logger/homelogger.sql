-- Home Data Logger — complete MySQL setup
-- Import with: mysql -u root -p < homelogger.sql
-- Safe to re-run: DROP the database first if you want a totally clean slate.

CREATE DATABASE IF NOT EXISTS homelogger
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE homelogger;

-- Categories a user defines up front. `object` categories are matched against
-- camera hits and carry a price/unit; `attendance` categories are worker roles
-- matched against RFID hits. One table, one management UI, two types.
CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  name VARCHAR(50) NOT NULL,
  type ENUM('object', 'attendance') NOT NULL DEFAULT 'object',
  unit_label VARCHAR(20) NOT NULL DEFAULT 'pcs',
  price_per_unit DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  color CHAR(7) NOT NULL DEFAULT '#DDF2E6',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY categories_public_id_unique (public_id),
  UNIQUE KEY categories_type_name_unique (type, name)
) ENGINE=InnoDB;

-- One row per object hit (camera feed matched against a known category, or
-- a manual dashboard entry). Device-driven, same review pattern as
-- attendance: a short confirm window (B3/B4/B5 on the LCD), auto-confirms
-- on silence since objects are low-stakes/routine, but B5 flags it into
-- 'needs_review' for the dashboard same as a flagged receipt.
CREATE TABLE IF NOT EXISTS object_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  total_price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  image_path VARCHAR(255) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'device',
  status ENUM('pending', 'confirmed', 'needs_review', 'rejected') NOT NULL DEFAULT 'pending',
  confirm_deadline TIMESTAMP NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY object_logs_public_id_unique (public_id),
  KEY object_logs_category_created_idx (category_id, created_at),
  KEY object_logs_created_idx (created_at),
  KEY object_logs_status_deadline_idx (status, confirm_deadline),
  CONSTRAINT object_logs_category_fk FOREIGN KEY (category_id)
    REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- Camera hits the classifier could not confidently match to any object
-- category or receipt. Surfaced on the dashboard so an operator can assign
-- the image to an existing category, create a new one, or dismiss it.
CREATE TABLE IF NOT EXISTS unknown_object_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  status ENUM('pending', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending',
  resolved_category_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unknown_object_hits_public_id_unique (public_id),
  KEY unknown_object_hits_status_idx (status, created_at),
  CONSTRAINT unknown_object_hits_category_fk FOREIGN KEY (resolved_category_id)
    REFERENCES categories (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- One row per receipt capture. `raw_model_json` keeps the model's raw
-- extraction response for audit/debugging. Sweep semantics: an abandoned
-- pending receipt resolves to 'rejected', never 'confirmed' — financial data
-- must never get silently committed just because nobody pressed a button.
CREATE TABLE IF NOT EXISTS receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  merchant_name VARCHAR(120) NULL,
  subtotal DECIMAL(10,2) UNSIGNED NULL,
  discount DECIMAL(10,2) UNSIGNED NULL,
  tax DECIMAL(10,2) UNSIGNED NULL,
  total DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  raw_model_json MEDIUMTEXT NULL,
  status ENUM('pending', 'confirmed', 'needs_review', 'rejected') NOT NULL DEFAULT 'pending',
  confirm_deadline TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY receipts_public_id_unique (public_id),
  KEY receipts_status_idx (status, created_at),
  KEY receipts_status_deadline_idx (status, confirm_deadline)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS receipt_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  quantity DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(10,2) UNSIGNED NULL,
  line_total DECIMAL(10,2) UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY receipt_items_receipt_idx (receipt_id),
  CONSTRAINT receipt_items_receipt_fk FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- People tracked for attendance. Each can carry an RFID/NFC tag UID so a
-- reader hit resolves straight to a person without any manual lookup.
CREATE TABLE IF NOT EXISTS workers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  phone VARCHAR(40) NULL,
  notes VARCHAR(300) NULL,
  rfid_uid VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY workers_public_id_unique (public_id),
  UNIQUE KEY workers_name_unique (name),
  UNIQUE KEY workers_rfid_uid_unique (rfid_uid),
  KEY workers_active_name_idx (active, name),
  CONSTRAINT workers_category_fk FOREIGN KEY (category_id)
    REFERENCES categories (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- Check-in/check-out records. Device-driven: the ESP32 shows a 10-second
-- on-screen countdown and calls confirm/reject itself. `confirm_deadline` is
-- a crash/power-loss safety net only, swept to 'confirmed' (silence = the
-- tap happened, no objection raised).
CREATE TABLE IF NOT EXISTS attendance_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('check_in', 'check_out') NOT NULL,
  occurred_at DATETIME NOT NULL,
  note VARCHAR(300) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'device',
  status ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'confirmed',
  confirm_deadline TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_logs_public_id_unique (public_id),
  KEY attendance_worker_date_idx (worker_id, occurred_at),
  KEY attendance_occurred_at_idx (occurred_at),
  KEY attendance_status_deadline_idx (status, confirm_deadline),
  CONSTRAINT attendance_worker_fk FOREIGN KEY (worker_id)
    REFERENCES workers (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- RFID/NFC tags the reader has seen that don't match any registered worker
-- yet. Surfaced on the dashboard so an operator can assign the tag in one click.
CREATE TABLE IF NOT EXISTS unknown_rfid_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid VARCHAR(64) NOT NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dismissed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unknown_rfid_hits_uid_unique (uid)
) ENGINE=InnoDB;

-- Verified/corrected object images kept as few-shot reference examples for
-- the classifier — improves accuracy over time without retraining anything.
CREATE TABLE IF NOT EXISTS training_examples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(16) NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  log_id BIGINT UNSIGNED NULL,
  image_path VARCHAR(255) NOT NULL,
  is_correction TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  times_used INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY training_examples_public_id_unique (public_id),
  UNIQUE KEY training_examples_log_unique (log_id),
  KEY training_examples_category_active_idx (category_id, active, created_at),
  CONSTRAINT training_examples_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  CONSTRAINT training_examples_log_fk FOREIGN KEY (log_id) REFERENCES object_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Simple key-value app configuration (confirm-window override, sound toggle, etc).
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(60) NOT NULL,
  setting_value VARCHAR(300) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

-- Starting object categories.
INSERT INTO categories (public_id, name, type, unit_label, price_per_unit, color) VALUES
  ('6d696c6b626f7474', 'Milk bottle', 'object', 'bottle', 60.00, '#DDF2E6'),
  ('6e65777370617072', 'Newspaper', 'object', 'copy', 15.00, '#F8E7D2')
ON DUPLICATE KEY UPDATE public_id = VALUES(public_id);

-- Starting attendance roles. Workers are added from the dashboard.
INSERT INTO categories (public_id, name, type, unit_label, price_per_unit, color) VALUES
  ('617474656e646331', 'Cleaner', 'attendance', 'pcs', 0.00, '#E4E8FB'),
  ('617474656e646332', 'Cook', 'attendance', 'pcs', 0.00, '#F8E7D2'),
  ('617474656e646333', 'Caregiver', 'attendance', 'pcs', 0.00, '#E6E2F5')
ON DUPLICATE KEY UPDATE color = VALUES(color);

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('confirm_window_seconds', '10'),
  ('sound_notifications', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
