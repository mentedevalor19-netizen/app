<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$token = $_GET['token'] ?? '';
if (!request_has_valid_webhook_token()) {
    http_response_code(403);
    exit('Acesso negado.');
}

$payload = json_encode([
    'url' => runtime_telegram_webhook_url(),
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init(runtime_telegram_api() . '/setWebhook');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$response = curl_exec($ch);
curl_close($ch);

$infoCh = curl_init(runtime_telegram_api() . '/getWebhookInfo');
curl_setopt($infoCh, CURLOPT_RETURNTRANSFER, true);
$info = curl_exec($infoCh);
curl_close($infoCh);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Webhook Telegram</title>
</head>
<body style="font-family:Arial,sans-serif;padding:24px">
    <h1>Configuração do webhook do Telegram</h1>
    <p><strong>Webhook configurado para:</strong> <?= htmlspecialchars(runtime_telegram_webhook_url()) ?></p>
    <h2>Resposta do setWebhook</h2>
    <pre><?= htmlspecialchars((string) $response) ?></pre>
    <h2>Webhook atual</h2>
    <pre><?= htmlspecialchars((string) $info) ?></pre>
    <p>Depois de usar este arquivo, remova-o do servidor.</p>
</body>
</html>
