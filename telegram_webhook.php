<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$tenant = bootstrap_tenant_from_request();
if (tenants_enabled() && !$tenant) {
    http_response_code(200);
    exit;
}

if (runtime_has_webhook_secret()) {
    if (!request_has_valid_webhook_token()) {
        log_evento('telegram_webhook_secret_invalido', 'Tentativa de webhook do Telegram com token invalido.');
        http_response_code(200);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    exit;
}

$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(200);
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    log_evento('telegram_payload_invalido', 'JSON invalido recebido no webhook do Telegram.');
    http_response_code(200);
    exit;
}

log_evento('telegram_update', 'Update recebido', $update);

if (isset($update['message'])) {
    processar_mensagem_telegram($update['message']);
}

if (isset($update['callback_query'])) {
    processar_callback_telegram($update['callback_query']);
}

http_response_code(200);
exit;

function processar_mensagem_telegram(array $message): void
{
    $chatId = (int) ($message['chat']['id'] ?? 0);
    if ($chatId <= 0) {
        return;
    }

    $texto = trim((string) ($message['text'] ?? ''));
    $comando = extrair_comando_telegram($texto);
    $from = is_array($message['from'] ?? null) ? $message['from'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $firstName = (string) ($from['first_name'] ?? 'Usuario');
    $username = (string) ($from['username'] ?? '');
    $startPayload = $comando === '/start' ? extrair_start_payload($texto) : '';

    $usuario = upsert_usuario($chatId, $firstName, $username, [
        'last_name' => (string) ($from['last_name'] ?? ''),
        'language_code' => (string) ($from['language_code'] ?? ''),
        'is_premium' => !empty($from['is_premium']),
        'chat_type' => (string) ($chat['type'] ?? 'private'),
        'chat_username' => (string) ($chat['username'] ?? ''),
        'start_payload' => $startPayload,
        'is_start' => $comando === '/start',
        'from' => $from,
        'chat' => $chat,
        'message_id' => (int) ($message['message_id'] ?? 0),
        'text' => $texto,
        'message' => $message,
        'source' => 'message',
    ]);

    if (!empty($usuario['estado_bot'])) {
        atualizar_estado_bot((int) $usuario['id'], '');
        $usuario['estado_bot'] = '';
    }

    if ($comando === '/start') {
        $remarketingElegivel = !usuario_ja_virou_cliente($usuario);
        enviar_boas_vindas($usuario);
        disparar_fluxos('start', [
            'usuario' => $usuario,
            'comando' => $comando,
            'start_payload' => $startPayload,
        ]);
        disparar_n8n_evento('lead_start', [
            'usuario' => $usuario,
            'start_payload' => $startPayload,
        ]);
        if ($remarketingElegivel) {
            disparar_remarketing_webhooks('lead_start', [
                'usuario' => $usuario,
                'start_payload' => $startPayload,
                'remarketing' => [
                    'eligible' => true,
                    'reason' => 'nao_cliente',
                ],
            ]);
        } else {
            log_evento('remarketing_lead_start_skip', 'Lead start ignorado no remarketing porque o usuario ja virou cliente.', [
                'usuario_id' => (int) ($usuario['id'] ?? 0),
                'telegram_id' => (int) ($usuario['telegram_id'] ?? 0),
                'tenant' => current_tenant_slug(),
            ]);
        }
        return;
    }

    if ($comando === '/planos' || $comando === '/renovar') {
        enviar_lista_planos($usuario);
        return;
    }

    if ($comando === '/status') {
        enviar_status_usuario($usuario);
        return;
    }

    if ($comando !== '') {
        $fluxos = disparar_fluxos('comando', [
            'usuario' => $usuario,
            'comando' => $comando,
        ]);

        if ((int) ($fluxos['fluxos'] ?? 0) > 0) {
            return;
        }

        enviar_mensagem($chatId, message_template('msg_unknown'));
        return;
    }

    enviar_mensagem($chatId, message_template('msg_unknown'));
}

function processar_callback_telegram(array $callback): void
{
    telegram_request('answerCallbackQuery', [
        'callback_query_id' => $callback['id'],
    ]);

    $chatId = (int) ($callback['from']['id'] ?? 0);
    if ($chatId <= 0) {
        return;
    }

    $from = is_array($callback['from'] ?? null) ? $callback['from'] : [];
    $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $firstName = (string) ($from['first_name'] ?? 'Usuario');
    $username = (string) ($from['username'] ?? '');
    $data = (string) ($callback['data'] ?? '');

    $usuario = upsert_usuario($chatId, $firstName, $username, [
        'last_name' => (string) ($from['last_name'] ?? ''),
        'language_code' => (string) ($from['language_code'] ?? ''),
        'is_premium' => !empty($from['is_premium']),
        'chat_type' => (string) ($chat['type'] ?? 'private'),
        'chat_username' => (string) ($chat['username'] ?? ''),
        'from' => $from,
        'chat' => $chat,
        'message_id' => (int) ($message['message_id'] ?? 0),
        'callback_data' => $data,
        'callback_query' => $callback,
        'source' => 'callback_query',
    ]);

    if ($data === 'menu_catalogo') {
        enviar_lista_planos($usuario);
        return;
    }

    if ($data === 'menu_packs') {
        enviar_lista_packs($usuario);
        return;
    }

    if (strpos($data, 'orderbump:aceitar:') === 0) {
        $orderbumpId = (int) substr($data, 18);
        iniciar_compra_orderbump($usuario, $orderbumpId);
        return;
    }

    if (strpos($data, 'orderbump:recusar:') === 0) {
        $orderbumpId = (int) substr($data, 18);
        $orderbump = get_orderbump_por_id($orderbumpId);
        if (!$orderbump) {
            enviar_mensagem($chatId, 'A oferta extra nao foi encontrada no momento.');
            return;
        }

        $produtoPrincipalId = (int) ($orderbump['produto_principal_id'] ?? 0);
        if ($produtoPrincipalId <= 0) {
            enviar_mensagem($chatId, 'A oferta extra nao esta vinculada a um produto valido.');
            return;
        }

        iniciar_compra($usuario, $produtoPrincipalId, null, true);
        return;
    }

    if (strpos($data, 'upsell:') === 0) {
        $funilId = (int) substr($data, 7);
        iniciar_compra_funil($usuario, $funilId, 'upsell');
        return;
    }

    if (strpos($data, 'downsell:') === 0) {
        $downsellId = (int) substr($data, 9);
        iniciar_compra_downsell($usuario, $downsellId);
        return;
    }

    if (strpos($data, 'comprar:') === 0) {
        $produtoId = (int) substr($data, 8);
        iniciar_compra($usuario, $produtoId);
        return;
    }
}

function extrair_comando_telegram(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '' || $texto[0] !== '/') {
        return '';
    }

    return normalize_bot_command($texto);
}

