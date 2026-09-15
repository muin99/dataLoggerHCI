-- Adds explicit discount and tax/VAT fields to receipts (e.g. a "Less" /
-- "Discount" / "Off" line, and/or a "VAT" / "Tax" / "Service Charge" line),
-- so both are visible in the dashboard instead of silently vanishing into
-- the gap between subtotal and total. Run once against an EXISTING
-- homelogger database via phpMyAdmin.

USE homelogger;

ALTER TABLE receipts
  ADD COLUMN discount DECIMAL(10,2) UNSIGNED NULL AFTER subtotal,
  ADD COLUMN tax DECIMAL(10,2) UNSIGNED NULL AFTER discount;
