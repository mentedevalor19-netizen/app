<?php
/**
 * index.php
 * Webhook principal do Telegram Bot
 *
 * Configure este arquivo como webhook:
 *   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://seudominio.com.br/index.php
 *
 * O Telegram enviará um POST com JSON toda vez que houver interação no bot
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/gerar_pix.php';

// ─── 1. Receber e validar payload ────────────────────────────────────────────

// Telegram sempre envia POST com JSON
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(200); // Sempre retornar 200 para o Telegram
    exit;
}

$update = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE || empty($update)) {
    http_response_code(200);
    exit;
}

log_evento('telegram_update', 'Update recebido', $update);

// ─── 2. Identificar tipo de interação ────────────────────────────────────────

// Mensagem de texto (comandos como /start)
if (isset($update['message'])) {
    processar_mensagem($update['message']);
}

// Callback query (botões inline)
if (isset($update['callback_query'])) {
    processar_callback($update['callback_query']);
}

// Sempre retornar 200 OK para o Telegram
http_response_code(200);
exit;

// ─── 3. Processar mensagem de texto ──────────────────────────────────────────

function processar_mensagem(array $message): void {
    $chat_id    = $message['chat']['id'];
    $texto      = trim($message['text'] ?? '');
    $first_name = $message['from']['first_name'] ?? 'Usuário';
    $username   = $message['from']['username']   ?? '';

    // Salva ou atualiza usuário no banco
    $usuario = upsert_usuario($chat_id, $first_name, $username);

    // ── Comando /start ──
    if ($texto === '/start') {
        handle_start($chat_id, $usuario);
        return;
    }

    // ── Comando /status ──
    if ($texto === '/status') {
        handle_status($chat_id, $usuario);
        return;
    }

    // ── Comando /renovar ──
    if ($texto === '/renovar') {
        handle_comprar($chat_id, $usuario);
        return;
    }

    // Mensagem não reconhecida
    enviar_mensagem($chat_id,
        "Olá! Use /start para começar ou /status para ver seu acesso. 😊"
    );
}

// ─── 4. Handlers de comandos ─────────────────────────────────────────────────

function handle_start(int $chat_id, array $usuario): void {
    $nome = htmlspecialchars($usuario['first_name']);

    // Se já tem acesso ativo
    if ($usuario['status'] === 'ativo' && $usuario['data_expiracao'] > date('Y-m-d H:i:s')) {
        $expira = date('d/m/Y', strtotime($usuario['data_expiracao']));
        enviar_mensagem($chat_id,
            "✅ Olá, <b>$nome</b>! Seu acesso está <b>ativo</b>.\n" .
            "📅 Válido até: <b>$expira</b>\n\n" .
            "Use /renovar para estender seu acesso.",
            ['reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🔄 Renovar acesso', 'callback_data' => 'comprar'],
                ]]
            ])]
        );
        return;
    }

    // Novo usuário ou expirado
    enviar_mensagem($chat_id,
        "👋 Olá, <b>$nome</b>! Bem-vindo!\n\n" .
        "🔐 Para acessar nosso grupo exclusivo, adquira seu acesso:\n\n" .
        "📦 <b>" . NOME_PRODUTO . "</b>\n" .
        "💰 <b>Valor: " . formatar_valor(VALOR_ACESSO) . "</b>\n" .
        "⏳ Duração: <b>" . DIAS_ACESSO . " dias</b>\n\n" .
        "Pagamento 100% via <b>Pix</b> — aprovação instantânea! ⚡",
        [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🛒 Comprar acesso - ' . formatar_valor(VALOR_ACESSO), 'callback_data' => 'comprar'],
                ]]
            ])
        ]
    );
}

function handle_status(int $chat_id, array $usuario): void {
    $status_map = [
        'pendente'  => '⏳ Pendente (aguardando pagamento)',
        'ativo'     => '✅ Ativo',
        'expirado'  => '❌ Expirado',
    ];

    $status_texto = $status_map[$usuario['status']] ?? 'Desconhecido';
    $expira_texto = '';

    if ($usuario['data_expiracao']) {
        $expira_texto = "\n📅 Expira em: <b>" . date('d/m/Y H:i', strtotime($usuario['data_expiracao'])) . "</b>";
    }

    enviar_mensagem($chat_id,
        "📊 <b>Seu status:</b>\n\n" .
        "🔹 Status: <b>$status_texto</b>$expira_texto\n\n" .
        ($usuario['status'] !== 'ativo' ? "Use /start para adquirir acesso." : ""),
    );
}

function handle_comprar(int $chat_id, array $usuario): void {
    // Avisa que está processando
    enviar_mensagem($chat_id, "⏳ Gerando seu Pix... aguarde!");

    // Gera cobrança Pix
    $pix = gerar_pix_para_usuario($usuario);

    if (!$pix) {
        enviar_mensagem($chat_id,
            "❌ Ocorreu um erro ao gerar o Pix. Tente novamente em alguns minutos.\n" .
            "Se o problema persistir, entre em contato com o suporte."
        );
        return;
    }

    $valor_fmt = formatar_valor($pix['valor']);
    $qr_code   = $pix['qr_code'];

    // Mensagem com instruções de pagamento
    $mensagem_pix =
        "✅ <b>Pix gerado com sucesso!</b>\n\n" .
        "💰 Valor: <b>$valor_fmt</b>\n" .
        "⏰ QR Code válido por 1 hora\n\n" .
        "📋 <b>Copia e Cola:</b>\n" .
        "<code>$qr_code</code>\n\n" .
        "ℹ️ Após o pagamento, o acesso será liberado <b>automaticamente</b> em até 1 minuto. ⚡";

    enviar_mensagem($chat_id, $mensagem_pix);

    // Envia QR Code como imagem (se disponível)
    if (!empty($pix['qr_img'])) {
        // Remove prefixo data URI se presente
        $img_data = preg_replace('/^data:image\/\w+;base64,/', '', $pix['qr_img']);

        // Envia como foto via sendPhoto com multipart
        enviar_qrcode_imagem($chat_id, $img_data);
    }
}

/**
 * Envia QR Code como imagem para o Telegram (upload base64 → multipart)
 */