function enviar_boas_vindas(array $usuario): void
{
    $chatId = (int) $usuario['telegram_id'];
    $nome = htmlspecialchars((string) ($usuario['first_name'] ?? 'Usuario'));

    if (($usuario['status'] ?? '') === 'ativo' && !empty($usuario['data_expiracao']) && strtotime((string) $usuario['data_expiracao']) > time()) {
        $expira = date('d/m/Y H:i', strtotime((string) $usuario['data_expiracao']));
        enviar_mensagem(
            $chatId,
            render_template(message_template('msg_start_active'), [
                'nome' => $nome,
                'expira' => $expira,
            ])
        );
        return;
    }

    $mensagemInicial = render_template(message_template('msg_start_intro'), [
        'nome' => $nome,
    ]);

    $mediaTipo = runtime_start_media_tipo();
    $mediaUrl = runtime_start_media_url();
    if ($mediaTipo !== 'none' && $mediaUrl !== '') {
        $response = enviar_conteudo_telegram($chatId, $mensagemInicial, $mediaTipo, $mediaUrl);
        if (!is_array($response) || empty($response['ok'])) {
            log_evento('start_media_falhou', 'Falha ao enviar a midia do /start. Usando texto simples como fallback.', [
                'chat_id' => $chatId,
                'media_tipo' => $mediaTipo,
                'media_url' => $mediaUrl,
            ]);
            enviar_mensagem($chatId, $mensagemInicial);
        }
    } else {
        enviar_mensagem($chatId, $mensagemInicial);
    }

    $audioUrl = runtime_start_audio_url();
    if ($audioUrl !== '') {
        $audioCaption = render_template(message_template('msg_start_audio_caption'), [
            'nome' => $nome,
        ]);
        $audioResponse = enviar_midia_telegram(
            $chatId,
            'audio',
            $audioUrl,
            ''
        );
        if (!is_array($audioResponse) || empty($audioResponse['ok'])) {
            log_evento('start_audio_falhou', 'Falha ao enviar o audio do /start.', [
                'chat_id' => $chatId,
                'audio_url' => $audioUrl,
            ]);

            if ($audioCaption !== '') {
                enviar_mensagem($chatId, $audioCaption);
            }
        } elseif ($audioCaption !== '') {
            enviar_mensagem($chatId, $audioCaption);
        }
    }

    $botoes = [[
        ['text' => runtime_start_plan_button_text(), 'callback_data' => 'menu_catalogo'],
    ]];

    if (get_packs_ativos()) {
        $botoes[] = [
            ['text' => runtime_start_pack_button_text(), 'callback_data' => 'menu_packs'],
        ];
    }

    $ctaResponse = enviar_mensagem(
        $chatId,
        render_template(message_template('msg_start_cta'), [
            'nome' => $nome,
        ]),
        [
            'reply_markup' => json_encode([
                'inline_keyboard' => $botoes,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
    );

    if (!is_array($ctaResponse) || empty($ctaResponse['ok'])) {
        log_evento('start_cta_falhou', 'Falha ao enviar a mensagem final com botoes do /start.', [
            'chat_id' => $chatId,
            'botoes' => $botoes,
        ]);
    }
}

function enviar_status_usuario(array $usuario): void
{
    $chatId = (int) $usuario['telegram_id'];
    $statusMap = [
        'pendente' => 'Pendente',
        'ativo' => 'Ativo',
        'expirado' => 'Expirado',
    ];

    $status = $statusMap[$usuario['status'] ?? 'pendente'] ?? 'Desconhecido';
    $expira = !empty($usuario['data_expiracao'])
        ? date('d/m/Y H:i', strtotime((string) $usuario['data_expiracao']))
        : 'Ainda nao liberado';
    $cpf = runtime_status_cpf_label($usuario);

    enviar_mensagem(
        $chatId,
        render_template(message_template('msg_status'), [
            'status' => $status,
            'expira' => $expira,
            'cpf' => $cpf,
        ])
    );
}

function enviar_lista_planos(array $usuario): void
{
    $chatId = (int) $usuario['telegram_id'];
    $produtos = get_produtos_grupo_ativos();
    $mostrarBotaoPacks = !empty(get_packs_ativos());

    if (!$produtos) {
        enviar_mensagem($chatId, 'Nenhum plano esta disponivel no momento.');
        return;
    }

    enviar_mensagem(
        $chatId,
        message_template('msg_choose_plan'),
        [
            'reply_markup' => montar_teclado_planos($produtos, $mostrarBotaoPacks),
        ]
    );
}

function enviar_lista_packs(array $usuario): void
{
    $chatId = (int) $usuario['telegram_id'];
    $packs = get_packs_ativos();

    if (!$packs) {
        enviar_mensagem($chatId, 'Nenhum pack esta disponivel no momento.');
        return;
    }

    enviar_mensagem(
        $chatId,
        message_template('msg_choose_pack'),
        [
            'reply_markup' => montar_teclado_packs($packs, true),
        ]
    );
}

function iniciar_compra(array $usuario, int $produtoId, ?int $orderbumpId = null, bool $ignorarOrderbump = false): void
{
    $chatId = (int) $usuario['telegram_id'];
    $produto = get_produto_por_id($produtoId);

    if (!$produto || !((int) ($produto['ativo'] ?? 1))) {
        enviar_mensagem($chatId, 'Esse plano nao esta disponivel no momento.');
        return;
    }

    if (!$ignorarOrderbump) {
        $orderbump = get_orderbump_ativo_por_produto($produtoId);
        if ($orderbump && !empty($orderbump['id']) && get_produto_oferta_orderbump($orderbump)) {
            enviar_oferta_orderbump($chatId, $orderbump, [
                'produto_principal' => htmlspecialchars((string) ($produto['nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8'),
            ]);
            return;
        }
    }

    if (!runtime_pestopay_checkout_ready($usuario)) {
        enviar_mensagem($chatId, 'O checkout da PestoPay precisa de um CPF e um telefone fixos validos. Acesse Configuracoes e preencha esses dois campos para liberar o Pix direto.');
        return;
    }

    if (!runtime_gateway_credentials_ready()) {
        enviar_mensagem($chatId, 'As credenciais da PestoPay ainda nao estao configuradas. Preencha a public key e a secret key no painel antes de gerar o Pix.');
        return;
    }

    $produtoCheckout = $produto;
    $extras = [];
    if ($orderbumpId > 0) {
        $orderbump = get_orderbump_por_id($orderbumpId);
        if ($orderbump && (int) ($orderbump['produto_principal_id'] ?? 0) === $produtoId) {
            $orderbumpProduto = get_produto_oferta_orderbump($orderbump);
            if ($orderbumpProduto) {
                $produtoCheckout['nome'] = trim((string) ($produto['nome'] ?? 'Produto') . ' + ' . (string) ($orderbumpProduto['nome'] ?? 'Oferta'));
                $produtoCheckout['valor'] = round((float) ($produto['valor'] ?? 0) + (float) ($orderbumpProduto['valor'] ?? 0), 2);
                $extras['orderbump_id'] = (int) $orderbump['id'];
            }
        }
    }

    enviar_mensagem(
        $chatId,
        render_template(message_template('msg_pix_generating'), [
            'produto' => htmlspecialchars((string) $produtoCheckout['nome']),
        ])
    );

    $pix = gerar_pix_para_usuario($usuario, $produtoCheckout, null, 'principal', $extras);
    if (!$pix) {
        enviar_mensagem($chatId, message_template('msg_pix_error'));
        return;
    }

    $mensagem = render_template(message_template('msg_pix_generated'), [
        'produto' => htmlspecialchars((string) $produtoCheckout['nome']),
        'valor' => formatar_valor((float) $pix['valor']),
        'txid' => $pix['txid'],
        'pix' => $pix['qr_code'],
    ]);

    enviar_mensagem($chatId, $mensagem);

    if (!empty($pix['qr_img'])) {
        enviar_qrcode_imagem($chatId, (string) $pix['qr_img']);
    }
}

function iniciar_compra_orderbump(array $usuario, int $orderbumpId): void
{
    $chatId = (int) $usuario['telegram_id'];
    $orderbump = get_orderbump_por_id($orderbumpId);

    if (!$orderbump || !((int) ($orderbump['ativo'] ?? 1))) {
        enviar_mensagem($chatId, 'A oferta extra nao esta disponivel no momento.');
        return;
    }

    $produtoPrincipalId = (int) ($orderbump['produto_principal_id'] ?? 0);
    $produtoPrincipal = get_produto_por_id($produtoPrincipalId);
    if (!$produtoPrincipal || !((int) ($produtoPrincipal['ativo'] ?? 1))) {
        log_evento('orderbump_checkout_sem_produto_principal', 'O order bump aceito esta sem produto principal valido.', [
            'orderbump_id' => $orderbumpId,
            'produto_principal_id' => $produtoPrincipalId,
        ]);
        enviar_mensagem($chatId, 'A oferta extra nao esta vinculada a um produto valido.');
        return;
    }

    $produtoOferta = get_produto_oferta_orderbump($orderbump);
    if (!$produtoOferta) {
        log_evento('orderbump_checkout_sem_produto_oferta', 'O produto ofertado no order bump nao esta mais disponivel.', [
            'orderbump_id' => $orderbumpId,
            'produto_oferta_id' => (int) ($orderbump['produto_id'] ?? 0),
        ]);
        enviar_mensagem($chatId, 'A oferta extra nao esta disponivel no momento.');
        return;
    }

    if (!runtime_pestopay_checkout_ready($usuario)) {
        enviar_mensagem($chatId, 'O checkout da PestoPay precisa de um CPF e um telefone fixos validos. Acesse Configuracoes e preencha esses dois campos para liberar o Pix direto.');
        return;
    }

    if (!runtime_gateway_credentials_ready()) {
        enviar_mensagem($chatId, 'As credenciais da PestoPay ainda nao estao configuradas. Preencha a public key e a secret key no painel antes de gerar o Pix.');
        return;
    }

    $produtoCheckout = $produtoPrincipal;
    $produtoCheckout['nome'] = trim((string) ($produtoPrincipal['nome'] ?? 'Produto') . ' + ' . (string) ($produtoOferta['nome'] ?? 'Oferta'));
    $produtoCheckout['valor'] = round((float) ($produtoPrincipal['valor'] ?? 0) + (float) ($produtoOferta['valor'] ?? 0), 2);
    $produtoCheckout['valor_original'] = round(
        (float) ($produtoPrincipal['valor_original'] ?? $produtoPrincipal['valor'] ?? 0)
        + (float) ($produtoOferta['valor_original'] ?? $produtoOferta['valor'] ?? 0),
        2
    );

    enviar_mensagem(
        $chatId,
        render_template(message_template('msg_pix_generating'), [
            'produto' => htmlspecialchars((string) $produtoCheckout['nome']),
        ])
    );

    $pix = gerar_pix_para_usuario($usuario, $produtoCheckout, null, 'principal', [
        'orderbump_id' => (int) $orderbump['id'],
    ]);
    if (!$pix) {
        log_evento('orderbump_pix_geracao_falhou', 'Nao foi possivel gerar o Pix do order bump aceito.', [
            'usuario_id' => (int) ($usuario['id'] ?? 0),
            'orderbump_id' => (int) $orderbump['id'],
            'produto_principal_id' => $produtoPrincipalId,
            'produto_oferta_id' => (int) ($produtoOferta['id'] ?? 0),
            'valor_total' => (float) ($produtoCheckout['valor'] ?? 0),
        ]);
        enviar_mensagem($chatId, message_template('msg_pix_error'));
        return;
    }

    $mensagem = render_template(message_template('msg_pix_generated'), [
        'produto' => htmlspecialchars((string) $produtoCheckout['nome']),
        'valor' => formatar_valor((float) $pix['valor']),
        'txid' => $pix['txid'],
        'pix' => $pix['qr_code'],
    ]);

    enviar_mensagem($chatId, $mensagem);

    if (!empty($pix['qr_img'])) {
        enviar_qrcode_imagem($chatId, (string) $pix['qr_img']);
    }
}

function iniciar_compra_funil(array $usuario, int $funilId, string $tipoOferta): void
{
    $chatId = (int) $usuario['telegram_id'];
    $funil = get_funil_por_id($funilId);

    if (!$funil || !((int) ($funil['ativo'] ?? 1))) {
        enviar_mensagem($chatId, 'Essa oferta nao esta disponivel no momento.');
        return;
    }

    $tipoOferta = in_array($tipoOferta, ['upsell', 'downsell'], true) ? $tipoOferta : 'upsell';
    $produto = get_produto_oferta_funil($funil, $tipoOferta);

    if (!$produto) {
        enviar_mensagem($chatId, 'Essa oferta nao esta disponivel no momento.');
        return;
    }

    if (!runtime_pestopay_checkout_ready($usuario)) {
        enviar_mensagem($chatId, 'O checkout da PestoPay precisa de um CPF e um telefone fixos validos. Acesse Configuracoes e preencha esses dois campos para liberar o Pix direto.');
        return;
    }

    if (!runtime_gateway_credentials_ready()) {
        enviar_mensagem($chatId, 'As credenciais da PestoPay ainda nao estao configuradas. Preencha a public key e a secret key no painel antes de gerar o Pix.');
        return;
    }

    enviar_mensagem(
        $chatId,
        render_template(message_template('msg_pix_generating'), [
            'produto' => htmlspecialchars((string) $produto['nome']),
        ])
    );

    $pix = gerar_pix_para_usuario($usuario, $produto, $funilId, $tipoOferta);
    if (!$pix) {
        enviar_mensagem($chatId, message_template('msg_pix_error'));
        return;
    }

    $mensagem = render_template(message_template('msg_pix_generated'), [
        'produto' => htmlspecialchars((string) $produto['nome']),
        'valor' => formatar_valor((float) $pix['valor']),
        'txid' => $pix['txid'],
        'pix' => $pix['qr_code'],
    ]);

    enviar_mensagem($chatId, $mensagem);

    if (!empty($pix['qr_img'])) {
        enviar_qrcode_imagem($chatId, (string) $pix['qr_img']);
    }
}

function iniciar_compra_downsell(array $usuario, int $downsellId): void
{
    $chatId = (int) $usuario['telegram_id'];
    $downsell = get_downsell_por_id($downsellId);

    if (!$downsell || !((int) ($downsell['ativo'] ?? 1))) {
        enviar_mensagem($chatId, 'Essa oferta nao esta disponivel no momento.');
        return;
    }

    $produto = get_produto_oferta_downsell($downsell);
    if (!$produto) {
        enviar_mensagem($chatId, 'Essa oferta nao esta disponivel no momento.');
        return;
    }

    if (!runtime_pestopay_checkout_ready($usuario)) {
        enviar_mensagem($chatId, 'O checkout da PestoPay precisa de um CPF e um telefone fixos validos. Acesse Configuracoes e preencha esses dois campos para liberar o Pix direto.');
        return;
    }

    if (!runtime_gateway_credentials_ready()) {
        enviar_mensagem($chatId, 'As credenciais da PestoPay ainda nao estao configuradas. Preencha a public key e a secret key no painel antes de gerar o Pix.');
        return;
    }

    enviar_mensagem(
        $chatId,
        render_template(message_template('msg_pix_generating'), [
            'produto' => htmlspecialchars((string) $produto['nome']),
        ])
    );

    $pix = gerar_pix_para_usuario($usuario, $produto, (int) ($downsell['funil_id'] ?? 0), 'downsell');
    if (!$pix) {
        enviar_mensagem($chatId, message_template('msg_pix_error'));
        return;
    }

    $mensagem = render_template(message_template('msg_pix_generated'), [
        'produto' => htmlspecialchars((string) $produto['nome']),
        'valor' => formatar_valor((float) $pix['valor']),
        'txid' => $pix['txid'],
        'pix' => $pix['qr_code'],
    ]);

    enviar_mensagem($chatId, $mensagem);

    if (!empty($pix['qr_img'])) {
        enviar_qrcode_imagem($chatId, (string) $pix['qr_img']);
    }
}
