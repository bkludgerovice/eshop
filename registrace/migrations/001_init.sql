-- BKL Registrace – výchozí schéma (verze 1, připraveno pro verzi 2)
-- Pravidlo: migrace jsou jen přidávací (nové tabulky/sloupce), nikdy nemažou data.
-- Částky jsou v nejmenších jednotkách měny (haléře / grosze / centy) jako celé číslo.

CREATE TABLE organizers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

INSERT INTO organizers (id, name) VALUES (1, 'Běžecký klub Ludgeřovice, z. s.');

CREATE TABLE admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('hlavni_admin','spravce_akce','jen_cteni') NOT NULL DEFAULT 'spravce_akce',
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_email (email),
  CONSTRAINT fk_admin_org FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE admin_user_events (
  admin_user_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (admin_user_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE settings (
  name VARCHAR(100) PRIMARY KEY,
  value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(150) NOT NULL,
  owner VARCHAR(200) NOT NULL,
  account_number VARCHAR(40) NOT NULL,
  bank_code VARCHAR(10) NOT NULL,
  iban VARCHAR(40) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CZK',
  fio_token_key VARCHAR(60) NULL COMMENT 'název tokenu v config.php, nikdy token samotný',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_org FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

INSERT INTO bank_accounts (id, name, owner, account_number, bank_code, iban, currency, fio_token_key)
VALUES (1, 'Klubový účet Fio', 'Běžecký klub Ludgeřovice, z. s.', '2502518589', '2010', 'CZ5520100000002502518589', 'CZK', 'klub');

CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT UNSIGNED NOT NULL DEFAULT 1,
  slug VARCHAR(100) NOT NULL,
  vs_code TINYINT UNSIGNED NOT NULL COMMENT 'číslo akce do VS (01–99)',
  vs_next INT UNSIGNED NOT NULL DEFAULT 1,
  name JSON NOT NULL COMMENT '{"cs": "...", "pl": "...", "en": "..."}',
  description JSON NULL,
  starts_at DATETIME NOT NULL,
  venue VARCHAR(255) NULL,
  logo_url VARCHAR(500) NULL,
  image_url VARCHAR(500) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(40) NULL,
  web_url VARCHAR(500) NULL,
  terms_url VARCHAR(500) NULL,
  gdpr_url VARCHAR(500) NULL,
  reg_opens_at DATETIME NULL,
  reg_closes_at DATETIME NULL,
  default_language CHAR(2) NOT NULL DEFAULT 'cs',
  languages VARCHAR(20) NOT NULL DEFAULT 'cs',
  status ENUM('pripravovana','otevreno','pozastaveno','uzavreno','ukoncena','archivovana') NOT NULL DEFAULT 'pripravovana',
  capacity INT UNSIGNED NULL COMMENT 'společná kapacita celé akce',
  bank_account_id INT UNSIGNED NOT NULL DEFAULT 1,
  currency CHAR(3) NOT NULL DEFAULT 'CZK',
  currencies VARCHAR(20) NOT NULL DEFAULT 'CZK' COMMENT 'V2',
  payment_methods JSON NULL COMMENT 'V2: metody podle země',
  payment_due_days SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  reminder_days_before_due SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  overdue_action ENUM('zrusit','rucne') NOT NULL DEFAULT 'rucne',
  overdue_grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  transfer_deadline DATETIME NULL,
  onsite_enabled TINYINT(1) NOT NULL DEFAULT 1,
  discount_applies_to_products TINYINT(1) NOT NULL DEFAULT 0,
  max_runners_per_order TINYINT UNSIGNED NOT NULL DEFAULT 10,
  guardian_age SMALLINT UNSIGNED NOT NULL DEFAULT 18,
  color_primary VARCHAR(7) NOT NULL DEFAULT '#db131e',
  embed_origins VARCHAR(1000) NOT NULL DEFAULT 'https://www.bkludgerovice.cz https://bkludgerovice.cz',
  sender_name VARCHAR(150) NULL,
  reply_to VARCHAR(190) NULL,
  checkin_pin_hash VARCHAR(255) NULL,
  checkin_until DATETIME NULL,
  public_list_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_slug (slug),
  CONSTRAINT fk_event_org FOREIGN KEY (organizer_id) REFERENCES organizers(id),
  CONSTRAINT fk_event_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE races (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name JSON NOT NULL,
  distance_km DECIMAL(6,2) NULL,
  capacity INT UNSIGNED NULL,
  reg_opens_at DATETIME NULL,
  reg_closes_at DATETIME NULL,
  min_age SMALLINT UNSIGNED NULL COMMENT 'podle ročníku',
  max_age SMALLINT UNSIGNED NULL,
  bib_from INT UNSIGNED NULL,
  bib_to INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_race_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  race_id INT UNSIGNED NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  gender ENUM('M','Z') NULL,
  age_from SMALLINT UNSIGNED NULL,
  age_to SMALLINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_cat_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE price_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  race_id INT UNSIGNED NULL COMMENT 'NULL = všechny tratě',
  name VARCHAR(100) NOT NULL DEFAULT '',
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  channel ENUM('online','na_miste') NOT NULL DEFAULT 'online',
  amount INT NOT NULL COMMENT 'v haléřích',
  currency CHAR(3) NOT NULL DEFAULT 'CZK',
  CONSTRAINT fk_tier_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE age_discounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  age_max SMALLINT UNSIGNED NULL COMMENT 'věk podle ročníku <= age_max',
  age_min SMALLINT UNSIGNED NULL COMMENT 'věk podle ročníku >= age_min',
  percent TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_agedisc_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  kind ENUM('clensky_spolecny','jednorazovy','obecny') NOT NULL DEFAULT 'obecny',
  label VARCHAR(150) NOT NULL DEFAULT '',
  percent TINYINT UNSIGNED NULL,
  fixed_amount INT NULL COMMENT 'v haléřích',
  max_uses INT UNSIGNED NULL COMMENT 'NULL = neomezeně',
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  batch VARCHAR(60) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupon (event_id, code),
  CONSTRAINT fk_coupon_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE charity_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  purpose JSON NOT NULL,
  recipient VARCHAR(200) NOT NULL,
  bank_account_id INT UNSIGNED NULL COMMENT 'V2: jiný účet',
  preset_amounts VARCHAR(100) NOT NULL DEFAULT '50,100,200' COMMENT 'v Kč, čárkou',
  allow_custom TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_charity_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE form_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  race_id INT UNSIGNED NULL,
  field_key VARCHAR(60) NOT NULL,
  type ENUM('text','number','date','select','multiselect','checkbox') NOT NULL,
  label JSON NOT NULL,
  help JSON NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  is_product TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'příplatky se počítají jako produkty',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_field (event_id, field_key),
  CONSTRAINT fk_field_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE form_field_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_id INT UNSIGNED NOT NULL,
  label JSON NOT NULL,
  surcharge INT NOT NULL DEFAULT 0 COMMENT 'v haléřích',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_option_field FOREIGN KEY (field_id) REFERENCES form_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  vs VARCHAR(10) NOT NULL,
  public_token VARCHAR(64) NOT NULL,
  contact_name VARCHAR(200) NOT NULL,
  contact_email VARCHAR(190) NOT NULL,
  contact_phone VARCHAR(40) NULL,
  language CHAR(2) NOT NULL DEFAULT 'cs',
  currency CHAR(3) NOT NULL DEFAULT 'CZK',
  channel ENUM('online','na_miste','admin') NOT NULL DEFAULT 'online',
  bank_account_id INT UNSIGNED NOT NULL,
  pay_account VARCHAR(60) NOT NULL COMMENT 'snímek čísla účtu',
  pay_iban VARCHAR(40) NOT NULL COMMENT 'snímek IBAN',
  amount_entry INT NOT NULL DEFAULT 0,
  amount_products INT NOT NULL DEFAULT 0,
  amount_discount INT NOT NULL DEFAULT 0,
  amount_charity INT NOT NULL DEFAULT 0,
  amount_total INT NOT NULL DEFAULT 0,
  amount_paid INT NOT NULL DEFAULT 0,
  due_date DATE NULL,
  payment_status ENUM('nezaplaceno','castecne','zaplaceno','preplaceno','vraceno','ke_kontrole') NOT NULL DEFAULT 'nezaplaceno',
  paid_at DATETIME NULL,
  reminded_at DATETIME NULL,
  note VARCHAR(1000) NULL,
  internal_note TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_vs (vs),
  UNIQUE KEY uq_order_token (public_token),
  KEY ix_order_event (event_id),
  CONSTRAINT fk_order_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_order_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE registrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  race_id INT UNSIGNED NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  birth_date DATE NOT NULL,
  gender ENUM('M','Z') NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  club VARCHAR(150) NULL,
  city VARCHAR(150) NULL,
  country CHAR(2) NOT NULL DEFAULT 'CZ',
  guardian_name VARCHAR(200) NULL,
  price_tier_id INT UNSIGNED NULL,
  price_base INT NOT NULL DEFAULT 0,
  age_discount INT NOT NULL DEFAULT 0,
  coupon_id INT UNSIGNED NULL,
  coupon_discount INT NOT NULL DEFAULT 0,
  price_products INT NOT NULL DEFAULT 0,
  price_final INT NOT NULL DEFAULT 0 COMMENT 'startovné po slevě + produkty',
  category_id INT UNSIGNED NULL,
  bib INT UNSIGNED NULL,
  status ENUM('rozpracovana','ceka_na_platbu','potvrzena','zrusena','expirovana','ke_kontrole') NOT NULL DEFAULT 'ceka_na_platbu',
  checkin_code VARCHAR(16) NOT NULL,
  checked_in_at DATETIME NULL,
  checked_in_by VARCHAR(100) NULL,
  transferred_from_id INT UNSIGNED NULL,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reg_bib (event_id, bib),
  UNIQUE KEY uq_reg_checkin (checkin_code),
  KEY ix_reg_event_status (event_id, status),
  KEY ix_reg_lastname (event_id, last_name),
  CONSTRAINT fk_reg_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_reg_race FOREIGN KEY (race_id) REFERENCES races(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE registration_field_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NULL,
  value_text VARCHAR(1000) NULL,
  surcharge INT NOT NULL DEFAULT 0,
  KEY ix_rfv_reg (registration_id),
  CONSTRAINT fk_rfv_reg FOREIGN KEY (registration_id) REFERENCES registrations(id),
  CONSTRAINT fk_rfv_field FOREIGN KEY (field_id) REFERENCES form_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE consents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  type ENUM('podminky','osobni_udaje','verejna_startovka','souhlas_rodice') NOT NULL,
  text_version VARCHAR(40) NOT NULL,
  accepted_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  KEY ix_consent_reg (registration_id),
  CONSTRAINT fk_consent_reg FOREIGN KEY (registration_id) REFERENCES registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_account_id INT UNSIGNED NULL,
  source ENUM('fio','hotovost','rucne','stripe') NOT NULL,
  external_id VARCHAR(100) NULL,
  amount INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CZK',
  vs VARCHAR(20) NULL,
  counter_account VARCHAR(100) NULL,
  counter_name VARCHAR(200) NULL,
  message VARCHAR(500) NULL,
  received_at DATETIME NOT NULL,
  order_id INT UNSIGNED NULL,
  match_status ENUM('sparovano','nesparovano','ignorovano','rucne') NOT NULL DEFAULT 'nesparovano',
  raw JSON NULL,
  created_by VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_ext (source, bank_account_id, external_id),
  KEY ix_payment_order (order_id),
  KEY ix_payment_vs (vs),
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE transfer_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  new_data JSON NOT NULL,
  status ENUM('automaticky','ceka','schvaleno','zamitnuto') NOT NULL DEFAULT 'ceka',
  decided_by VARCHAR(100) NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_reg FOREIGN KEY (registration_id) REFERENCES registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE stripe_events (
  id VARCHAR(100) PRIMARY KEY,
  type VARCHAR(100) NOT NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE email_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NULL,
  event_key VARCHAR(50) NOT NULL,
  language CHAR(2) NOT NULL DEFAULT 'cs',
  subject VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tpl (event_id, event_key, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE email_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dedupe_key VARCHAR(190) NOT NULL,
  to_email VARCHAR(190) NOT NULL,
  bcc VARCHAR(190) NULL,
  reply_to VARCHAR(190) NULL,
  sender_name VARCHAR(150) NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  attachments JSON NULL COMMENT 'inline obrázky: [{"cid","type","data"(base64)}]',
  order_id INT UNSIGNED NULL,
  registration_id INT UNSIGNED NULL,
  status ENUM('ceka','odeslano','chyba','zruseno') NOT NULL DEFAULT 'ceka',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_dedupe (dedupe_key),
  KEY ix_mail_status (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE email_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  filter JSON NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  recipients INT NOT NULL DEFAULT 0,
  status ENUM('koncept','odesila_se','odeslano') NOT NULL DEFAULT 'koncept',
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE export_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  columns JSON NOT NULL,
  filters JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  old_value JSON NULL,
  new_value JSON NULL,
  actor VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE system_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level ENUM('info','varovani','chyba','kriticka') NOT NULL,
  source VARCHAR(40) NOT NULL,
  message VARCHAR(2000) NOT NULL,
  context JSON NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_syslog (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE job_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(40) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  result VARCHAR(20) NULL,
  message VARCHAR(1000) NULL,
  KEY ix_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
