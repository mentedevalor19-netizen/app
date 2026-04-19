<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$token = $_GET['token'] ?? '';
if (!request_has_valid_webhook_token()) {
    log_evento('webhook_pix_secret_invalido', 'Tentativa de webhook com token invalido.');
    http_response_code(200);
    exit('OK');
}

$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(200);
    exit('OK');
}

$payload = json_decode($input, true);
if (!is_array($payload)) {
    log_evento('webhook_pix_json_invalido', 'Payload invalido recebido.', ['raw' => $input]);
    http_response_code(200);
    exit('OK');
}

log_evento('webhook_pix_recebido', 'Webhook Ecompag recebido', $payload);

if (($payload['transactionType'] ?? '') !== 'RECEIVEPIX' || ($payload['status'] ?? '') !== 'PAID') {
    http_response_code(200);
    exit('OK');
}

$txid = (string) ($payload['transactionId'] ?? '');
if ($txid === '') {
    log_evento('webhook_pix_sem_txid', 'Webhook sem transactionId.');
    http_response_code(200);
    exit('OK');
}

$pagamento = confirmar_pagamento($txid);
if (!$pagamento) {
    log_evento('webhook_pix_txid_nao_encontrado', 'Pagamento nao encontrado para o transactionId recebido.', ['txid' => $txid]);
    http_response_code(200);
    exit('OK');
}

if (!empty($pagamento['already_processed'])) {
    log_evento('webhook_pix_duplicado', 'Webhook duplicado ignorado.', ['txid' => $txid]);
    http_response_code(200);
    exit('OK');
}

$selectFunil = db_has_column('pagamentos', 'funil_id') ? ', p.funil_id' : ', NULL AS funil_id';
$selectTipoOferta = db_has_column('pagamentos', 'tipo_oferta') ? ', p.tipo_oferta' : ", 'principal' AS tipo_oferta";
$selectOrderbump = db_has_column('pagamentos', 'orderbump_id') ? ', p.orderbump_id' : ', NULL AS orderbump_id';
$selectProdutoTipo = db_has_column('produtos', 'tipo') ? ', pr.tipo' : ", 'grupo' AS tipo";
$selectPackLink = db_has_column('produtos', 'pack_link') ? ', pr.pack_link' : ', NULL AS pack_link';

$stmt = db()->prepare(
    'SELECT u.*, p.produto_id' . $selectFunil . $selectTipoOferta . $selectOrderbump . ', pr.nome AS produto_nome, pr.dias_acesso' . $selectProdutoTipo . $selectPackLink . '
     FROM pagamentos p
     JOIN usuarios u ON u.id = p.usuario_id
     LEFT JOIN produtos pr ON pr.id = p.produto_id
     WHERE p.txid = ?
     LIMIT 1'
);
$stmt->execute([$txid]);
$usuario = $stmt->fetch();

if (!$usuario) {
    log_evento('webhook_pix_usuario_nao_encontrado', 'Usuario nao localizado ao processar pagamento.', ['txid' => $txid]);
    http_response_code(200);
    exit('OK');
}

