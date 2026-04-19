-- ============================================================
-- database_admin.sql
-- Estrutura adicional do painel administrativo
-- Execute depois do database.sql principal
-- ============================================================

CREATE TABLE IF NOT EXISTS `admins` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`         VARCHAR(100) NOT NULL,
  `email`        VARCHAR(150) NOT NULL UNIQUE,
  `senha_hash`   VARCHAR(255) NOT NULL,
  `nivel`        ENUM('super','admin','viewer') NOT NULL DEFAULT 'admin',
  `ultimo_login` DATETIME DEFAULT NULL,
  `ativo`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admins` (`id`, `nome`, `email`, `senha_hash`, `nivel`)
VALUES (
  1,
  'Administrador',
  'admin@admin.com',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'super'
);

CREATE TABLE IF NOT EXISTS `sessoes_admin` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64) NOT NULL UNIQUE,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `expira_em`  DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_admin_token` (`token`),
  CONSTRAINT `fk_sessao_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produtos` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`        VARCHAR(150) NOT NULL,
  `descricao`   TEXT DEFAULT NULL,
  `valor`       DECIMAL(10,2) NOT NULL,
  `dias_acesso` INT UNSIGNED NOT NULL DEFAULT 30,
  `tipo`        ENUM('grupo','pack') NOT NULL DEFAULT 'grupo',
  `pack_link`   TEXT DEFAULT NULL,
  `ativo`       TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_produto_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `produtos` (`id`, `nome`, `descricao`, `valor`, `dias_acesso`, `tipo`, `ordem`)
VALUES (1, 'Acesso VIP - 30 dias', 'Acesso completo ao grupo por 30 dias', 29.90, 30, 'grupo', 1);

