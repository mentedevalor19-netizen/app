<?php
/**
 * webhook_pix.php
 * Endpoint que recebe notificações de pagamento da Ecompag (PIX confirmado)
 *
 * Configure este URL no painel da Ecompag como Webhook de notificação:
 *   https://seudominio.com.br/webhook_pix.php
 *
 * A Ecompag enviará um POST JSON quando um Pix for pago.
 * Sempre retorne HTTP 200, caso contrário a Ecompag retentará a notificação.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ─── 1. Segurança: aceitar apenas POST ───────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ─── 2. Leitura do payload ───────────────────────────────────────────────────

$input = file_get_contents('php://input');

if (empty($input)) {
    http_response_code(200); // Retorna 200 mesmo em payload vazio
    exit;
}

$payload = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    log_evento('webhook_json_invalido', "Payload inválido: $input");
    http_response_code(200);
    exit;
}

log_evento('webhook_pix_recebido', 'Payload recebido', $payload);

// ─── 3. Validação de segurança ────────────────────────────────────────────────
//
// A Ecompag envia um header "x-webhook-secret" ou similar.
// Verifique a documentação da Ecompag para o header exato.
// Aqui validamos um token secreto nos headers.

$secret_header = $_SERVER['HTTP_X_WEBHOOK_SECRET']
              ?? $_SERVER['HTTP_AUTHORIZATION']
              ?? '';

// Remove prefixo "Bearer " se houver
$secret_header = str_replace('Bearer ', '', $secret_header);

if (!empty(WEBHOOK_SECRET) && $secret_header !== WEBHOOK_SECRET) {
    log_evento('webhook_pix_auth_falhou', "Header inválido: $secret_header");
    // Retorna 200 para não revelar que a chave foi rejeitada
    http_response_code(200);
    exit;
}

// ─── 4. Processar notificação ────────────────────────────────────────────────
//
// Estrutura típica da Ecompag (baseada no padrão BACEN PIX):
// {
//   "evento": "cob.pago",
//   "pix": [{
//     "txid": "abc123...",
//     "valor": "29.90",
//     "pagador": { "nome": "João", "cpf": "..." },
//     "horario": "2024-01-01T10:00:00Z"
//   }]
// }

$evento = $payload['evento'] ?? $payload['event'] ?? '';

// Verifica se é evento de pagamento confirmado
$eventos_pagamento = ['cob.pago', 'PIX_RECEIVED', 'payment.confirmed', 'pix.paid'];

if (!in_array($evento, $eventos_pagamento, true)) {
    // Outro tipo de evento (ex: cobrança expirada) — ignora
    log_evento('webhook_pix_evento_ignorado', "Evento: $evento");
    http_response_code(200);
    exit;
}

// ─── 5. Processar cada Pix recebido ──────────────────────────────────────────
//
// A Ecompag pode enviar múltiplos Pix em um único webhook

$pix_list = $payload['pix'] ?? [$payload]; // Normaliza para array

foreach ($pix_list as $pix) {
    $txid  = $pix['txid']  ?? $pix['endToEndId'] ?? null;
    $valor = (float) ($pix['valor'] ?? 0);

    if (!$txid) {
        log_evento('webhook_pix_sem_txid', 'Pix sem txid', $pix);
        continue;
    }

    processar_pix_confirmado($txid, $valor, $pix);
}

// ─── 6. Responder 200 OK ─────────────────────────────────────────────────────

http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;

// ─── Função principal de processamento ───────────────────────────────────────

/**
 * Processa um Pix confirmado:
 * 1. Localiza pagamento pendente pelo txid
 * 2. Atualiza status para "pago"
 * 3. Ativa o usuário no banco
 * 4. Gera link de convite do grupo Telegram
 * 5. Envia mensagem de boas-vindas ao usuário
 */
function processar_pix_confirmado(string $txid, float $valor, array $dados_pix): void {
    // 1. Busca e confirma pagamento no banco
    $pagamento = confirmar_pagamento($txid);

    if (!$pagamento) {
        // Pode ser pagamento duplicado ou txid desconhecido
        log_evento('webhook_pix_duplicado', "txid=$txid já processado ou não encontrado");
        return;
    }

    $usuario_id = (int) $pagamento['usuario_id'];

    // 2. Busca dados do usuário
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        log_evento('webhook_pix_usuario_nao_encontrado', "usuario_id=$usuario_id txid=$txid");
        return;
    }

    $telegram_id = (int) $usuario['telegram_id'];

    // 3. Ativa acesso (define data_expiracao = hoje + DIAS_ACESSO)
    ativar_usuario($usuario_id);

    // 4. Calcula data de expiração para exibição
    $expiracao = date('d/m/Y', strtotime('+' . DIAS_ACESSO . ' days'));

    // 5. Gera link de convite único para o grupo
    $link_convite = gerar_link_convite();

    // 6. Atualiza flag de grupo_adicionado
    if ($link_convite) {
        db()->prepare('UPDATE usuarios SET grupo_adicionado = 1 WHERE id = ?')
            ->execute([$usuario_id]);
    }

    // 7. Monta mensagem de confirmação
    $nome = htmlspecialchars($usuario['first_name'] ?? 'Usuário');
    $valor_fmt = formatar_valor($pagamento['valor']);

    if ($link_convite) {
        $mensagem =
            "🎉 <b>Pagamento confirmado!</b>\n\n" .
            "Olá, <b>$nome</b>! Seu Pix de <b>$valor_fmt</b> foi recebido.\n\n" .
            "✅ Acesso liberado até: <b>$expiracao</b>\n\n" .
            "👇 <b>Clique para entrar no grupo:</b>\n" .
            $link_convite . "\n\n" .
            "⚠️ Este link é de uso único e válido por 1 hora.\n" .
            "Não compartilhe com ninguém!";
    } else {
        $mensagem =
            "🎉 <b>Pagamento confirmado!</b>\n\n" .
            "Olá, <b>$nome</b>! Seu acesso foi liberado até <b>$expiracao</b>.\n\n" .
            "⚠️ Houve um problema ao gerar o link do grupo. " .
            "Nossa equipe entrará em contato em breve.";

        log_evento('convite_falhou', "usuario_id=$usuario_id telegram_id=$telegram_id");
    }

    // 8. Envia mensagem ao usuário
    $resultado = enviar_mensagem($telegram_id, $mensagem);

    log_evento(
        'pix_processado',
        "txid=$txid usuario_id=$usuario_id telegram_id=$telegram_id",
        ['pagamento' => $pagamento, 'resultado_telegram' => $resultado]
    );
}
