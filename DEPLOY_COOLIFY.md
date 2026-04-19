# Deploy com Coolify + Supabase + n8n

Este projeto agora esta preparado para rodar no `Coolify`, com o `n8n` na mesma VPS e o banco principal no `Supabase`.

## Arquivos desta stack

- `database_supabase.sql`
- `deploy/coolify/docker-compose.yml`
- `deploy/coolify/env.example`
- `deploy/coolify/docker/php-apache/Dockerfile`

## Arquitetura recomendada

- `app`: painel admin + bot Telegram + webhooks
- `worker`: processa fluxos, order bump, downsell, mailing e expiracao em loop
- `n8n`: automacoes e remarketing
- `Supabase`: banco PostgreSQL externo

## 1. Preparar o Supabase

1. Crie um projeto no Supabase.
2. Abra o SQL Editor.
3. Execute o arquivo `database_supabase.sql`.
4. Guarde a connection string do Postgres.

Recomendacao pratica:

- Se sua VPS tiver apenas IPv4, prefira o `Session Pooler` do Supabase na porta `5432`.
- Se usar pooler, mantenha `DB_EMULATE_PREPARES=1`.
- Se sua VPS tiver IPv6 e voce quiser conexao direta, pode usar o host direto do banco.

## 2. Subir no Coolify

1. Crie uma nova aplicacao no Coolify usando `Docker Compose`.
2. Aponte para este repositorio.
3. Use o arquivo `deploy/coolify/docker-compose.yml`.
4. Copie `deploy/coolify/env.example` para as variaveis da aplicacao e preencha tudo.

## 3. Dominios no Coolify

Configure um dominio para cada servico:

- `app` na porta interna `80`
- `n8n` na porta interna `5678`

Exemplo:

- `https://app.seudominio.com` para o app
- `https://n8n.seudominio.com` para o n8n

## 4. Banco do app

As variaveis mais importantes do app sao:

- `DB_DRIVER=pgsql`
- `DB_DSN=pgsql:host=...;port=5432;dbname=postgres;sslmode=require`
- `DB_USER=postgres.SEUPROJECTREF`
- `DB_PASS=...`
- `DB_SCHEMA=public`
- `DB_EMULATE_PREPARES=1`

Se preferir, pode deixar `DB_DSN` vazio e usar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS`.

## 5. n8n na mesma VPS

O compose ja sobe o `n8n` com volume persistente. Preencha pelo menos:

- `N8N_HOST`
- `N8N_EDITOR_BASE_URL`
- `N8N_PUBLIC_WEBHOOK_URL`
- `N8N_BASIC_AUTH_PASSWORD`
- `N8N_ENCRYPTION_KEY`

Para o app enviar eventos ao n8n, voce pode usar:

- URL publica: `https://n8n.seudominio.com/webhook/app-events`
- URL interna da rede Docker: `http://n8n:5678/webhook/app-events`

## 6. Workers e cron

Voce nao precisa criar cron separado no sistema para o basico. O servico `worker` ja roda em loop e executa:

- `php /var/www/html/worker_runner.php all`
- `php /var/www/html/cron_expiracao.php`

As variaveis de ajuste sao:

- `WORKER_LIMIT`
- `WORKER_SLEEP`

## 7. Configurar os webhooks finais

Depois do deploy:

1. Abra o painel em `Configuracoes`.
2. Confira `Base URL`, `Webhook secret`, token do Telegram e credenciais da Ecompag.
3. Rode `setup_webhook.php?token=SEU_SEGREDO`.
4. Valide o webhook Pix.
5. Preencha os webhooks opcionais em:
   - `Order Bump`
   - `Upsell`
   - `Downsell`
   - `Remarketing`

## 8. O que ja ficou pronto no projeto

- `/start` em 3 etapas: imagem/video, audio separado e CTA com botoes
- cadastro completo do lead no banco no primeiro `/start`
- webhooks por `order bump`, `upsell`, `downsell` e `remarketing`
- eventos para `lead_start`, `pix_gerado`, `pagamento_aprovado`, `pack_entregue`, `acesso_expirado`, `orderbump_ofertado`, `upsell_ofertado` e `downsell_ofertado`
- painel com menu separado para `Order Bump`, `Upsell`, `Downsell` e `Remarketing`

## 9. Migracao de uma instalacao MySQL antiga

Se voce ainda estiver num banco MySQL antigo antes de ir para o Supabase:

1. exporte os dados
2. atualize a estrutura com `database_update_funis.sql`
3. migre os dados para o Postgres
4. depois aponte o Coolify para o Supabase
