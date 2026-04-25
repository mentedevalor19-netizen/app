<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$viaCli = PHP_SAPI === 'cli';
$tenant = !$viaCli ? bootstrap_tenant_from_request() : null;
$viaWeb = !$viaCli && request_has_valid_webhook_token();

if (!$viaCli && ((tenants_enabled() && !$tenant) || !$viaWeb)) {
    http_response_code(403);
    exit('Acesso negado.');
}

$processarEscopoAtual = static function (): array {
    $now = db_now();
    $cutoffPagamento = date('Y-m-d H:i:s', strtotime('-2 hours'));

    $stmt = db()->prepare(
        "SELECT id, telegram_id, first_name, data_expiracao
         FROM usuarios
         WHERE " . tenant_scope_condition('usuarios') . "
           AND status = 'ativo'
           AND grupo_adicionado = 1
           AND data_expiracao IS NOT NULL
           AND data_expiracao < ?
         ORDER BY data_expiracao ASC
         LIMIT 100"
    );
    $stmt->execute([$now]);

    $usuarios = $stmt->fetchAll();
    $removidos = 0;

    foreach ($usuarios as $usuario) {
        remover_do_grupo((int) $usuario['telegram_id']);
        expirar_usuario((int) $usuario['id']);
        $removidos++;

        enviar_mensagem(
            (int) $usuario['telegram_id'],
            message_template('msg_expired')
        );

        disparar_fluxos('acesso_expirado', [
            'usuario' => array_merge($usuario, ['status' => 'expirado']),
            'expira' => !empty($usuario['data_expiracao']) ? date('d/m/Y H:i', strtotime((string) $usuario['data_expiracao'])) : '',
        ]);

        disparar_n8n_evento('acesso_expirado', [
            'usuario' => array_merge($usuario, ['status' => 'expirado']),
            'expira' => !empty($usuario['data_expiracao']) ? date('c', strtotime((string) $usuario['data_expiracao'])) : '',
        ]);
        disparar_remarketing_webhooks('acesso_expirado', [
            'usuario' => array_merge($usuario, ['status' => 'expirado']),
            'expira' => !empty($usuario['data_expiracao']) ? date('c', strtotime((string) $usuario['data_expiracao'])) : '',
        ]);
    }

    db()->prepare(
        "UPDATE pagamentos
         SET status = 'expirado'
         WHERE " . tenant_scope_condition('pagamentos') . "
           AND status = 'pendente'
           AND created_at < ?"
    )->execute([$cutoffPagamento]);

    log_evento('cron_expiracao', 'Cron executado com sucesso.', [
        'usuarios_processados' => count($usuarios),
        'removidos' => $removidos,
    ]);

    return [
        'usuarios_processados' => count($usuarios),
        'removidos' => $removidos,
    ];
};

$totalRemovidos = 0;
$totalUsuarios = 0;

if ($viaCli && tenants_enabled()) {
    $tenants = db()->query("SELECT * FROM tenants WHERE status = 'ativo' ORDER BY id ASC")->fetchAll();
    foreach ($tenants as $tenantRow) {
        set_current_tenant_context($tenantRow);
        $stats = $processarEscopoAtual();
        $totalRemovidos += (int) ($stats['removidos'] ?? 0);
        $totalUsuarios += (int) ($stats['usuarios_processados'] ?? 0);
    }
    set_current_tenant_context(null);
} else {
    $stats = $processarEscopoAtual();
    $totalRemovidos = (int) ($stats['removidos'] ?? 0);
    $totalUsuarios = (int) ($stats['usuarios_processados'] ?? 0);
}

echo "OK - usuarios vencidos processados: {$totalRemovidos} (varridos: {$totalUsuarios})\n";
