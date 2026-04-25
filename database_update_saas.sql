-- ============================================================
-- database_update_saas.sql
-- Migracao para modo SaaS / multi-tenant
-- ============================================================

SET TIME ZONE 'America/Sao_Paulo';

CREATE TABLE IF NOT EXISTS tenants (
  id bigserial PRIMARY KEY,
  nome varchar(150) NOT NULL,
  slug varchar(120) NOT NULL UNIQUE,
  status text NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'suspenso')),
  created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tenants (id, nome, slug, status)
VALUES (1, 'Workspace Principal', 'workspace-principal', 'ativo')
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('tenants', 'id'), COALESCE((SELECT MAX(id) FROM tenants), 1), true);

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE logs ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE admins ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE configuracoes ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE funis ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE orderbumps ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE downsells ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE downsell_disparos ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE fluxos ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE fluxo_etapas ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE fluxo_execucoes ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE mailings ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE mailing_envios ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE remarketing_webhooks ADD COLUMN IF NOT EXISTS tenant_id bigint REFERENCES tenants(id) ON DELETE CASCADE;

UPDATE usuarios SET tenant_id = COALESCE(tenant_id, 1);
UPDATE admins SET tenant_id = COALESCE(tenant_id, 1);
UPDATE produtos SET tenant_id = COALESCE(tenant_id, 1);
UPDATE configuracoes SET tenant_id = COALESCE(tenant_id, 1);
UPDATE funis SET tenant_id = COALESCE(tenant_id, 1);
UPDATE orderbumps SET tenant_id = COALESCE(tenant_id, 1);
UPDATE downsells SET tenant_id = COALESCE(tenant_id, 1);
UPDATE fluxos SET tenant_id = COALESCE(tenant_id, 1);
UPDATE mailings SET tenant_id = COALESCE(tenant_id, 1);
UPDATE remarketing_webhooks SET tenant_id = COALESCE(tenant_id, 1);
UPDATE pagamentos p
SET tenant_id = COALESCE(p.tenant_id, u.tenant_id, 1)
FROM usuarios u
WHERE u.id = p.usuario_id;
UPDATE downsell_disparos dd
SET tenant_id = COALESCE(dd.tenant_id, d.tenant_id, p.tenant_id, 1)
FROM downsells d
LEFT JOIN pagamentos p ON p.id = dd.pagamento_id
WHERE d.id = dd.downsell_id;
UPDATE fluxo_etapas fe
SET tenant_id = COALESCE(fe.tenant_id, f.tenant_id, 1)
FROM fluxos f
WHERE f.id = fe.fluxo_id;
UPDATE fluxo_execucoes fx
SET tenant_id = COALESCE(fx.tenant_id, f.tenant_id, u.tenant_id, 1)
FROM fluxos f
LEFT JOIN usuarios u ON u.id = fx.usuario_id
WHERE f.id = fx.fluxo_id;
UPDATE mailing_envios me
SET tenant_id = COALESCE(me.tenant_id, m.tenant_id, u.tenant_id, 1)
FROM mailings m
LEFT JOIN usuarios u ON u.id = me.usuario_id
WHERE m.id = me.mailing_id;
UPDATE logs SET tenant_id = COALESCE(tenant_id, 1);

ALTER TABLE usuarios ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE pagamentos ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE admins ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE produtos ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE configuracoes ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE funis ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE orderbumps ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE downsells ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE downsell_disparos ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE fluxos ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE fluxo_etapas ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE fluxo_execucoes ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE mailings ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE mailing_envios ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE remarketing_webhooks ALTER COLUMN tenant_id SET NOT NULL;

ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_telegram_id_key;
ALTER TABLE pagamentos DROP CONSTRAINT IF EXISTS pagamentos_txid_key;
ALTER TABLE configuracoes DROP CONSTRAINT IF EXISTS configuracoes_chave_key;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_usuarios_tenant_telegram ON usuarios (tenant_id, telegram_id);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_pagamentos_tenant_txid ON pagamentos (tenant_id, txid);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_configuracoes_tenant_chave ON configuracoes (tenant_id, chave);

CREATE INDEX IF NOT EXISTS idx_usuarios_tenant_status ON usuarios (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_pagamentos_tenant_status ON pagamentos (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_logs_tenant_tipo ON logs (tenant_id, tipo);
CREATE INDEX IF NOT EXISTS idx_produtos_tenant_ativo ON produtos (tenant_id, ativo);
CREATE INDEX IF NOT EXISTS idx_funis_tenant_ativo ON funis (tenant_id, ativo);
CREATE INDEX IF NOT EXISTS idx_orderbumps_tenant_ativo ON orderbumps (tenant_id, ativo);
CREATE INDEX IF NOT EXISTS idx_downsells_tenant_ativo ON downsells (tenant_id, ativo);
CREATE INDEX IF NOT EXISTS idx_fluxos_tenant_gatilho ON fluxos (tenant_id, gatilho);
CREATE INDEX IF NOT EXISTS idx_fluxo_etapas_tenant_fluxo ON fluxo_etapas (tenant_id, fluxo_id);
CREATE INDEX IF NOT EXISTS idx_fluxo_execucoes_tenant_status ON fluxo_execucoes (tenant_id, status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_mailings_tenant_status ON mailings (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_mailing_envios_tenant_status ON mailing_envios (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_remarketing_tenant_evento ON remarketing_webhooks (tenant_id, evento, ativo);

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'tenants' AND column_name = 'updated_at') THEN
    DROP TRIGGER IF EXISTS trg_tenants_updated_at ON tenants;
    CREATE TRIGGER trg_tenants_updated_at BEFORE UPDATE ON tenants FOR EACH ROW EXECUTE FUNCTION set_updated_at();
  END IF;
END $$;
