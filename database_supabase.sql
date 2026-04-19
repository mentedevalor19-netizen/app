-- ============================================================
-- database_supabase.sql
-- Estrutura completa para Supabase / PostgreSQL
-- ============================================================

SET TIME ZONE 'America/Sao_Paulo';

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$;

CREATE TABLE IF NOT EXISTS usuarios (
  id bigserial PRIMARY KEY,
  telegram_id bigint NOT NULL UNIQUE,
  username varchar(100),
  first_name varchar(100),
  last_name varchar(100),
  language_code varchar(16),
  chat_type varchar(30),
  chat_username varchar(100),
  is_premium smallint NOT NULL DEFAULT 0,
  nome_pagador varchar(150),
  cpf varchar(14),
  start_payload varchar(255),
  estado_bot varchar(50) NOT NULL DEFAULT '',
  status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'ativo', 'expirado')),
  data_expiracao timestamptz,
  grupo_adicionado smallint NOT NULL DEFAULT 0,
  last_seen_at timestamptz,
  ultimo_start_em timestamptz,
  telegram_meta jsonb,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_usuarios_telegram_id ON usuarios (telegram_id);
CREATE INDEX IF NOT EXISTS idx_usuarios_status ON usuarios (status);

CREATE TABLE IF NOT EXISTS pagamentos (
  id bigserial PRIMARY KEY,
  usuario_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  produto_id bigint,
  funil_id bigint,
  tipo_oferta text NOT NULL DEFAULT 'principal' CHECK (tipo_oferta IN ('principal', 'upsell', 'downsell')),
  orderbump_id bigint,
  txid varchar(120) NOT NULL UNIQUE,
  valor numeric(10, 2) NOT NULL,
  status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'pago', 'expirado', 'cancelado')),
  qr_code text,
  qr_code_img text,
  paid_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_pagamentos_usuario_id ON pagamentos (usuario_id);
CREATE INDEX IF NOT EXISTS idx_pagamentos_status ON pagamentos (status);
CREATE INDEX IF NOT EXISTS idx_pagamentos_txid ON pagamentos (txid);

