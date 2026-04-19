<?php
/**
 * admin/auth.php
 * Sistema de autenticação do painel admin
 * Sessões armazenadas no banco de dados
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

define('SESSION_DURATION', 60 * 60 * 8); // 8 horas
define('ADMIN_COOKIE',     'adm_tkn');
define('ADMIN_BASE_URI', '/' . basename(__DIR__));

function admin_url(string $path = ''): string {
    return rtrim(ADMIN_BASE_URI, '/') . '/' . ltrim($path, '/');
}

// ─── Funções de autenticação ─────────────────────────────────────────────────

/**
 * Tenta autenticar admin por email/senha
 * Retorna o admin ou null
 */
function auth_login(string $email, string $senha): ?array {
    $stmt = db()->prepare('SELECT * FROM admins WHERE email = ? AND ativo = 1');
    $stmt->execute([strtolower(trim($email))]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($senha, $admin['senha_hash'])) {
        log_evento('admin_login_falhou', "Tentativa falha: $email IP=" . get_ip());
        return null;
    }

    // Cria token de sessão
    $token    = bin2hex(random_bytes(32));
    $expira   = date('Y-m-d H:i:s', time() + SESSION_DURATION);
    $ip       = get_ip();
    $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $agora    = db_now();

    // Limpa sessões antigas deste admin
    db()->prepare('DELETE FROM sessoes_admin WHERE admin_id = ? AND expira_em < ?')
        ->execute([$admin['id'], $agora]);

    // Insere nova sessão
    db()->prepare(
        'INSERT INTO sessoes_admin (admin_id, token, ip, user_agent, expira_em) VALUES (?, ?, ?, ?, ?)'
    )->execute([$admin['id'], $token, $ip, $ua, $expira]);

    // Atualiza último login
    db()->prepare('UPDATE admins SET ultimo_login = ? WHERE id = ?')
        ->execute([$agora, $admin['id']]);

    // Seta cookie seguro
    setcookie(ADMIN_COOKIE, $token, [
        'expires'  => time() + SESSION_DURATION,
        'path'     => ADMIN_BASE_URI,
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    log_evento('admin_login', "Admin #{$admin['id']} {$admin['email']} logou");
    return $admin;
}

/**
 * Retorna o admin autenticado ou null
 */
function auth_admin(): ?array {
    $token = $_COOKIE[ADMIN_COOKIE] ?? '';
    if (empty($token) || strlen($token) !== 64) return null;

    $stmt = db()->prepare(
        'SELECT a.*, s.token, s.expira_em
         FROM sessoes_admin s
         JOIN admins a ON a.id = s.admin_id
         WHERE s.token = ? AND s.expira_em > ? AND a.ativo = 1'
    );
    $stmt->execute([$token, db_now()]);
    $admin = $stmt->fetch();

    if (!$admin) return null;

    // Renova expiração a cada request (sliding session)
    $nova_expiracao = date('Y-m-d H:i:s', time() + SESSION_DURATION);
    db()->prepare('UPDATE sessoes_admin SET expira_em = ? WHERE token = ?')
        ->execute([$nova_expiracao, $token]);

    return $admin;
}

/**
 * Encerra sessão atual
 */
function auth_logout(): void {
    $token = $_COOKIE[ADMIN_COOKIE] ?? '';
    if ($token) {
        db()->prepare('DELETE FROM sessoes_admin WHERE token = ?')->execute([$token]);
    }
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => ADMIN_BASE_URI]);
}

/**
 * Middleware: exige autenticação. Redireciona se não logado.
 */
function require_auth(): array {
    $admin = auth_admin();
    if (!$admin) {
        header('Location: ' . admin_url('login.php'));
        exit;
    }
    return $admin;
}

/**
 * Middleware: exige nível super ou admin (não viewer)
 */
function require_admin(array $admin): void {
    if ($admin['nivel'] === 'viewer') {
        header('Location: ' . admin_url('index.php?erro=sem_permissao'));
        exit;
    }
}

function get_ip(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}
