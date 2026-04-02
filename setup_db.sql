-- =============================================================================
-- setup_db.sql — GLORIOUS90 Order Form database setup
-- =============================================================================
--
-- HOW TO RUN (phpMyAdmin):
--   1. Log in to cPanel → phpMyAdmin.
--   2. In the left sidebar, click the database you created for this project
--      (e.g. cpanelusername_glorious90).  Make sure it is selected.
--   3. Click the "Import" tab at the top.
--   4. Click "Choose File" and select this file.
--   5. Leave all other settings at their defaults (format: SQL).
--   6. Click "Go".  You should see "Import has been successfully finished."
--
-- You only need to run this once.  Running it again will fail on the
-- UNIQUE constraint if orders already exist — that is expected behaviour.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id`              INT            NOT NULL AUTO_INCREMENT,
  `order_no`        VARCHAR(20)    NOT NULL,
  `timestamp`       DATETIME       NOT NULL,
  `nama`            VARCHAR(255)   NOT NULL,
  `telefon`         VARCHAR(20)    NOT NULL,
  `saiz`            VARCHAR(10)    NOT NULL,
  `penghantaran`    TINYINT(1)     NOT NULL DEFAULT 0,
  `alamat`          TEXT           NULL,
  `poskod`          VARCHAR(10)    NULL,
  `bandar`          VARCHAR(100)   NULL,
  `negeri`          VARCHAR(100)   NULL,
  `jumlah_bayaran`  DECIMAL(8,2)   NOT NULL,
  `created_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_no` (`order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
