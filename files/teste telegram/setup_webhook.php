<?php
/**
 * setup_webhook.php
 * Script auxiliar para configurar o webhook do Telegram
 *
 * Execute UMA VEZ após subir os arquivos:
 *   https://seudominio.com.br/setup_webhook.php?secret=SUA_CHAVE
 *
 * DELETE este arquivo após usar!
 */

require_once __DIR__ . '/config.php';

// Proteção básica
if (!isset($_GET['secret']) || $_GET['secret'] !== WEBHOOK_SECRET) {
    http_response_code(403);
    exit('Acesso negado. Forneça ?secret=SUA_CHAVE_WEBHOOK');
}

$webhook_url = BASE_URL . '/index.php';

// ─── Registra webhook ────────────────────────────────────────────────────────
$set_url = TELEGRAM_API . '/setWebhook';
$payload = json_encode([
    'url'             => $webhook_url,
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]);

$ch = curl_init($set_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

// ─── Verifica info do webhook ─────────────────────────────────────────────────
$info_ch = curl_init(TELEGRAM_API . '/getWebhookInfo');
curl_setopt($info_ch, CURLOPT_RETURNTRANSFER, true);
$info_response = curl_exec($info_ch);
curl_close($info_ch);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><title>Setup Webhook</title></head>
<body style="font-family:monospace;padding:20px">
<h2>🤖 Configuração do Webhook</h2>
<p><strong>URL do Webhook:</strong> <?= htmlspecialchars($webhook_url) ?></p>
<hr>
<h3>Resultado do setWebhook:</h3>
<pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<hr>
<h3>Info atual do Webhook:</h3>
<pre><?= htmlspecialchars(json_encode(json_decode($info_response, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<hr>
<p style="color:red">⚠️ <strong>DELETE este arquivo do servidor após usar!</strong></p>
</body>
</html>
