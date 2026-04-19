-- ============================================================
-- database.sql
-- Estrutura do banco de dados
-- Importar via phpMyAdmin ou linha de comando MySQL
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-03:00";  -- Horário de Brasília

-- ------------------------------------------------------------
-- Tabela: usuarios
-- Armazena cada usuário do Telegram e seu status de acesso
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `telegram_id`     BIGINT          NOT NULL UNIQUE,          -- ID único do Telegram
  `username`        VARCHAR(100)    DEFAULT NULL,             -- @username (pode ser nulo)
  `first_name`      VARCHAR(100)    DEFAULT NULL,             -- Primeiro nome
  `status`          ENUM('pendente','ativo','expirado')
                                    NOT NULL DEFAULT 'pendente',
  `data_expiracao`  DATETIME        DEFAULT NULL,             -- Quando o acesso expira
  `grupo_adicionado` TINYINT(1)     NOT NULL DEFAULT 0,       -- 1 = está no grupo
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_telegram_id`   (`telegram_id`),
  INDEX `idx_status`        (`status`),
  INDEX `idx_data_expiracao`(`data_expiracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Tabela: pagamentos
-- Registra cada tentativa/cobrança Pix
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pagamentos` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT UNSIGNED    NOT NULL,
  `txid`        VARCHAR(150)    NOT NULL UNIQUE,              -- ID da transação Ecompag
  `valor`       DECIMAL(10,2)   NOT NULL,
  `status`      ENUM('pendente','pago','cancelado','expirado')
                                NOT NULL DEFAULT 'pendente',
  `qr_code`     TEXT            DEFAULT NULL,                 -- Payload copia-e-cola
  `qr_code_img` TEXT            DEFAULT NULL,                 -- Base64 ou URL do QR Code
  `paid_at`     DATETIME        DEFAULT NULL,                 -- Quando foi confirmado
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  INDEX `idx_txid`       (`txid`),
  INDEX `idx_status`     (`status`),
  INDEX `idx_usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Tabela: logs (opcional — registra eventos importantes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo`       VARCHAR(50)  NOT NULL,                         -- ex: 'webhook_pix', 'cron'
  `mensagem`   TEXT         NOT NULL,
  `dados`      JSON         DEFAULT NULL,                     -- payload raw
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tipo`(`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
