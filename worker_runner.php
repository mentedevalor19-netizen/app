<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Use este arquivo via CLI.\n");
}

$mode = strtolower(trim((string) ($argv[1] ?? 'all')));
$limit = isset($argv[2]) ? max(1, min(100, (int) $argv[2])) : 20;

$aliases = [
    'mailing' => 'mailing',
    'mailings' => 'mailing',
    'downsell' => 'downsells',
    'downsells' => 'downsells',
    'flow' => 'flows',
    'flows' => 'flows',
    'all' => 'all',
];

if (!isset($aliases[$mode])) {
    fwrite(STDERR, "Uso: php worker_runner.php [all|mailing|downsells|flows] [limit]\n");
    exit(1);
}

$mode = $aliases[$mode];

$jobs = [
    'mailing' => static fn(int $jobLimit): array => executar_worker_com_lock('mailing', static fn(): array => processar_mailings_pendentes($jobLimit)),
    'downsells' => static fn(int $jobLimit): array => executar_worker_com_lock('downsells', static fn(): array => processar_downsells_pendentes($jobLimit)),
    'flows' => static fn(int $jobLimit): array => executar_worker_com_lock('flows', static fn(): array => processar_fluxos_pendentes($jobLimit)),
];

$runs = [];

if ($mode === 'all') {
    foreach (['mailing', 'downsells', 'flows'] as $job) {
        $runs[$job] = $jobs[$job]($limit);
    }
} else {
    $runs[$mode] = $jobs[$mode]($limit);
}

$summary = [
    'completed' => 0,
    'locked' => 0,
    'errors' => 0,
];

foreach ($runs as $run) {
    if (($run['status'] ?? '') === 'completed') {
        $summary['completed']++;
    } elseif (($run['status'] ?? '') === 'locked') {
        $summary['locked']++;
    } else {
        $summary['errors']++;
    }
}

$response = [
    'ok' => $summary['errors'] === 0,
    'mode' => $mode,
    'limit' => $limit,
    'summary' => $summary,
    'runs' => $runs,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($response['ok'] ? 0 : 1);
