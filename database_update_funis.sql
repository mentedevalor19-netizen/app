-- Execute este arquivo em uma instalacao ja existente
-- para ativar configuracoes, funis, upsell, downsell e mailing.

CREATE TABLE IF NOT EXISTS `configuracoes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`      VARCHAR(100) NOT NULL UNIQUE,
  `valor`      LONGTEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `funis` (
  `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                       VARCHAR(150) NOT NULL,
  `descricao`                  TEXT DEFAULT NULL,
  `headline`                   VARCHAR(255) DEFAULT NULL,
  `mensagem_upsell`            TEXT DEFAULT NULL,
  `upsell_desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `upsell_media_tipo`          ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `upsell_media_url`           TEXT DEFAULT NULL,
  `produto_principal_id`       INT UNSIGNED NOT NULL,
  `upsell_produto_id`          INT UNSIGNED DEFAULT NULL,
  `ativo`                      TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`                      INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_funil_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orderbumps` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                VARCHAR(150) NOT NULL,
  `produto_principal_id` INT UNSIGNED NOT NULL,
  `produto_id`          INT UNSIGNED NOT NULL,
  `desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `mensagem`            TEXT DEFAULT NULL,
  `media_tipo`          ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`           TEXT DEFAULT NULL,
  `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
  `ordem`               INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_orderbump_principal` (`produto_principal_id`),
  INDEX `idx_orderbump_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `downsells` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                VARCHAR(150) NOT NULL,
  `funil_id`            INT UNSIGNED NOT NULL,
  `produto_id`          INT UNSIGNED NOT NULL,
  `desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `delay_minutes`       INT UNSIGNED NOT NULL DEFAULT 30,
  `mensagem`            TEXT DEFAULT NULL,
  `media_tipo`          ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none',
  `media_url`           TEXT DEFAULT NULL,
  `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_downsell_funil` (`funil_id`),
  INDEX `idx_downsell_ativo` (`ativo`)
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
  INDEX `idx_downsell_disparos_status` (`status`)
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
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxos_gatilho` (`gatilho`),
  INDEX `idx_fluxos_ativo` (`ativo`)
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
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxo_etapas_fluxo` (`fluxo_id`),
  INDEX `idx_fluxo_etapas_ativo` (`ativo`)
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
  INDEX `idx_fluxo_execucoes_fluxo` (`fluxo_id`)
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
  INDEX `idx_mailing_status` (`status`)
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
  INDEX `idx_mailing_envios_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `last_name` VARCHAR(100) DEFAULT NULL AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `language_code` VARCHAR(16) DEFAULT NULL AFTER `last_name`,
  ADD COLUMN IF NOT EXISTS `chat_type` VARCHAR(30) DEFAULT NULL AFTER `language_code`,
  ADD COLUMN IF NOT EXISTS `chat_username` VARCHAR(100) DEFAULT NULL AFTER `chat_type`,
  ADD COLUMN IF NOT EXISTS `is_premium` TINYINT(1) NOT NULL DEFAULT 0 AFTER `chat_username`,
  ADD COLUMN IF NOT EXISTS `nome_pagador` VARCHAR(150) DEFAULT NULL AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `cpf` VARCHAR(14) DEFAULT NULL AFTER `nome_pagador`,
  ADD COLUMN IF NOT EXISTS `start_payload` VARCHAR(255) DEFAULT NULL AFTER `cpf`,
  ADD COLUMN IF NOT EXISTS `estado_bot` VARCHAR(50) NOT NULL DEFAULT '' AFTER `start_payload`,
  ADD COLUMN IF NOT EXISTS `last_seen_at` DATETIME DEFAULT NULL AFTER `grupo_adicionado`,
  ADD COLUMN IF NOT EXISTS `ultimo_start_em` DATETIME DEFAULT NULL AFTER `last_seen_at`,
  ADD COLUMN IF NOT EXISTS `telegram_meta` JSON DEFAULT NULL AFTER `ultimo_start_em`;

ALTER TABLE `pagamentos`
  ADD COLUMN IF NOT EXISTS `produto_id` INT UNSIGNED DEFAULT NULL AFTER `usuario_id`,
  ADD COLUMN IF NOT EXISTS `funil_id` INT UNSIGNED DEFAULT NULL AFTER `produto_id`,
  ADD COLUMN IF NOT EXISTS `tipo_oferta` ENUM('principal','upsell') NOT NULL DEFAULT 'principal' AFTER `funil_id`,
  ADD COLUMN IF NOT EXISTS `orderbump_id` INT UNSIGNED DEFAULT NULL AFTER `tipo_oferta`;

ALTER TABLE `pagamentos`
  MODIFY COLUMN `tipo_oferta` ENUM('principal','upsell','downsell') NOT NULL DEFAULT 'principal';

ALTER TABLE `produtos`
  ADD COLUMN IF NOT EXISTS `tipo` ENUM('grupo','pack') NOT NULL DEFAULT 'grupo' AFTER `dias_acesso`,
  ADD COLUMN IF NOT EXISTS `pack_link` TEXT DEFAULT NULL AFTER `tipo`;

ALTER TABLE `funis`
  ADD COLUMN IF NOT EXISTS `upsell_desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `mensagem_upsell`,
  ADD COLUMN IF NOT EXISTS `upsell_media_tipo` ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none' AFTER `upsell_desconto_percentual`,
  ADD COLUMN IF NOT EXISTS `upsell_media_url` TEXT DEFAULT NULL AFTER `upsell_media_tipo`,
  ADD COLUMN IF NOT EXISTS `upsell_webhook_url` TEXT DEFAULT NULL AFTER `upsell_media_url`,
  ADD COLUMN IF NOT EXISTS `upsell_webhook_secret` VARCHAR(255) DEFAULT NULL AFTER `upsell_webhook_url`;

ALTER TABLE `downsells`
  ADD COLUMN IF NOT EXISTS `desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `produto_id`,
  ADD COLUMN IF NOT EXISTS `delay_minutes` INT UNSIGNED NOT NULL DEFAULT 30 AFTER `desconto_percentual`,
  ADD COLUMN IF NOT EXISTS `mensagem` TEXT DEFAULT NULL AFTER `delay_minutes`,
  ADD COLUMN IF NOT EXISTS `media_tipo` ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none' AFTER `mensagem`,
  ADD COLUMN IF NOT EXISTS `media_url` TEXT DEFAULT NULL AFTER `media_tipo`,
  ADD COLUMN IF NOT EXISTS `webhook_url` TEXT DEFAULT NULL AFTER `media_url`,
  ADD COLUMN IF NOT EXISTS `webhook_secret` VARCHAR(255) DEFAULT NULL AFTER `webhook_url`,
  ADD COLUMN IF NOT EXISTS `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `media_url`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `orderbumps`
  ADD COLUMN IF NOT EXISTS `desconto_percentual` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `produto_id`,
  ADD COLUMN IF NOT EXISTS `mensagem` TEXT DEFAULT NULL AFTER `desconto_percentual`,
  ADD COLUMN IF NOT EXISTS `media_tipo` ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none' AFTER `mensagem`,
  ADD COLUMN IF NOT EXISTS `media_url` TEXT DEFAULT NULL AFTER `media_tipo`,
  ADD COLUMN IF NOT EXISTS `webhook_url` TEXT DEFAULT NULL AFTER `media_url`,
  ADD COLUMN IF NOT EXISTS `webhook_secret` VARCHAR(255) DEFAULT NULL AFTER `webhook_url`,
  ADD COLUMN IF NOT EXISTS `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `media_url`,
  ADD COLUMN IF NOT EXISTS `ordem` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ativo`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `fluxos`
  MODIFY COLUMN `gatilho` ENUM('start','comando','cpf_salvo','pix_gerado','pagamento_aprovado','pack_entregue','acesso_expirado') NOT NULL DEFAULT 'start',
  ADD COLUMN IF NOT EXISTS `descricao` TEXT DEFAULT NULL AFTER `nome`,
  ADD COLUMN IF NOT EXISTS `comando` VARCHAR(50) DEFAULT NULL AFTER `gatilho`,
  ADD COLUMN IF NOT EXISTS `descricao_comando` VARCHAR(150) DEFAULT NULL AFTER `comando`,
  ADD COLUMN IF NOT EXISTS `produto_id` INT UNSIGNED DEFAULT NULL AFTER `descricao_comando`,
  ADD COLUMN IF NOT EXISTS `funil_id` INT UNSIGNED DEFAULT NULL AFTER `produto_id`,
  ADD COLUMN IF NOT EXISTS `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `funil_id`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `fluxo_etapas`
  ADD COLUMN IF NOT EXISTS `ordem` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `nome`,
  ADD COLUMN IF NOT EXISTS `delay_minutes` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ordem`,
  ADD COLUMN IF NOT EXISTS `mensagem` TEXT DEFAULT NULL AFTER `delay_minutes`,
  ADD COLUMN IF NOT EXISTS `media_tipo` ENUM('none','photo','video','audio','document') NOT NULL DEFAULT 'none' AFTER `mensagem`,
  ADD COLUMN IF NOT EXISTS `media_url` TEXT DEFAULT NULL AFTER `media_tipo`,
  ADD COLUMN IF NOT EXISTS `botao_tipo` ENUM('none','url','planos','packs','produto') NOT NULL DEFAULT 'none' AFTER `media_url`,
  ADD COLUMN IF NOT EXISTS `botao_texto` VARCHAR(120) DEFAULT NULL AFTER `botao_tipo`,
  ADD COLUMN IF NOT EXISTS `botao_url` TEXT DEFAULT NULL AFTER `botao_texto`,
  ADD COLUMN IF NOT EXISTS `botao_produto_id` INT UNSIGNED DEFAULT NULL AFTER `botao_url`,
  ADD COLUMN IF NOT EXISTS `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `botao_produto_id`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `fluxo_execucoes`
  ADD COLUMN IF NOT EXISTS `gatilho` VARCHAR(50) NOT NULL DEFAULT 'start' AFTER `usuario_id`,
  ADD COLUMN IF NOT EXISTS `referencia_tipo` VARCHAR(50) DEFAULT NULL AFTER `gatilho`,
  ADD COLUMN IF NOT EXISTS `referencia_id` INT UNSIGNED DEFAULT NULL AFTER `referencia_tipo`,
  ADD COLUMN IF NOT EXISTS `payload_context` LONGTEXT DEFAULT NULL AFTER `referencia_id`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('pendente','enviado','falhou','cancelado') NOT NULL DEFAULT 'pendente' AFTER `payload_context`,
  ADD COLUMN IF NOT EXISTS `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`,
  ADD COLUMN IF NOT EXISTS `sent_at` DATETIME DEFAULT NULL AFTER `scheduled_at`,
  ADD COLUMN IF NOT EXISTS `last_error` TEXT DEFAULT NULL AFTER `sent_at`;

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