function enviar_qrcode_imagem(int $chat_id, string $base64): void {
    $imageData = base64_decode($base64);
    if (!$imageData) return;

    $boundary = uniqid('tg_');
    $body     = "--$boundary\r\n";
    $body    .= "Content-Disposition: form-data; name=\"chat_id\"\r\n\r\n$chat_id\r\n";
    $body    .= "--$boundary\r\n";
    $body    .= "Content-Disposition: form-data; name=\"photo\"; filename=\"qrcode.png\"\r\n";
    $body    .= "Content-Type: image/png\r\n\r\n";
    $body    .= $imageData . "\r\n";
    $body    .= "--$boundary--\r\n";

    $ch = curl_init(TELEGRAM_API . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: multipart/form-data; boundary=$boundary"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    log_evento('qrcode_enviado', "chat_id=$chat_id resultado=" . substr($res, 0, 200));
}

// ─── 5. Processar callback (botões inline) ───────────────────────────────────

function processar_callback(array $callback): void {
    $chat_id    = $callback['from']['id'];
    $data       = $callback['data'] ?? '';
    $first_name = $callback['from']['first_name'] ?? 'Usuário';
    $username   = $callback['from']['username']   ?? '';
    $message_id = $callback['message']['message_id'] ?? null;

    // Responde o callback para remover o "relógio" do botão
    telegram_request('answerCallbackQuery', [
        'callback_query_id' => $callback['id'],
    ]);

    $usuario = upsert_usuario($chat_id, $first_name, $username);

    switch ($data) {
        case 'comprar':
            handle_comprar($chat_id, $usuario);
            break;

        default:
            enviar_mensagem($chat_id, "❓ Opção não reconhecida.");
    }
}
