<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$viaCli = PHP_SAPI === 'cli';
$viaWeb = request_has_valid_webhook_token();

if (!$viaCli && !$viaWeb) {
    http_response_code(403);
    exit('Acesso negado.');
}

$limit = $viaCli
    ? (isset($argv[1]) ? max(1, min(100, (int) $argv[1])) : 20)
    : (isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20);

$result = executar_worker_com_lock('flows', static fn(): array => processar_fluxos_pendentes($limit));
$response = array_merge($result, ['limit' => $limit]);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if ($viaCli) {
    echo PHP_EOL;
    exit(($response['ok'] ?? false) ? 0 : 1);
}
