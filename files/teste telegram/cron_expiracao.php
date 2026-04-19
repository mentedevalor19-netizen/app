<?php
/**
 * cron_expiracao.php
 * Remove usuários com acesso vencido do grupo Telegram
 *
 * Configure no cPanel (Hostinger) → Cron Jobs:
 *   Frequência: A cada hora (ou a cada dia)
 *   Comando:    php /home/usuario/public_html/cron_expiracao.php
 *
 * Ou via URL protegida (menos recomendado, mas funciona):
 *   wget -q -O /dev/null "https://seudominio.com.br/cron_expiracao.php?secret=CHAVE"
 *
 * ─── ATENÇÃO ─────────────────────────────────────────────────────────────────
 * Proteja este arquivo! Ele não deve ser acessível publicamente sem autenticação.
 * Use a validação pelo SECRET abaixo ou bloqueie via .htaccess.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ─── Proteção: aceita execução via CLI ou com secret via HTTP ────────────────

$via_cli = (php_sapi_name() === 'cli');
$via_web = isset($_GET['secret']) && $_GET['secret'] === WEBHOOK_SECRET;

if (!$via_cli && !$via_web) {
    http_response_code(403);
    exit("Acesso negado. Execute via cron ou forneça o secret correto.\n");
}

// ─── Início da execução ───────────────────────────────────────────────────────

$inicio = microtime(true);
echo_log("=== CRON EXPIRAÇÃO iniciado em " . date('Y-m-d H:i:s') . " ===");

$removidos  = 0;
$erros      = 0;
$processados = 0;

// ─── Busca usuários com acesso vencido que ainda estão no grupo ───────────────

$sql = "
    SELECT id, telegram_id, first_name, data_expiracao
    FROM usuarios
    WHERE status = 'ativo'
      AND data_expiracao IS NOT NULL
      AND data_expiracao < NOW()
      AND grupo_adicionado = 1
    ORDER BY data_expiracao ASC
    LIMIT 100
";

try {
    $stmt = db()->query($sql);
    $usuarios_vencidos = $stmt->fetchAll();
} catch (PDOException $e) {
    echo_log("ERRO DB: " . $e->getMessage());
    exit(1);
}

$total = count($usuarios_vencidos);
echo_log("Encontrados $total usuário(s) com acesso vencido.");

if ($total === 0) {
    echo_log("Nada a fazer.");
    finalizar($inicio, $removidos, $erros);
    exit(0);
}

// ─── Processa cada usuário vencido ───────────────────────────────────────────

foreach ($usuarios_vencidos as $usuario) {
    $processados++;
    $uid         = (int) $usuario['id'];
    $telegram_id = (int) $usuario['telegram_id'];
    $nome        = $usuario['first_name'] ?? "ID:$telegram_id";
    $expirou_em  = $usuario['data_expiracao'];

    echo_log("[$processados/$total] Processando: $nome (telegram=$telegram_id, expirou=$expirou_em)");

    // 1. Tenta remover do grupo Telegram
    $removido = remover_do_grupo($telegram_id);

    if ($removido) {
        echo_log("  ✓ Removido do grupo com sucesso.");
        $removidos++;
    } else {
        echo_log("  ⚠ Falha ao remover do grupo (pode já ter saído). Continuando...");
        // Não conta como erro — o usuário pode ter saído manualmente
    }

    // 2. Atualiza status no banco independente de ter sido removido ou não
    expirar_usuario($uid);
    echo_log("  ✓ Status atualizado para 'expirado'.");

    // 3. Notifica o usuário via Telegram (opcional, mas é uma boa prática)
    $notificado = notificar_expiracao($telegram_id, $nome);
    if ($notificado) {
        echo_log("  ✓ Usuário notificado.");
    } else {
        echo_log("  ⚠ Não foi possível notificar (pode ter bloqueado o bot).");
    }

    // Pequena pausa para não sobrecarregar a API do Telegram
    usleep(300000); // 300ms
}

// ─── Limpeza adicional: marcar pagamentos expirados ──────────────────────────

try {
    $affected = db()->exec("
        UPDATE pagamentos
        SET status = 'expirado'
        WHERE status = 'pendente'
          AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ");
    echo_log("Pagamentos pendentes antigos marcados como expirado: $affected");
} catch (PDOException $e) {
    echo_log("ERRO ao limpar pagamentos: " . $e->getMessage());
}

// ─── Finalização ─────────────────────────────────────────────────────────────

finalizar($inicio, $removidos, $erros);
exit(0);

// ─── Funções auxiliares do cron ──────────────────────────────────────────────

/**
 * Notifica usuário que o acesso expirou, com botão para renovar
 */
function notificar_expiracao(int $telegram_id, string $nome): bool {
    $nome = htmlspecialchars($nome);
    $res = enviar_mensagem($telegram_id,
        "⏰ <b>Seu acesso expirou!</b>\n\n" .
        "Olá, <b>$nome</b>! Seu período de acesso ao grupo chegou ao fim.\n\n" .
        "Para continuar tendo acesso, renove agora mesmo:\n\n" .
        "💰 " . NOME_PRODUTO . " por <b>" . formatar_valor(VALOR_ACESSO) . "</b>",
        [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🔄 Renovar acesso - ' . formatar_valor(VALOR_ACESSO), 'callback_data' => 'comprar'],
                ]]
            ])
        ]
    );

    return isset($res['ok']) && $res['ok'] === true;
}

/**
 * Imprime log no terminal (CLI) e também salva no log do sistema
 */
function echo_log(string $msg): void {
    $linha = "[" . date('H:i:s') . "] $msg";
    echo $linha . "\n";
    log_evento('cron', $msg);
}

/**
 * Exibe e salva resumo de execução
 */
function finalizar(float $inicio, int $removidos, int $erros): void {
    $tempo = round(microtime(true) - $inicio, 2);
    echo_log("=== CRON finalizado em {$tempo}s | Removidos: $removidos | Erros: $erros ===");
}
