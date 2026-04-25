<?php
date_default_timezone_set('America/Sao_Paulo');

/**
 * Configurações centrais do projeto.
 * Prefira preencher via variáveis de ambiente na hospedagem.
 */

function config_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
$protocol = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$detectedSiteUrl = rtrim($protocol . '://' . $host, '/');

define('SITE_URL', rtrim(config_env('SITE_URL', $detectedSiteUrl), '/'));
define('BASE_URL', rtrim(config_env('BASE_URL', SITE_URL), '/'));

// PestoPay
define('PESTOPAY_API_BASE', rtrim(config_env('PESTOPAY_API_BASE', 'https://app.pestopay.com.br/api/v1'), '/'));
define('PESTOPAY_PUBLIC_KEY', config_env('PESTOPAY_PUBLIC_KEY', config_env('ECOMPAG_CLIENT_ID', '')));
define('PESTOPAY_SECRET_KEY', config_env('PESTOPAY_SECRET_KEY', config_env('ECOMPAG_CLIENT_SECRET', '')));
define('ECOMPAG_API_BASE', PESTOPAY_API_BASE);
define('ECOMPAG_CLIENT_ID', PESTOPAY_PUBLIC_KEY);
define('ECOMPAG_CLIENT_SECRET', PESTOPAY_SECRET_KEY);
define('WEBHOOK_SECRET', config_env('WEBHOOK_SECRET', 'Be!12345'));
define('WEBHOOK_URL', BASE_URL . '/webhook.php');
define('ECOMPAG_NOTIFY_URL', BASE_URL . '/webhook_pix.php?token=' . rawurlencode(WEBHOOK_SECRET));

// Telegram
define('TELEGRAM_BOT_TOKEN', config_env('TELEGRAM_BOT_TOKEN', '8695895909:AAGsCqiRHmcXp8TsC4pCZ2MWiYKbFZ54ukg'));
define('TELEGRAM_GROUP_ID', config_env('TELEGRAM_GROUP_ID', '-3987730402'));
define('TELEGRAM_API', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);
define('TELEGRAM_WEBHOOK_URL', BASE_URL . '/telegram_webhook.php');

// n8n
define('N8N_WEBHOOK_URL', rtrim(config_env('N8N_WEBHOOK_URL', ''), '/'));
define('N8N_SECRET', config_env('N8N_SECRET', ''));
define('N8N_TIMEOUT', (int) config_env('N8N_TIMEOUT', '15'));

// Banco de dados
define('DB_DRIVER', strtolower(config_env('DB_DRIVER', 'mysql')));
define('DB_DSN', config_env('DB_DSN', ''));
define('DB_HOST', config_env('DB_HOST', 'localhost'));
define('DB_PORT', (int) config_env('DB_PORT', DB_DRIVER === 'pgsql' ? '5432' : '3306'));
define('DB_NAME', config_env('DB_NAME', 'u604809831_gerentetelegra'));
define('DB_USER', config_env('DB_USER', 'u604809831_gerentetelegra'));
define('DB_PASS', config_env('DB_PASS', 'Be!12345'));
define('DB_CHARSET', 'utf8mb4');
define('DB_SCHEMA', config_env('DB_SCHEMA', 'public'));
define('DB_SSLMODE', config_env('DB_SSLMODE', DB_DRIVER === 'pgsql' ? 'require' : 'prefer'));
define('DB_EMULATE_PREPARES', config_env('DB_EMULATE_PREPARES', DB_DRIVER === 'pgsql' ? '1' : '0'));

// Produto padrão do bot
define('DEFAULT_PRODUCT_NAME', config_env('DEFAULT_PRODUCT_NAME', 'Acesso VIP - 30 dias'));
define('DEFAULT_PRODUCT_DESCRIPTION', config_env('DEFAULT_PRODUCT_DESCRIPTION', 'Acesso completo ao grupo por 30 dias'));
define('DEFAULT_PRODUCT_PRICE', (float) config_env('DEFAULT_PRODUCT_PRICE', '29.90'));
define('DEFAULT_PRODUCT_DAYS', (int) config_env('DEFAULT_PRODUCT_DAYS', '30'));
define('NOME_PRODUTO', DEFAULT_PRODUCT_NAME);
define('VALOR_ACESSO', DEFAULT_PRODUCT_PRICE);
define('DIAS_ACESSO', DEFAULT_PRODUCT_DAYS);

// Site legado por arquivos
define('PAYMENTS_DIR', __DIR__ . '/payments');
define('PENDING_DIR', PAYMENTS_DIR . '/pending');
define('PAID_DIR', PAYMENTS_DIR . '/paid');
define('ACCESS_LINK', config_env('ACCESS_LINK', 'https://t.me/+SEU_LINK_DE_CONVITE'));
define('ACCESS_TOKEN_SALT', config_env('ACCESS_TOKEN_SALT', 'Be!12345'));

// E-mail legado
define('EMAIL_FROM', config_env('EMAIL_FROM', 'no-reply@seudominio.com'));
define('EMAIL_FROM_NAME', config_env('EMAIL_FROM_NAME', 'Seu Projeto'));
define('EMAIL_SUBJECT', config_env('EMAIL_SUBJECT', 'Pagamento Confirmado - Acesso Liberado!'));
define('SMTP_HOST', config_env('SMTP_HOST', 'smtp.hostinger.com'));
define('SMTP_PORT', (int) config_env('SMTP_PORT', '465'));
define('SMTP_USER', config_env('SMTP_USER', 'seu-email@seudominio.com'));
define('SMTP_PASSWORD', config_env('SMTP_PASSWORD', 'sua_senha_smtp'));

// Logs
define('LOGS_DIR', __DIR__ . '/logs');
define('LOG_FILE', LOGS_DIR . '/sistema.log');

ini_set('display_errors', config_env('APP_DEBUG', '0') === '1' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOGS_DIR . '/php_errors.log');
error_reporting(E_ALL);

foreach ([PAYMENTS_DIR, PENDING_DIR, PAID_DIR, LOGS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function generateUniqueId(): string
{
    return uniqid('payment_', true) . '_' . time();
}

function sanitize(?string $data): string
{
    return htmlspecialchars(strip_tags(trim((string) $data)), ENT_QUOTES, 'UTF-8');
}

function validarCPF(string $cpf): bool
{
    $cpf = preg_replace('/\D+/', '', $cpf);
    if (strlen($cpf) !== 11) {
        return false;
    }
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += (int) $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int) $cpf[$c] !== $d) {
            return false;
        }
    }

    return true;
}

function validarEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}
