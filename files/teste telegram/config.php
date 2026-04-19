<?php
/**
 * config.php
 * Configurações centrais do sistema
 * ATENÇÃO: Nunca exponha este arquivo publicamente!
 */

// ─── TELEGRAM ────────────────────────────────────────────────────────────────
define('TELEGRAM_BOT_TOKEN', 'SEU_TOKEN_AQUI');           // Token do @BotFather
define('TELEGRAM_GROUP_ID',  '-1001234567890');            // ID do grupo (negativo)
define('TELEGRAM_API',       'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);

// ─── ECOMPAG ─────────────────────────────────────────────────────────────────
define('ECOMPAG_API_URL',    'https://api.ecompag.com.br/v1'); // URL base da API
define('ECOMPAG_CLIENT_ID',  'SEU_CLIENT_ID');
define('ECOMPAG_SECRET',     'SEU_CLIENT_SECRET');
define('ECOMPAG_CHAVE_PIX',  'sua@chave.pix');             // Chave Pix cadastrada

// ─── PRODUTO ─────────────────────────────────────────────────────────────────
define('VALOR_ACESSO',       29.90);                       // Valor em reais
define('DIAS_ACESSO',        30);                          // Duração do acesso
define('NOME_PRODUTO',       'Acesso VIP - 30 dias');

// ─── BANCO DE DADOS ──────────────────────────────────────────────────────────
define('DB_HOST',   'localhost');
define('DB_NAME',   'nome_do_banco');
define('DB_USER',   'usuario_do_banco');
define('DB_PASS',   'senha_do_banco');
define('DB_CHARSET','utf8mb4');

// ─── SISTEMA ─────────────────────────────────────────────────────────────────
define('BASE_URL',        'https://seudominio.com.br');    // URL do seu site (sem barra final)
define('WEBHOOK_SECRET',  'chave_secreta_webhook_pix');    // String aleatória para validar webhook
define('LOG_FILE',        __DIR__ . '/logs/sistema.log');

// ─── ERROS (desligar em produção) ────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
error_reporting(E_ALL);

// Criar pasta de logs se não existir
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
