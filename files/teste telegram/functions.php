<?php
/**
 * functions.php
 * Funções auxiliares utilizadas em todo o sistema
 */

require_once __DIR__ . '/config.php';

// ─── BANCO DE DADOS ──────────────────────────────────────────────────────────

/**
 * Retorna conexão PDO (singleton simples)
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            log_evento('db_error', 'Falha ao conectar ao banco: ' . $e->getMessage());
            http_response_code(500);
            exit('Erro interno.');
        }
    }
    return $pdo;
}

// ─── USUÁRIOS ────────────────────────────────────────────────────────────────

/**
 * Busca usuário pelo telegram_id; cria se não existir
 */
function upsert_usuario(int $telegram_id, string $first_name = '', string $username = ''): array {
    $pdo = db();

    // Tenta buscar
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE telegram_id = ?');
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();

    if ($user) {
        // Atualiza nome caso tenha mudado
        $pdo->prepare('UPDATE usuarios SET first_name = ?, username = ? WHERE telegram_id = ?')
            ->execute([$first_name, $username ?: null, $telegram_id]);
        return array_merge($user, ['first_name' => $first_name, 'username' => $username]);
    }

    // Cria novo
    $pdo->prepare('INSERT INTO usuarios (telegram_id, first_name, username) VALUES (?, ?, ?)')
        ->execute([$telegram_id, $first_name, $username ?: null]);

    return [
        'id'          => (int) $pdo->lastInsertId(),
        'telegram_id' => $telegram_id,
        'first_name'  => $first_name,
        'username'    => $username,
        'status'      => 'pendente',
        'data_expiracao' => null,
    ];
}

/**
 * Ativa acesso do usuário por X dias
 */
function ativar_usuario(int $usuario_id): void {
    $expiracao = date('Y-m-d H:i:s', strtotime('+' . DIAS_ACESSO . ' days'));
    db()->prepare(
        'UPDATE usuarios SET status = "ativo", data_expiracao = ? WHERE id = ?'
    )->execute([$expiracao, $usuario_id]);
}

/**
 * Marca usuário como expirado
 */
function expirar_usuario(int $usuario_id): void {
    db()->prepare(
        'UPDATE usuarios SET status = "expirado", grupo_adicionado = 0 WHERE id = ?'
    )->execute([$usuario_id]);
}

// ─── TELEGRAM ────────────────────────────────────────────────────────────────

/**
 * Envia requisição à API do Telegram
 *
 * @param string $method  Método da API (ex: sendMessage)
 * @param array  $params  Parâmetros do método
 * @return array|null     Resposta decodificada ou null em caso de erro
 */
function telegram_request(string $method, array $params): ?array {
    $url  = TELEGRAM_API . '/' . $method;
    $json = json_encode($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_evento('telegram_curl_error', $err);
        return null;
    }

    return json_decode($response, true);
}

/**
 * Envia mensagem de texto ao usuário
 */
function enviar_mensagem(int $chat_id, string $texto, array $extra = []): ?array {
    return telegram_request('sendMessage', array_merge([
        'chat_id'    => $chat_id,
        'text'       => $texto,
        'parse_mode' => 'HTML',
    ], $extra));
}

/**
 * Gera e retorna link de convite único para o grupo
 */
function gerar_link_convite(): ?string {
    $res = telegram_request('createChatInviteLink', [
        'chat_id'      => TELEGRAM_GROUP_ID,
        'member_limit' => 1,           // Apenas 1 uso
        'expire_date'  => time() + (60 * 60), // Válido por 1 hora
    ]);

    if (isset($res['result']['invite_link'])) {
        return $res['result']['invite_link'];
    }

    log_evento('convite_erro', json_encode($res));
    return null;
}

/**
 * Remove (bane e desbane) usuário do grupo Telegram
 */
function remover_do_grupo(int $telegram_id): bool {
    // Bane o usuário
    $ban = telegram_request('banChatMember', [
        'chat_id' => TELEGRAM_GROUP_ID,
        'user_id' => $telegram_id,
    ]);

    if (!isset($ban['result']) || $ban['result'] !== true) {
        log_evento('remover_grupo_erro', json_encode($ban));
        return false;
    }

    // Desbane imediatamente (para poder entrar de novo se renovar)
    telegram_request('unbanChatMember', [
        'chat_id'               => TELEGRAM_GROUP_ID,
        'user_id'               => $telegram_id,
        'only_if_banned'        => true,
    ]);

    return true;
}

// ─── PAGAMENTOS ──────────────────────────────────────────────────────────────

/**
 * Cria registro de pagamento pendente no banco
 */
function criar_pagamento(int $usuario_id, string $txid, float $valor, string $qr, string $qr_img): int {
    db()->prepare(
        'INSERT INTO pagamentos (usuario_id, txid, valor, status, qr_code, qr_code_img)
         VALUES (?, ?, ?, "pendente", ?, ?)'
    )->execute([$usuario_id, $txid, $valor, $qr, $qr_img]);

    return (int) db()->lastInsertId();
}

/**
 * Marca pagamento como pago
 */
function confirmar_pagamento(string $txid): ?array {
    $stmt = db()->prepare('SELECT * FROM pagamentos WHERE txid = ? AND status = "pendente"');
    $stmt->execute([$txid]);
    $pag = $stmt->fetch();

    if (!$pag) return null;

    db()->prepare(
        'UPDATE pagamentos SET status = "pago", paid_at = NOW() WHERE txid = ?'
    )->execute([$txid]);

    return $pag;
}

// ─── LOG ─────────────────────────────────────────────────────────────────────

/**
 * Registra evento no banco e no arquivo de log
 */
function log_evento(string $tipo, string $mensagem, array $dados = []): void {
    // Arquivo
    $linha = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($tipo), $mensagem);
    file_put_contents(LOG_FILE, $linha, FILE_APPEND | LOCK_EX);

    // Banco (tenta, mas não para a execução se falhar)
    try {
        db()->prepare(
            'INSERT INTO logs (tipo, mensagem, dados) VALUES (?, ?, ?)'
        )->execute([$tipo, $mensagem, $dados ? json_encode($dados) : null]);
    } catch (Throwable $e) {
        // Silencia erro de log para não criar loop
    }
}

// ─── UTILITÁRIOS ─────────────────────────────────────────────────────────────

/**
 * Gera txid único para a cobrança Pix (max 35 chars alfanumérico)
 */
function gerar_txid(): string {
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))), 0, 35);
}

/**
 * Formata valor em reais: 29.90 → "R$ 29,90"
 */
function formatar_valor(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * Retorna JSON e encerra execução
 */
function responder_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
