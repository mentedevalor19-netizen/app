<?php
/**
 * gerar_pix.php
 * Cria uma cobrança Pix via API da Ecompag e retorna QR Code ao usuário
 *
 * Chamado internamente pelo index.php quando o usuário clica em "Comprar"
 * Pode ser chamado via: include 'gerar_pix.php'; OU diretamente via cURL interno
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Obtém token de acesso OAuth da Ecompag
 * A Ecompag usa autenticação OAuth2 client_credentials
 *
 * @return string|null Token Bearer ou null em caso de erro
 */
function ecompag_get_token(): ?string {
    $url  = ECOMPAG_API_URL . '/oauth/token';
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => ECOMPAG_CLIENT_ID,
        'client_secret' => ECOMPAG_SECRET,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        log_evento('ecompag_token_erro', "HTTP $httpCode: $response");
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

/**
 * Cria cobrança Pix imediata na Ecompag
 *
 * Documentação Ecompag:
 *   POST /v1/cob/{txid}
 *   Body JSON conforme BACEN/PIX
 *
 * @param string $txid      ID único da transação (max 35 chars)
 * @param float  $valor     Valor em reais
 * @param string $descricao Descrição da cobrança
 * @param string $token     Bearer token OAuth
 * @return array|null       Dados da cobrança ou null
 */
function ecompag_criar_cobranca(string $txid, float $valor, string $descricao, string $token): ?array {
    $url = ECOMPAG_API_URL . '/cob/' . $txid;

    $payload = [
        'calendario' => [
            'expiracao' => 3600,           // QR Code expira em 1 hora
        ],
        'valor' => [
            'original' => number_format($valor, 2, '.', ''), // "29.90"
        ],
        'chave'         => ECOMPAG_CHAVE_PIX,
        'solicitacaoPagador' => $descricao,
        'infoAdicionais' => [
            [
                'nome'  => 'Produto',
                'valor' => NOME_PRODUTO,
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',       // Ecompag usa PUT para criar cobrança com txid
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 201 && $httpCode !== 200) {
        log_evento('ecompag_cob_erro', "HTTP $httpCode: $response");
        return null;
    }

    return $data;
}

/**
 * Busca QR Code (payload e imagem base64) de uma cobrança existente
 *
 * GET /v1/cob/{txid}/qrcode
 *
 * @param string $txid  ID da cobrança
 * @param string $token Bearer token OAuth
 * @return array|null   ['payload' => '...', 'imagemQrcode' => 'base64...']
 */
function ecompag_get_qrcode(string $txid, string $token): ?array {
    $url = ECOMPAG_API_URL . '/cob/' . $txid . '/qrcode';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        log_evento('ecompag_qrcode_erro', "HTTP $httpCode: $response");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Função principal: gera cobrança Pix e salva no banco
 *
 * @param array $usuario Dados do usuário (id, telegram_id, first_name)
 * @return array|null    ['qr_code' => 'copia_cola', 'qr_img' => 'base64', 'txid' => '...']
 */
function gerar_pix_para_usuario(array $usuario): ?array {
    // 1. Obtém token OAuth
    $token = ecompag_get_token();
    if (!$token) {
        log_evento('gerar_pix_erro', 'Não foi possível obter token Ecompag', $usuario);
        return null;
    }

    // 2. Gera txid único
    $txid = gerar_txid();

    // 3. Cria cobrança na Ecompag
    $cobranca = ecompag_criar_cobranca(
        $txid,
        VALOR_ACESSO,
        NOME_PRODUTO . ' - Telegram ID: ' . $usuario['telegram_id'],
        $token
    );

    if (!$cobranca) {
        return null;
    }

    // 4. Busca QR Code
    $qrcode = ecompag_get_qrcode($txid, $token);

    // Fallback: alguns provedores já retornam o QR na criação
    $payload  = $qrcode['payload']       ?? $cobranca['pixCopiaECola'] ?? '';
    $qr_img   = $qrcode['imagemQrcode']  ?? $cobranca['imagemQrcode']  ?? '';

    // 5. Salva pagamento no banco
    criar_pagamento(
        $usuario['id'],
        $txid,
        VALOR_ACESSO,
        $payload,
        $qr_img
    );

    log_evento('pix_gerado', "txid=$txid usuario_id={$usuario['id']}");

    return [
        'txid'   => $txid,
        'qr_code'=> $payload,
        'qr_img' => $qr_img,
        'valor'  => VALOR_ACESSO,
    ];
}