CREATE TABLE IF NOT EXISTS `configuracoes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`      VARCHAR(100) NOT NULL UNIQUE,
  `valor`      LONGTEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_config_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `funis` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                  VARCHAR(150) NOT NULL,
  `descricao`             TEXT DEFAULT NULL,
  `headline`              VARCHAR(255) DEFAULT NULL,
  `mensagem_upsell`       TEXT DEFAULT NULL,
  `upsell_desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `upsell_media_tipo`     ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `upsell_media_url`      TEXT DEFAULT NULL,
  `upsell_webhook_url`    TEXT DEFAULT NULL,
  `upsell_webhook_secret` VARCHAR(255) DEFAULT NULL,
  `produto_principal_id`  INT UNSIGNED NOT NULL,
  `upsell_produto_id`     INT UNSIGNED DEFAULT NULL,
  `ativo`                 TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`                 INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_funil_ativo` (`ativo`),
  CONSTRAINT `fk_funil_produto_principal` FOREIGN KEY (`produto_principal_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_funil_produto_upsell` FOREIGN KEY (`upsell_produto_id`) REFERENCES `produtos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orderbumps` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                VARCHAR(150) NOT NULL,
  `produto_principal_id` INT UNSIGNED NOT NULL,
  `produto_id`          INT UNSIGNED NOT NULL,
  `desconto_percentual`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `mensagem`            TEXT DEFAULT NULL,
  `media_tipo`          ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`           TEXT DEFAULT NULL,
  `webhook_url`         TEXT DEFAULT NULL,
  `webhook_secret`      VARCHAR(255) DEFAULT NULL,
  `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`               INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_orderbump_principal` (`produto_principal_id`),
  INDEX `idx_orderbump_ativo` (`ativo`),
  CONSTRAINT `fk_orderbump_produto_principal` FOREIGN KEY (`produto_principal_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orderbump_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `downsells` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                 VARCHAR(150) NOT NULL,
  `funil_id`             INT UNSIGNED NOT NULL,
  `produto_id`           INT UNSIGNED NOT NULL,
  `desconto_percentual`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `delay_minutes`        INT UNSIGNED NOT NULL DEFAULT 30,
  `mensagem`             TEXT DEFAULT NULL,
  `media_tipo`           ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`            TEXT DEFAULT NULL,
  `webhook_url`          TEXT DEFAULT NULL,
  `webhook_secret`       VARCHAR(255) DEFAULT NULL,
  `ativo`                TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_downsell_funil` (`funil_id`),
  INDEX `idx_downsell_ativo` (`ativo`),
  CONSTRAINT `fk_downsell_funil` FOREIGN KEY (`funil_id`) REFERENCES `funis`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_downsell_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `downsell_disparos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `downsell_id`  INT UNSIGNED NOT NULL,
  `pagamento_id` INT UNSIGNED NOT NULL,
  `usuario_id`   INT UNSIGNED NOT NULL,
  `status`       ENUM('enviado','falhou') NOT NULL DEFAULT 'enviado',
  `sent_at`      DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_downsell_pagamento` (`downsell_id`, `pagamento_id`),
  INDEX `idx_downsell_disparos_status` (`status`),
  CONSTRAINT `fk_downsell_disparo_downsell` FOREIGN KEY (`downsell_id`) REFERENCES `downsells`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_downsell_disparo_pagamento` FOREIGN KEY (`pagamento_id`) REFERENCES `pagamentos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_downsell_disparo_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxos` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`       VARCHAR(150) NOT NULL,
  `descricao`  TEXT DEFAULT NULL,
  `gatilho`    ENUM('start','comando','cpf_salvo','pix_gerado','pagamento_aprovado','pack_entregue','acesso_expirado') NOT NULL DEFAULT 'start',
  `comando`    VARCHAR(50) DEFAULT NULL,
  `descricao_comando` VARCHAR(150) DEFAULT NULL,
  `produto_id` INT UNSIGNED DEFAULT NULL,
  `funil_id`   INT UNSIGNED DEFAULT NULL,
  `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxos_gatilho` (`gatilho`),
  INDEX `idx_fluxos_ativo` (`ativo`),
  CONSTRAINT `fk_fluxos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fluxos_funil` FOREIGN KEY (`funil_id`) REFERENCES `funis`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxo_etapas` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fluxo_id`         INT UNSIGNED NOT NULL,
  `nome`             VARCHAR(150) NOT NULL,
  `ordem`            INT UNSIGNED NOT NULL DEFAULT 0,
  `delay_minutes`    INT UNSIGNED NOT NULL DEFAULT 0,
  `mensagem`         TEXT DEFAULT NULL,
  `media_tipo`       ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`        TEXT DEFAULT NULL,
  `botao_tipo`       ENUM('none','url','planos','packs','produto') NOT NULL DEFAULT 'none',
  `botao_texto`      VARCHAR(120) DEFAULT NULL,
  `botao_url`        TEXT DEFAULT NULL,
  `botao_produto_id` INT UNSIGNED DEFAULT NULL,
  `ativo`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxo_etapas_fluxo` (`fluxo_id`),
  INDEX `idx_fluxo_etapas_ativo` (`ativo`),
  CONSTRAINT `fk_fluxo_etapas_fluxo` FOREIGN KEY (`fluxo_id`) REFERENCES `fluxos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fluxo_etapas_produto` FOREIGN KEY (`botao_produto_id`) REFERENCES `produtos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxo_execucoes` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fluxo_id`        INT UNSIGNED NOT NULL,
  `etapa_id`        INT UNSIGNED NOT NULL,
  `usuario_id`      INT UNSIGNED NOT NULL,
  `gatilho`         VARCHAR(50) NOT NULL,
  `referencia_tipo` VARCHAR(50) DEFAULT NULL,
  `referencia_id`   INT UNSIGNED DEFAULT NULL,
  `payload_context` LONGTEXT DEFAULT NULL,
  `status`          ENUM('pendente','enviado','falhou','cancelado') NOT NULL DEFAULT 'pendente',
  `scheduled_at`    DATETIME NOT NULL,
  `sent_at`         DATETIME DEFAULT NULL,
  `last_error`      TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxo_execucoes_status` (`status`, `scheduled_at`),
  INDEX `idx_fluxo_execucoes_fluxo` (`fluxo_id`),
  CONSTRAINT `fk_fluxo_execucoes_fluxo` FOREIGN KEY (`fluxo_id`) REFERENCES `fluxos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fluxo_execucoes_etapa` FOREIGN KEY (`etapa_id`) REFERENCES `fluxo_etapas`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fluxo_execucoes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`          VARCHAR(150) NOT NULL,
  `filtro_status` ENUM('todos','ativo','pendente','expirado') NOT NULL DEFAULT 'todos',
  `mensagem`      TEXT NOT NULL,
  `media_tipo`    ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`     TEXT DEFAULT NULL,
  `botao_texto`   VARCHAR(120) DEFAULT NULL,
  `botao_url`     TEXT DEFAULT NULL,
  `status`        ENUM('pendente','processando','concluido','cancelado') NOT NULL DEFAULT 'pendente',
  `total_alvo`    INT UNSIGNED NOT NULL DEFAULT 0,
  `total_enviado` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_falhou`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `started_at`    DATETIME DEFAULT NULL,
  `finished_at`   DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mailing_status` (`status`),
  CONSTRAINT `fk_mailings_admin` FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailing_envios` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailing_id` INT UNSIGNED NOT NULL,
  `usuario_id` INT UNSIGNED NOT NULL,
  `status`     ENUM('pendente','enviado','falhou') NOT NULL DEFAULT 'pendente',
  `tentativas` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT DEFAULT NULL,
  `sent_at`    DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mailing_envios_status` (`status`),
  INDEX `idx_mailing_envios_mailing` (`mailing_id`),
  INDEX `idx_mailing_envios_usuario` (`usuario_id`),
  CONSTRAINT `fk_mailing_envios_mailing` FOREIGN KEY (`mailing_id`) REFERENCES `mailings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mailing_envios_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remarketing_webhooks` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`           VARCHAR(150) NOT NULL,
  `evento`         ENUM('lead_start','pix_gerado','pagamento_aprovado','pack_entregue','acesso_expirado','orderbump_ofertado','upsell_ofertado','downsell_ofertado') NOT NULL DEFAULT 'lead_start',
  `webhook_url`    TEXT NOT NULL,
  `webhook_secret` VARCHAR(255) DEFAULT NULL,
  `ativo`          TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`          INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_remarketing_evento` (`evento`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
