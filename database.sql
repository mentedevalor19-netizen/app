-- ============================================================
-- database.sql
-- Estrutura principal do bot Telegram + Pix
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-03:00";

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_id`      BIGINT NOT NULL UNIQUE,
  `username`         VARCHAR(100) DEFAULT NULL,
  `first_name`       VARCHAR(100) DEFAULT NULL,
  `last_name`        VARCHAR(100) DEFAULT NULL,
  `language_code`    VARCHAR(16) DEFAULT NULL,
  `chat_type`        VARCHAR(30) DEFAULT NULL,
  `chat_username`    VARCHAR(100) DEFAULT NULL,
  `is_premium`       TINYINT(1) NOT NULL DEFAULT 0,
  `nome_pagador`     VARCHAR(150) DEFAULT NULL,
  `cpf`              VARCHAR(14) DEFAULT NULL,
  `start_payload`    VARCHAR(255) DEFAULT NULL,
  `estado_bot`       VARCHAR(50) NOT NULL DEFAULT '',
  `status`           ENUM('pendente','ativo','expirado') NOT NULL DEFAULT 'pendente',
  `data_expiracao`   DATETIME DEFAULT NULL,
  `grupo_adicionado` TINYINT(1) NOT NULL DEFAULT 0,
  `last_seen_at`     DATETIME DEFAULT NULL,
  `ultimo_start_em`  DATETIME DEFAULT NULL,
  `telegram_meta`    JSON DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_telegram_id` (`telegram_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagamentos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`   INT UNSIGNED NOT NULL,
  `produto_id`   INT UNSIGNED DEFAULT NULL,
  `funil_id`     INT UNSIGNED DEFAULT NULL,
  `tipo_oferta`  ENUM('principal','upsell','downsell') NOT NULL DEFAULT 'principal',
  `orderbump_id` INT UNSIGNED DEFAULT NULL,
  `txid`         VARCHAR(120) NOT NULL UNIQUE,
  `valor`        DECIMAL(10,2) NOT NULL,
  `status`       ENUM('pendente','pago','expirado','cancelado') NOT NULL DEFAULT 'pendente',
  `qr_code`      LONGTEXT DEFAULT NULL,
  `qr_code_img`  LONGTEXT DEFAULT NULL,
  `paid_at`      DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_usuario_id` (`usuario_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_txid` (`txid`),
  CONSTRAINT `fk_pagamentos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo`       VARCHAR(50) NOT NULL,
  `mensagem`   TEXT NOT NULL,
  `dados`      JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depois importe o arquivo:
-- files/database_admin.sql
