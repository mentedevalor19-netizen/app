# Deploy em VPS

Este projeto agora esta pronto para rodar em VPS mantendo o painel, o bot, os workers e as automacoes externas do `n8n` no mesmo stack.

## Stack recomendada

- Ubuntu 24.04
- Nginx
- PHP 8.2 ou superior com `curl`, `mbstring`, `pdo_mysql`, `openssl`
- MySQL 8 ou MariaDB 10.11+
- Certbot para SSL
- `cron` para workers
- Docker e Docker Compose se quiser subir app + banco + n8n em containers

## Estrutura sugerida

- Codigo: `/var/www/telegram-bot/current`
- Logs de app: dentro de `/logs`
- Nginx apontando para a pasta do projeto

## Passo a passo

1. Suba os arquivos do projeto para a VPS.
2. Importe o banco atual.
3. Ajuste [config.php](G:\Documentos Ryzen\Downloads\files\config.php) com as credenciais do banco da VPS.
4. Se quiser usar variaveis de ambiente, mantenha as constantes do arquivo como fallback.
5. Configure SSL e dominio.
6. Aponte novamente os webhooks:
   - Telegram: `setup_webhook.php`
   - PestoPay: `webhook_pix.php?token=...`
7. Configure os workers no cron com o `worker_runner.php`.
8. Se usar comandos personalizados nos fluxos, sincronize o menu do bot pelo painel de `Fluxos`.

## Deploy com Docker + n8n

Se quiser subir tudo em containers:

1. Entre em [deploy\vps](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps>).
2. Copie [deploy\vps\env.example](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\env.example:1>) para `.env`.
3. Ajuste dominio, segredos, Telegram, PestoPay e dados do n8n.
4. Suba a stack:

```bash
docker compose up -d --build
```

5. Configure o proxy reverso usando:
   - [deploy\vps\nginx-proxy-app.conf.example](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\nginx-proxy-app.conf.example:1>)
   - [deploy\vps\nginx-proxy-n8n.conf.example](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\nginx-proxy-n8n.conf.example:1>)
6. Configure o cron usando [deploy\vps\cron.docker.example](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\cron.docker.example:1>).

Arquivos do stack:

- [deploy\vps\docker-compose.yml](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\docker-compose.yml:1>)
- [deploy\vps\docker\php-apache\Dockerfile](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\docker\php-apache\Dockerfile:1>)
- [deploy\vps\docker\php-apache\allow-override.conf](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\docker\php-apache\allow-override.conf:1>)

## Migrando da hospedagem atual

1. Exporte o banco que hoje esta em producao.
2. Baixe os arquivos publicados atualmente.
3. Suba exatamente esta mesma codebase para a VPS.
4. Atualize o banco com [database_update_funis.sql](G:\Documentos Ryzen\Downloads\files\database_update_funis.sql) se a instancia antiga ainda nao tiver todas as tabelas e colunas novas.
5. Ajuste dominio, SSL, cron e credenciais.
6. Valide o painel, os workers e os webhooks ainda em um subdominio de teste.
7. So depois troque os webhooks oficiais do Telegram e da PestoPay para o dominio final da VPS.

## Variaveis de ambiente

Use [deploy\vps\env.example](G:\Documentos Ryzen\Downloads\files\deploy\vps\env.example) como base se quiser tirar credenciais sensiveis do `config.php`.

## Cron recomendado

Use o arquivo [deploy\vps\cron.example](G:\Documentos Ryzen\Downloads\files\deploy\vps\cron.example) como base.

Se estiver usando Docker, use [deploy\vps\cron.docker.example](<G:\Documentos Ryzen\Downloads\aplicativo vps\deploy\vps\cron.docker.example:1>).

## Workers

### Executar todos

```bash
php /var/www/telegram-bot/current/worker_runner.php all
```

### Executar so mailing

```bash
php /var/www/telegram-bot/current/worker_runner.php mailing
```

### Executar so downsells

```bash
php /var/www/telegram-bot/current/worker_runner.php downsells
```

### Executar so fluxos

```bash
php /var/www/telegram-bot/current/worker_runner.php flows
```

## Ordem de migracao

1. Subir VPS e banco.
2. Testar painel e login.
3. Testar `worker_runner.php`.
4. Testar `telegram_webhook.php`.
5. Testar `webhook_pix.php`.
6. Trocar webhooks do Telegram e da PestoPay.
7. Fazer um pagamento real de validacao.

## Onde o n8n entra melhor

O projeto pode continuar cuidando de:

- pagamentos
- liberacao de acesso
- convites de grupo
- filas internas
- workers de fluxos, downsells e mailing

E o `n8n` entra muito bem para:

- notificacoes de CRM
- enviar eventos para planilhas
- reengajamento multi-canal
- integracoes com WhatsApp, e-mail, Discord, Slack
- alertas internos
- automacoes de vendas fora do Telegram

## Eventos enviados para o n8n

O app envia `POST` para o webhook configurado com estes eventos:

- `pix_gerado`
- `pagamento_aprovado`
- `pack_entregue`
- `acesso_expirado`

Headers:

- `X-App-Event`
- `X-App-Secret`

Payload base:

```json
{
  "event": "pagamento_aprovado",
  "sent_at": "2026-04-18T15:30:00-03:00",
  "source": "telegram-bot-app",
  "site_url": "https://app.seu-dominio.com",
  "base_url": "https://app.seu-dominio.com",
  "payload": {}
}
```

Minha recomendacao e manter o PHP como nucleo transacional e usar o `n8n` como camada de orquestracao.