CREATE TABLE IF NOT EXISTS logs (
  id bigserial PRIMARY KEY,
  tipo varchar(50) NOT NULL,
  mensagem text NOT NULL,
  dados jsonb,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_logs_tipo ON logs (tipo);

CREATE TABLE IF NOT EXISTS admins (
  id bigserial PRIMARY KEY,
  nome varchar(100) NOT NULL,
  email varchar(150) NOT NULL UNIQUE,
  senha_hash varchar(255) NOT NULL,
  nivel text NOT NULL DEFAULT 'admin' CHECK (nivel IN ('super', 'admin', 'viewer')),
  ultimo_login timestamptz,
  ativo smallint NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO admins (id, nome, email, senha_hash, nivel)
VALUES (
  1,
  'Administrador',
  'admin@admin.com',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'super'
)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('admins', 'id'), COALESCE((SELECT MAX(id) FROM admins), 1), true);

CREATE TABLE IF NOT EXISTS sessoes_admin (
  id bigserial PRIMARY KEY,
  admin_id bigint NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
  token varchar(64) NOT NULL UNIQUE,
  ip varchar(45),
  user_agent varchar(255),
  expira_em timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sessoes_admin_token ON sessoes_admin (token);

CREATE TABLE IF NOT EXISTS produtos (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  descricao text,
  valor numeric(10, 2) NOT NULL,
  dias_acesso integer NOT NULL DEFAULT 30,
  tipo text NOT NULL DEFAULT 'grupo' CHECK (tipo IN ('grupo', 'pack')),
  pack_link text,
  ativo smallint NOT NULL DEFAULT 1,
  ordem integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO produtos (id, nome, descricao, valor, dias_acesso, tipo, ordem)
VALUES (1, 'Acesso VIP - 30 dias', 'Acesso completo ao grupo por 30 dias', 29.90, 30, 'grupo', 1)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('produtos', 'id'), COALESCE((SELECT MAX(id) FROM produtos), 1), true);

CREATE INDEX IF NOT EXISTS idx_produtos_ativo ON produtos (ativo);

CREATE TABLE IF NOT EXISTS configuracoes (
  id bigserial PRIMARY KEY,
  chave varchar(100) NOT NULL UNIQUE,
  valor text,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_configuracoes_chave ON configuracoes (chave);

CREATE TABLE IF NOT EXISTS funis (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  descricao text,
  headline varchar(255),
  mensagem_upsell text,
  upsell_desconto_percentual numeric(5, 2) NOT NULL DEFAULT 0.00,
  upsell_media_tipo text NOT NULL DEFAULT 'none' CHECK (upsell_media_tipo IN ('none', 'photo', 'video', 'audio', 'document')),
  upsell_media_url text,
  upsell_webhook_url text,
  upsell_webhook_secret varchar(255),
  produto_principal_id bigint NOT NULL REFERENCES produtos(id) ON DELETE CASCADE,
  upsell_produto_id bigint REFERENCES produtos(id) ON DELETE SET NULL,
  ativo smallint NOT NULL DEFAULT 1,
  ordem integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_funis_ativo ON funis (ativo);

CREATE TABLE IF NOT EXISTS orderbumps (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  produto_principal_id bigint NOT NULL REFERENCES produtos(id) ON DELETE CASCADE,
  produto_id bigint NOT NULL REFERENCES produtos(id) ON DELETE CASCADE,
  desconto_percentual numeric(5, 2) NOT NULL DEFAULT 0.00,
  mensagem text,
  media_tipo text NOT NULL DEFAULT 'none' CHECK (media_tipo IN ('none', 'photo', 'video', 'audio', 'document')),
  media_url text,
  webhook_url text,
  webhook_secret varchar(255),
  ativo smallint NOT NULL DEFAULT 1,
  ordem integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orderbumps_principal ON orderbumps (produto_principal_id);
CREATE INDEX IF NOT EXISTS idx_orderbumps_ativo ON orderbumps (ativo);

CREATE TABLE IF NOT EXISTS downsells (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  funil_id bigint NOT NULL REFERENCES funis(id) ON DELETE CASCADE,
  produto_id bigint NOT NULL REFERENCES produtos(id) ON DELETE CASCADE,
  desconto_percentual numeric(5, 2) NOT NULL DEFAULT 0.00,
  delay_minutes integer NOT NULL DEFAULT 30,
  mensagem text,
  media_tipo text NOT NULL DEFAULT 'none' CHECK (media_tipo IN ('none', 'photo', 'video', 'audio', 'document')),
  media_url text,
  webhook_url text,
  webhook_secret varchar(255),
  ativo smallint NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_downsells_funil ON downsells (funil_id);
CREATE INDEX IF NOT EXISTS idx_downsells_ativo ON downsells (ativo);

CREATE TABLE IF NOT EXISTS downsell_disparos (
  id bigserial PRIMARY KEY,
  downsell_id bigint NOT NULL REFERENCES downsells(id) ON DELETE CASCADE,
  pagamento_id bigint NOT NULL REFERENCES pagamentos(id) ON DELETE CASCADE,
  usuario_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'enviado' CHECK (status IN ('enviado', 'falhou')),
  sent_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (downsell_id, pagamento_id)
);

CREATE INDEX IF NOT EXISTS idx_downsell_disparos_status ON downsell_disparos (status);

CREATE TABLE IF NOT EXISTS fluxos (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  descricao text,
  gatilho text NOT NULL DEFAULT 'start' CHECK (gatilho IN ('start', 'comando', 'cpf_salvo', 'pix_gerado', 'pagamento_aprovado', 'pack_entregue', 'acesso_expirado')),
  comando varchar(50),
  descricao_comando varchar(150),
  produto_id bigint REFERENCES produtos(id) ON DELETE SET NULL,
  funil_id bigint REFERENCES funis(id) ON DELETE SET NULL,
  ativo smallint NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fluxos_gatilho ON fluxos (gatilho);
CREATE INDEX IF NOT EXISTS idx_fluxos_ativo ON fluxos (ativo);

CREATE TABLE IF NOT EXISTS fluxo_etapas (
  id bigserial PRIMARY KEY,
  fluxo_id bigint NOT NULL REFERENCES fluxos(id) ON DELETE CASCADE,
  nome varchar(150) NOT NULL,
  ordem integer NOT NULL DEFAULT 0,
  delay_minutes integer NOT NULL DEFAULT 0,
  mensagem text,
  media_tipo text NOT NULL DEFAULT 'none' CHECK (media_tipo IN ('none', 'photo', 'video', 'audio', 'document')),
  media_url text,
  botao_tipo text NOT NULL DEFAULT 'none' CHECK (botao_tipo IN ('none', 'url', 'planos', 'packs', 'produto')),
  botao_texto varchar(120),
  botao_url text,
  botao_produto_id bigint REFERENCES produtos(id) ON DELETE SET NULL,
  ativo smallint NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fluxo_etapas_fluxo ON fluxo_etapas (fluxo_id);
CREATE INDEX IF NOT EXISTS idx_fluxo_etapas_ativo ON fluxo_etapas (ativo);

CREATE TABLE IF NOT EXISTS fluxo_execucoes (
  id bigserial PRIMARY KEY,
  fluxo_id bigint NOT NULL REFERENCES fluxos(id) ON DELETE CASCADE,
  etapa_id bigint NOT NULL REFERENCES fluxo_etapas(id) ON DELETE CASCADE,
  usuario_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  gatilho varchar(50) NOT NULL,
  referencia_tipo varchar(50),
  referencia_id bigint,
  payload_context text,
  status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'enviado', 'falhou', 'cancelado')),
  scheduled_at timestamptz NOT NULL,
  sent_at timestamptz,
  last_error text,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fluxo_execucoes_status ON fluxo_execucoes (status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_fluxo_execucoes_fluxo ON fluxo_execucoes (fluxo_id);

CREATE TABLE IF NOT EXISTS mailings (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  filtro_status text NOT NULL DEFAULT 'todos' CHECK (filtro_status IN ('todos', 'ativo', 'pendente', 'expirado')),
  mensagem text NOT NULL,
  media_tipo text NOT NULL DEFAULT 'none' CHECK (media_tipo IN ('none', 'photo', 'video', 'audio', 'document')),
  media_url text,
  botao_texto varchar(120),
  botao_url text,
  status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'processando', 'concluido', 'cancelado')),
  total_alvo integer NOT NULL DEFAULT 0,
  total_enviado integer NOT NULL DEFAULT 0,
  total_falhou integer NOT NULL DEFAULT 0,
  created_by bigint REFERENCES admins(id) ON DELETE SET NULL,
  started_at timestamptz,
  finished_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mailings_status ON mailings (status);

CREATE TABLE IF NOT EXISTS mailing_envios (
  id bigserial PRIMARY KEY,
  mailing_id bigint NOT NULL REFERENCES mailings(id) ON DELETE CASCADE,
  usuario_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'enviado', 'falhou')),
  tentativas integer NOT NULL DEFAULT 0,
  last_error text,
  sent_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mailing_envios_status ON mailing_envios (status);
CREATE INDEX IF NOT EXISTS idx_mailing_envios_mailing ON mailing_envios (mailing_id);
CREATE INDEX IF NOT EXISTS idx_mailing_envios_usuario ON mailing_envios (usuario_id);

CREATE TABLE IF NOT EXISTS remarketing_webhooks (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  evento text NOT NULL DEFAULT 'lead_start' CHECK (evento IN ('lead_start', 'pix_gerado', 'pagamento_aprovado', 'pack_entregue', 'acesso_expirado', 'orderbump_ofertado', 'upsell_ofertado', 'downsell_ofertado')),
  webhook_url text NOT NULL,
  webhook_secret varchar(255),
  ativo smallint NOT NULL DEFAULT 1,
  ordem integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_remarketing_evento ON remarketing_webhooks (evento, ativo);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_pagamentos_produto') THEN
    ALTER TABLE pagamentos
      ADD CONSTRAINT fk_pagamentos_produto
      FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_pagamentos_funil') THEN
    ALTER TABLE pagamentos
      ADD CONSTRAINT fk_pagamentos_funil
      FOREIGN KEY (funil_id) REFERENCES funis(id) ON DELETE SET NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_pagamentos_orderbump') THEN
    ALTER TABLE pagamentos
      ADD CONSTRAINT fk_pagamentos_orderbump
      FOREIGN KEY (orderbump_id) REFERENCES orderbumps(id) ON DELETE SET NULL;
  END IF;
END $$;

DROP TRIGGER IF EXISTS trg_usuarios_updated_at ON usuarios;
CREATE TRIGGER trg_usuarios_updated_at BEFORE UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_pagamentos_updated_at ON pagamentos;
CREATE TRIGGER trg_pagamentos_updated_at BEFORE UPDATE ON pagamentos FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_produtos_updated_at ON produtos;
CREATE TRIGGER trg_produtos_updated_at BEFORE UPDATE ON produtos FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_configuracoes_updated_at ON configuracoes;
CREATE TRIGGER trg_configuracoes_updated_at BEFORE UPDATE ON configuracoes FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_funis_updated_at ON funis;
CREATE TRIGGER trg_funis_updated_at BEFORE UPDATE ON funis FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_orderbumps_updated_at ON orderbumps;
CREATE TRIGGER trg_orderbumps_updated_at BEFORE UPDATE ON orderbumps FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_downsells_updated_at ON downsells;
CREATE TRIGGER trg_downsells_updated_at BEFORE UPDATE ON downsells FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_fluxos_updated_at ON fluxos;
CREATE TRIGGER trg_fluxos_updated_at BEFORE UPDATE ON fluxos FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_fluxo_etapas_updated_at ON fluxo_etapas;
CREATE TRIGGER trg_fluxo_etapas_updated_at BEFORE UPDATE ON fluxo_etapas FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_remarketing_updated_at ON remarketing_webhooks;
CREATE TRIGGER trg_remarketing_updated_at BEFORE UPDATE ON remarketing_webhooks FOR EACH ROW EXECUTE FUNCTION set_updated_at();