$nome = htmlspecialchars((string) ($usuario['first_name'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8');
$produtoNome = htmlspecialchars((string) ($usuario['produto_nome'] ?? runtime_default_product_name()), ENT_QUOTES, 'UTF-8');
$produtoId = (int) ($usuario['produto_id'] ?? 0);
$tipoOferta = (string) ($usuario['tipo_oferta'] ?? 'principal');
$isPack = produto_is_pack($usuario);
$conviteFluxo = '';
$expiraFluxo = '';
$packLinkFluxo = '';

if ($isPack) {
    $packLink = produto_pack_link($usuario);
    $packLinkFluxo = $packLink;

    if ($packLink !== '') {
        enviar_conteudo_telegram(
            (int) $usuario['telegram_id'],
            render_template(message_template('msg_pack_delivered'), [
                'nome' => $nome,
                'produto' => $produtoNome,
                'pack_link' => htmlspecialchars($packLink, ENT_QUOTES, 'UTF-8'),
            ])
        );
    } else {
        enviar_conteudo_telegram(
            (int) $usuario['telegram_id'],
            render_template(message_template('msg_pack_missing_link'), [
                'nome' => $nome,
                'produto' => $produtoNome,
            ])
        );

        log_evento('webhook_pix_pack_sem_link', 'Produto do tipo pack sem link configurado.', [
            'txid' => $txid,
            'produto_id' => $produtoId,
        ]);
    }
} else {
    $dias = (int) ($usuario['dias_acesso'] ?? runtime_default_product_days());
    if ($dias <= 0) {
        $dias = runtime_default_product_days();
    }

    ativar_usuario((int) $usuario['id'], $dias);

    $stmtExp = db()->prepare('SELECT data_expiracao FROM usuarios WHERE id = ?');
    $stmtExp->execute([(int) $usuario['id']]);
    $dataExpiracao = $stmtExp->fetchColumn();

    $convite = gerar_link_convite();
    $expira = $dataExpiracao
        ? date('d/m/Y H:i', strtotime((string) $dataExpiracao))
        : date('d/m/Y H:i', strtotime('+' . $dias . ' days'));
    $conviteFluxo = (string) ($convite ?? '');
    $expiraFluxo = $expira;

    if ($convite) {
        enviar_conteudo_telegram(
            (int) $usuario['telegram_id'],
            render_template(message_template('msg_payment_confirmed'), [
                'nome' => $nome,
                'produto' => $produtoNome,
                'expira' => $expira,
                'convite' => $convite,
            ])
        );
    } else {
        enviar_conteudo_telegram(
            (int) $usuario['telegram_id'],
            render_template(message_template('msg_payment_confirmed_no_invite'), [
                'nome' => $nome,
                'expira' => $expira,
                'produto' => $produtoNome,
            ])
        );

        log_evento('webhook_pix_convite_falhou', 'Nao foi possivel gerar o convite do grupo.', ['txid' => $txid]);
    }
}

$produtoFluxo = $produtoId > 0 ? (get_produto_por_id($produtoId) ?: []) : [];
$produtoFluxo['id'] = $produtoId;
$produtoFluxo['nome'] = (string) ($usuario['produto_nome'] ?? $produtoFluxo['nome'] ?? runtime_default_product_name());
$produtoFluxo['valor_original'] = (float) ($produtoFluxo['valor'] ?? $pagamento['valor'] ?? 0);
$produtoFluxo['valor'] = (float) ($pagamento['valor'] ?? $produtoFluxo['valor_original']);
$produtoFluxo['pack_link'] = $packLinkFluxo !== '' ? $packLinkFluxo : (string) ($produtoFluxo['pack_link'] ?? '');

if (($produtoFluxo['valor_original'] ?? 0) > 0 && ($produtoFluxo['valor'] ?? 0) < ($produtoFluxo['valor_original'] ?? 0)) {
    $produtoFluxo['desconto_percentual'] = round(100 - ((float) $produtoFluxo['valor'] / (float) $produtoFluxo['valor_original'] * 100), 2);
}

$orderbumpId = (int) ($usuario['orderbump_id'] ?? 0);

disparar_fluxos('pagamento_aprovado', [
    'usuario' => $usuario,
    'produto' => $produtoFluxo,
    'pagamento' => array_merge($pagamento, [
        'produto_nome' => (string) $produtoFluxo['nome'],
        'orderbump_id' => $orderbumpId > 0 ? $orderbumpId : null,
    ]),
    'funil_id' => !empty($usuario['funil_id']) ? (int) $usuario['funil_id'] : null,
    'convite' => $conviteFluxo,
    'expira' => $expiraFluxo,
    'pack_link' => $packLinkFluxo,
    'referencia_tipo' => 'pagamento',
    'referencia_id' => (int) ($pagamento['id'] ?? 0),
]);

disparar_n8n_evento('pagamento_aprovado', [
    'usuario' => $usuario,
    'produto' => $produtoFluxo,
    'pagamento' => array_merge($pagamento, [
        'produto_nome' => (string) $produtoFluxo['nome'],
        'orderbump_id' => $orderbumpId > 0 ? $orderbumpId : null,
    ]),
    'funil_id' => !empty($usuario['funil_id']) ? (int) $usuario['funil_id'] : null,
    'convite' => $conviteFluxo,
    'expira' => $expiraFluxo,
    'pack_link' => $packLinkFluxo,
]);
disparar_remarketing_webhooks('pagamento_aprovado', [
    'usuario' => $usuario,
    'produto' => $produtoFluxo,
    'pagamento' => array_merge($pagamento, [
        'produto_nome' => (string) $produtoFluxo['nome'],
        'orderbump_id' => $orderbumpId > 0 ? $orderbumpId : null,
    ]),
    'funil_id' => !empty($usuario['funil_id']) ? (int) $usuario['funil_id'] : null,
    'convite' => $conviteFluxo,
    'expira' => $expiraFluxo,
    'pack_link' => $packLinkFluxo,
]);

if ($isPack && $packLinkFluxo !== '') {
    disparar_fluxos('pack_entregue', [
        'usuario' => $usuario,
        'produto' => $produtoFluxo,
        'pagamento' => array_merge($pagamento, ['produto_nome' => (string) $produtoFluxo['nome']]),
        'pack_link' => $packLinkFluxo,
        'referencia_tipo' => 'pagamento',
        'referencia_id' => (int) ($pagamento['id'] ?? 0),
    ]);

    disparar_n8n_evento('pack_entregue', [
        'usuario' => $usuario,
        'produto' => $produtoFluxo,
        'pagamento' => array_merge($pagamento, ['produto_nome' => (string) $produtoFluxo['nome']]),
        'pack_link' => $packLinkFluxo,
    ]);
    disparar_remarketing_webhooks('pack_entregue', [
        'usuario' => $usuario,
        'produto' => $produtoFluxo,
        'pagamento' => array_merge($pagamento, ['produto_nome' => (string) $produtoFluxo['nome']]),
        'pack_link' => $packLinkFluxo,
    ]);
}

meta_send_event('Purchase', $usuario, [
    'currency' => 'BRL',
    'value' => (float) ($pagamento['valor'] ?? 0),
    'content_name' => (string) ($usuario['produto_nome'] ?? runtime_default_product_name()),
    'content_type' => 'product',
    'content_ids' => [$produtoId > 0 ? (string) $produtoId : 'default'],
    'contents' => [[
        'id' => $produtoId > 0 ? (string) $produtoId : 'default',
        'quantity' => 1,
        'item_price' => (float) ($pagamento['valor'] ?? 0),
    ]],
    'order_id' => $txid,
], [
    'event_id' => 'purchase_' . $txid,
]);

if ($orderbumpId > 0) {
    enviar_entrega_orderbump_pos_pagamento($usuario, array_merge($pagamento, [
        'orderbump_id' => $orderbumpId,
    ]));
}

if (!empty($usuario['funil_id']) && $tipoOferta === 'principal') {
    $funil = get_funil_por_id((int) $usuario['funil_id']);
    if ($funil && !empty($funil['upsell_produto_id'])) {
        enviar_oferta_funil((int) $usuario['telegram_id'], $funil, 'upsell', [
            'nome' => $nome,
        ]);
    }
}

http_response_code(200);
echo 'OK';
