<?php
require_once __DIR__ . '/config.php';

function tenants_enabled(): bool
{
    return db_has_table('tenants') && db_has_column('admins', 'tenant_id');
}

function tenant_table_supports_scope(string $table): bool
{
    return tenants_enabled() && db_has_column($table, 'tenant_id');
}

function clear_app_settings_cache(): void
{
    foreach (array_keys($GLOBALS) as $key) {
        if (strpos((string) $key, 'app_settings_cache:') === 0) {
            unset($GLOBALS[$key]);
        }
    }
}

function set_current_tenant_context(?array $tenant): void
{
    $GLOBALS['app_current_tenant'] = $tenant ?: null;
    clear_app_settings_cache();
}

function current_tenant(): ?array
{
    $tenant = $GLOBALS['app_current_tenant'] ?? null;
    return is_array($tenant) ? $tenant : null;
}

function current_tenant_id(): int
{
    return (int) (current_tenant()['id'] ?? 0);
}

function current_tenant_slug(): string
{
    return trim((string) (current_tenant()['slug'] ?? ''));
}

function current_tenant_name(): string
{
    $tenant = current_tenant();
    if (!$tenant) {
        return '';
    }

    return trim((string) ($tenant['nome'] ?? $tenant['name'] ?? ''));
}

function get_tenant_by_id(int $tenantId): ?array
{
    if ($tenantId <= 0 || !db_has_table('tenants')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    return $tenant ?: null;
}

function get_tenant_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '' || !db_has_table('tenants')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();

    return $tenant ?: null;
}

function tenant_slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'workspace';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'workspace';
}

function tenant_unique_slug(string $nome): string
{
    $base = tenant_slugify($nome);
    $slug = $base;
    $suffix = 2;

    while (get_tenant_by_slug($slug)) {
        $slug = $base . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}

function bootstrap_tenant_from_request(): ?array
{
    if (!tenants_enabled()) {
        return null;
    }

    $slug = trim((string) ($_GET['tenant'] ?? $_POST['tenant'] ?? ''));
    if ($slug === '') {
        return current_tenant();
    }

    $tenant = get_tenant_by_slug($slug);
    if ($tenant) {
        set_current_tenant_context($tenant);
    }

    return $tenant ?: null;
}

function bootstrap_tenant_from_admin(array $admin): ?array
{
    if (!tenants_enabled()) {
        return null;
    }

    $tenantId = (int) ($admin['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        set_current_tenant_context(null);
        return null;
    }

    $tenant = get_tenant_by_id($tenantId);
    set_current_tenant_context($tenant);
    return $tenant;
}

function tenant_scope_condition(string $table, string $alias = ''): string
{
    if (!tenant_table_supports_scope($table)) {
        return '1=1';
    }

    $tenantId = current_tenant_id();
    if ($tenantId <= 0) {
        return '1=0';
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . 'tenant_id = ' . (int) $tenantId;
}

function tenant_insert_append(string $table, array &$columns, array &$placeholders, array &$params): void
{
    if (!tenant_table_supports_scope($table) || in_array('tenant_id', $columns, true)) {
        return;
    }

    $tenantId = current_tenant_id();
    if ($tenantId <= 0) {
        throw new RuntimeException('Tenant nao definido para salvar em ' . $table . '.');
    }

    $columns[] = 'tenant_id';
    $placeholders[] = '?';
    $params[] = $tenantId;
}

function url_with_query_params(string $path, array $params = []): string
{
    $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '');
    if (!$params) {
        return $path;
    }

    return $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query($params);
}

function tenant_public_query_params(bool $includeToken = false): array
{
    $params = [];

    if (current_tenant_slug() !== '') {
        $params['tenant'] = current_tenant_slug();
    }

    if ($includeToken && runtime_has_webhook_secret()) {
        $params['token'] = runtime_webhook_secret();
    }

    return $params;
}

function register_workspace_admin(string $workspaceName, string $ownerName, string $email, string $password): array
{
    if (!db_has_table('tenants') || !db_has_column('admins', 'tenant_id')) {
        throw new RuntimeException('A base SaaS ainda nao foi aplicada no banco.');
    }

    $workspaceName = trim($workspaceName);
    $ownerName = trim($ownerName);
    $email = strtolower(trim($email));

    if ($workspaceName === '' || $ownerName === '') {
        throw new InvalidArgumentException('Informe o nome do workspace e o nome do responsavel.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe um e-mail valido.');
    }

    if (strlen($password) < 8) {
        throw new InvalidArgumentException('A senha precisa ter pelo menos 8 caracteres.');
    }

    $pdo = db();
    $previousTenant = current_tenant();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('Ja existe um administrador com esse e-mail.');
        }

        $slug = tenant_unique_slug($workspaceName);
        $now = db_now();

        $pdo->prepare(
            'INSERT INTO tenants (nome, slug, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$workspaceName, $slug, 'ativo', $now, $now]);

        $tenant = get_tenant_by_id((int) $pdo->lastInsertId());
        if (!$tenant) {
            throw new RuntimeException('Nao foi possivel criar o workspace.');
        }

        set_current_tenant_context($tenant);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'INSERT INTO admins (tenant_id, nome, email, senha_hash, nivel, ativo, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $tenant['id'],
            $ownerName,
            $email,
            $hash,
            'super',
            1,
            $now,
        ]);

        if (db_has_table('configuracoes')) {
            app_setting_save('site_url', SITE_URL);
            app_setting_save('base_url', BASE_URL);
            app_setting_save('webhook_secret', bin2hex(random_bytes(16)));
        }

        if (db_has_table('produtos')) {
            $stmtProduto = $pdo->prepare('SELECT COUNT(*) FROM produtos WHERE ' . tenant_scope_condition('produtos'));
            $stmtProduto->execute();
            if ((int) $stmtProduto->fetchColumn() === 0) {
                $columns = ['nome', 'descricao', 'valor', 'dias_acesso', 'tipo', 'pack_link', 'ativo', 'ordem'];
                $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];
                $params = [
                    DEFAULT_PRODUCT_NAME,
                    DEFAULT_PRODUCT_DESCRIPTION,
                    DEFAULT_PRODUCT_PRICE,
                    DEFAULT_PRODUCT_DAYS,
                    'grupo',
                    null,
                    1,
                    1,
                ];
                tenant_insert_append('produtos', $columns, $placeholders, $params);

                $pdo->prepare(
                    'INSERT INTO produtos (' . implode(', ', $columns) . ')
                     VALUES (' . implode(', ', $placeholders) . ')'
                )->execute($params);
            }
        }

        $pdo->commit();

        return [
            'tenant' => $tenant,
            'admin_email' => $email,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        set_current_tenant_context($previousTenant);
    }
}

function app_settings(): array
{
    $cacheKey = 'app_settings_cache:' . (tenant_table_supports_scope('configuracoes') ? current_tenant_id() : 'legacy');
    if (array_key_exists($cacheKey, $GLOBALS) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $settings = [];

    try {
        if (tenant_table_supports_scope('configuracoes')) {
            $tenantId = current_tenant_id();
            if ($tenantId > 0) {
                $stmt = db()->prepare('SELECT chave, valor FROM configuracoes WHERE tenant_id = ?');
                $stmt->execute([$tenantId]);
                $rows = $stmt->fetchAll();
            } else {
                $rows = [];
            }
        } else {
            $rows = db()->query('SELECT chave, valor FROM configuracoes')->fetchAll();
        }

        foreach ($rows as $row) {
            $settings[$row['chave']] = $row['valor'];
        }
    } catch (Throwable $e) {
        // Primeira instalação ou tabela ainda não criada.
    }

    $GLOBALS[$cacheKey] = $settings;
    return $settings;
}

function app_setting(string $key, $default = null)
{
    $settings = app_settings();
    if (array_key_exists($key, $settings) && $settings[$key] !== '' && $settings[$key] !== null) {
        return $settings[$key];
    }

    return $default;
}

function app_setting_save(string $key, ?string $value): void
{
    $updatedAt = db_now();
    if (tenant_table_supports_scope('configuracoes')) {
        $tenantId = current_tenant_id();
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant nao definido para salvar configuracoes.');
        }

        $stmt = db()->prepare('UPDATE configuracoes SET valor = ?, updated_at = ? WHERE tenant_id = ? AND chave = ?');
        $stmt->execute([$value, $updatedAt, $tenantId, $key]);

        if ($stmt->rowCount() === 0) {
            db()->prepare(
                'INSERT INTO configuracoes (tenant_id, chave, valor, updated_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$tenantId, $key, $value, $updatedAt]);
        }
    } else {
        $stmt = db()->prepare('UPDATE configuracoes SET valor = ?, updated_at = ? WHERE chave = ?');
        $stmt->execute([$value, $updatedAt, $key]);

        if ($stmt->rowCount() === 0) {
            db()->prepare(
                'INSERT INTO configuracoes (chave, valor, updated_at)
                 VALUES (?, ?, ?)'
            )->execute([$key, $value, $updatedAt]);
        }
    }

    clear_app_settings_cache();
}

function db_driver(): string
{
    return DB_DRIVER === 'pgsql' ? 'pgsql' : 'mysql';
}

function db_is_pgsql(): bool
{
    return db_driver() === 'pgsql';
}

function db_now(): string
{
    return date('Y-m-d H:i:s');
}

function db_today(): string
{
    return date('Y-m-d');
}

function db_quote_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Identificador SQL invalido: ' . $identifier);
    }

    return db_is_pgsql() ? '"' . $identifier . '"' : '`' . $identifier . '`';
}

function db_like_operator(): string
{
    return db_is_pgsql() ? 'ILIKE' : 'LIKE';
}

function runtime_site_url(): string
{
    return rtrim((string) app_setting('site_url', SITE_URL), '/');
}

function runtime_base_url(): string
{
    return rtrim((string) app_setting('base_url', BASE_URL), '/');
}

function runtime_webhook_secret(): string
{
    return (string) app_setting('webhook_secret', WEBHOOK_SECRET);
}

function runtime_has_webhook_secret(): bool
{
    return trim(runtime_webhook_secret()) !== '';
}

function request_has_valid_webhook_token(): bool
{
    if (!runtime_has_webhook_secret()) {
        return false;
    }

    if (!isset($_GET['token']) || !is_string($_GET['token'])) {
        return false;
    }

    return hash_equals(runtime_webhook_secret(), $_GET['token']);
}

function runtime_ecompag_client_id(): string
{
    return runtime_pestopay_public_key();
}

function runtime_ecompag_client_secret(): string
{
    return runtime_pestopay_secret_key();
}

function runtime_pestopay_public_key(): string
{
    return trim((string) app_setting('pestopay_public_key', app_setting('ecompag_client_id', PESTOPAY_PUBLIC_KEY)));
}

function runtime_pestopay_secret_key(): string
{
    return trim((string) app_setting('pestopay_secret_key', app_setting('ecompag_client_secret', PESTOPAY_SECRET_KEY)));
}

function runtime_pestopay_webhook_token(): string
{
    return trim((string) app_setting('pestopay_webhook_token', ''));
}

function runtime_gateway_credentials_ready(): bool
{
    return runtime_pestopay_public_key() !== '' && runtime_pestopay_secret_key() !== '';
}

function runtime_telegram_bot_token(): string
{
    return (string) app_setting('telegram_bot_token', TELEGRAM_BOT_TOKEN);
}

function runtime_telegram_group_id(): string
{
    return (string) app_setting('telegram_group_id', TELEGRAM_GROUP_ID);
}

function runtime_telegram_api(): string
{
    return 'https://api.telegram.org/bot' . runtime_telegram_bot_token();
}

function runtime_telegram_webhook_url(): string
{
    return url_with_query_params(
        runtime_base_url() . '/telegram_webhook.php',
        tenant_public_query_params(true)
    );
}

function runtime_ecompag_notify_url(): string
{
    return url_with_query_params(
        runtime_base_url() . '/webhook_pix.php',
        tenant_public_query_params(true)
    );
}

function runtime_n8n_webhook_url(): string
{
    return rtrim((string) app_setting('n8n_webhook_url', N8N_WEBHOOK_URL), '/');
}

function runtime_n8n_secret(): string
{
    return trim((string) app_setting('n8n_secret', N8N_SECRET));
}

function runtime_n8n_timeout(): int
{
    $timeout = (int) app_setting('n8n_timeout', (string) N8N_TIMEOUT);
    return max(3, min(120, $timeout));
}

function runtime_n8n_enabled(): bool
{
    return filter_var(runtime_n8n_webhook_url(), FILTER_VALIDATE_URL) !== false;
}

function runtime_default_product_name(): string
{
    return (string) app_setting('default_product_name', DEFAULT_PRODUCT_NAME);
}

function runtime_default_product_description(): string
{
    return (string) app_setting('default_product_description', DEFAULT_PRODUCT_DESCRIPTION);
}

function runtime_default_product_price(): float
{
    return (float) app_setting('default_product_price', (string) DEFAULT_PRODUCT_PRICE);
}

function runtime_default_product_days(): int
{
    return (int) app_setting('default_product_days', (string) DEFAULT_PRODUCT_DAYS);
}

function cpf_request_mode_options(): array
{
    return [
        'before_catalog' => 'Pedir CPF antes de mostrar os planos',
        'after_plan' => 'Pedir CPF so depois que o usuario escolher o plano',
    ];
}

function runtime_cpf_request_mode(): string
{
    $mode = (string) app_setting('cpf_request_mode', 'after_plan');
    return array_key_exists($mode, cpf_request_mode_options()) ? $mode : 'after_plan';
}

function runtime_fixed_checkout_cpf(): string
{
    return preg_replace('/\D+/', '', (string) app_setting('checkout_fixed_cpf', '')) ?: '';
}

function runtime_fixed_checkout_name(): string
{
    return trim((string) app_setting('checkout_fixed_name', ''));
}

function runtime_fixed_checkout_phone(): string
{
    return preg_replace('/\D+/', '', (string) app_setting('checkout_fixed_phone', '')) ?: '';
}

function runtime_checkout_uses_backend_payer(): bool
{
    $cpf = runtime_fixed_checkout_cpf();
    return preg_match('/^\d{11}$/', $cpf) === 1;
}

function runtime_effective_checkout_cpf(array $usuario = []): string
{
    if (runtime_checkout_uses_backend_payer()) {
        return runtime_fixed_checkout_cpf();
    }

    return preg_replace('/\D+/', '', (string) ($usuario['cpf'] ?? '')) ?: '';
}

function runtime_effective_checkout_name(array $usuario = []): string
{
    if (runtime_checkout_uses_backend_payer()) {
        $fixedName = runtime_fixed_checkout_name();
        if ($fixedName !== '') {
            return $fixedName;
        }
    }

    $nome = trim((string) ($usuario['nome_pagador'] ?? ''));
    if ($nome !== '') {
        return $nome;
    }

    $firstName = trim((string) ($usuario['first_name'] ?? ''));
    $lastName = trim((string) ($usuario['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName !== '') {
        return $fullName;
    }

    return 'Cliente Telegram';
}

function runtime_effective_checkout_phone(array $usuario = []): string
{
    $candidates = [];

    $fixedPhone = runtime_fixed_checkout_phone();
    if ($fixedPhone !== '') {
        $candidates[] = $fixedPhone;
    }

    foreach (['phone', 'telefone', 'celular', 'whatsapp', 'mobile'] as $key) {
        $value = preg_replace('/\D+/', '', (string) ($usuario[$key] ?? '')) ?: '';
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    foreach ($candidates as $digits) {
        $digits = preg_replace('/\D+/', '', (string) $digits) ?: '';
        if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return $digits;
        }
    }

    return '';
}

function pestopay_format_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
    }

    return '';
}

function runtime_pestopay_checkout_phone(array $usuario = []): string
{
    return pestopay_format_phone(runtime_effective_checkout_phone($usuario));
}

function runtime_pestopay_checkout_ready(array $usuario = []): bool
{
    return preg_match('/^\d{11}$/', runtime_effective_checkout_cpf($usuario)) === 1
        && runtime_pestopay_checkout_phone($usuario) !== '';
}

function runtime_ui_text(string $key, string $default): string
{
    $value = trim((string) app_setting($key, $default));
    return $value !== '' ? $value : $default;
}

function runtime_start_plan_button_text(): string
{
    return runtime_ui_text('start_button_planos_text', 'Ver planos');
}

function runtime_start_pack_button_text(): string
{
    return runtime_ui_text('start_button_packs_text', 'Ver packs');
}

function runtime_pack_back_button_text(): string
{
    return runtime_ui_text('packs_back_button_text', 'Voltar ao menu principal');
}

function runtime_orderbump_accept_button_text(): string
{
    return runtime_ui_text('orderbump_accept_button_text', 'Adicionar ao pedido');
}

function runtime_orderbump_skip_button_text(): string
{
    return runtime_ui_text('orderbump_skip_button_text', 'Continuar sem');
}

function runtime_upsell_button_text(): string
{
    return runtime_ui_text('upsell_button_text', 'Quero a oferta');
}

function runtime_downsell_button_text(): string
{
    return runtime_ui_text('downsell_button_text', 'Quero esta opcao');
}

function texto_ascii_seguro(string $texto, int $maxLength = 0): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($converted !== false && $converted !== '') {
            $texto = $converted;
        }
    }

    $texto = preg_replace('/[^\x20-\x7E]+/', ' ', $texto) ?? '';
    $texto = preg_replace('/\s+/', ' ', $texto) ?? '';
    $texto = trim($texto);

    if ($maxLength > 0) {
        $texto = function_exists('mb_substr')
            ? mb_substr($texto, 0, $maxLength)
            : substr($texto, 0, $maxLength);
        $texto = trim($texto);
    }

    return $texto;
}

function runtime_usuario_precisa_informar_cpf(array $usuario = []): bool
{
    return false;
}

function runtime_start_media_tipo(): string
{
    return normalizar_media_tipo((string) app_setting('msg_start_media_tipo', 'none'));
}

function runtime_start_media_url(): string
{
    return trim((string) app_setting('msg_start_media_url', ''));
}

function runtime_start_audio_url(): string
{
    return trim((string) app_setting('msg_start_audio_url', ''));
}

function runtime_status_cpf_label(array $usuario = []): string
{
    if (runtime_checkout_uses_backend_payer()) {
        return 'Interno';
    }

    $cpf = preg_replace('/\D+/', '', (string) ($usuario['cpf'] ?? '')) ?: '';
    if (strlen($cpf) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }

    return 'Nao informado';
}

function runtime_should_request_cpf_before_catalog(): bool
{
    return false;
}

function bot_journey_message_groups(): array
{
    return [
        [
            'id' => 'entrada',
            'title' => 'Entrada e boas-vindas',
            'copy' => 'Define o comportamento base do /start e como o bot responde quando a conversa comeca.',
            'collapsed' => false,
            'fields' => [
                'msg_start_intro' => [
                    'label' => 'Mensagem inicial do /start',
                    'help' => 'O sistema completa essa mensagem com a orientacao final para o usuario seguir em /planos e gerar o Pix.',
                    'rows' => 6,
                ],
                'msg_start_after_plan' => [
                    'label' => 'Mensagem complementar do /start antes do /planos',
                    'help' => 'Texto final do /start apontando para /planos. Com CPF fixo no backend, a escolha do item ja gera o Pix automaticamente.',
                    'rows' => 3,
                ],
                'msg_start_ready_catalog' => [
                    'label' => 'Mensagem complementar do /start com checkout pronto',
                    'help' => 'Usada quando o usuario ja esta apto a comprar e pode ir direto para /planos.',
                    'rows' => 3,
                ],
                'msg_start_active' => [
                    'label' => 'Mensagem para usuario com acesso ativo',
                    'help' => 'Usada quando o usuario manda /start e ja possui acesso liberado.',
                    'rows' => 5,
                ],
                'msg_unknown' => [
                    'label' => 'Mensagem para comando nao reconhecido',
                    'help' => 'Fallback enviado quando o usuario manda algo que o bot nao entende.',
                    'rows' => 4,
                ],
                'msg_status' => [
                    'label' => 'Mensagem de /status',
                    'help' => 'Mostra status, expiracao e o CPF usado no checkout.',
                    'rows' => 5,
                ],
            ],
        ],
        [
            'id' => 'catalogo',
            'title' => 'Catalogo e escolha de ofertas',
            'copy' => 'Controla a vitrine que aparece quando o usuario chama /planos ou abre packs.',
            'collapsed' => false,
            'fields' => [
                'msg_choose_offer' => [
                    'label' => 'Mensagem quando existirem funis e planos',
                    'help' => 'Ideal para apresentar a vitrine principal.',
                    'rows' => 4,
                ],
                'msg_choose_plan' => [
                    'label' => 'Mensagem quando existirem apenas planos',
                    'help' => 'Usada quando nao ha funis ativos e o bot mostra so os planos.',
                    'rows' => 4,
                ],
                'msg_choose_pack' => [
                    'label' => 'Mensagem do menu de packs',
                    'help' => 'Aparece no submenu exclusivo de packs.',
                    'rows' => 4,
                ],
            ],
        ],
        [
            'id' => 'cpf',
            'title' => 'CPF e retomada da compra',
            'copy' => 'Essas mensagens so aparecem quando o checkout ainda depende do CPF do cliente. Se houver CPF fixo no backend, essa etapa e ignorada.',
            'collapsed' => false,
            'fields' => [
                'msg_request_cpf' => [
                    'label' => 'Mensagem pedindo CPF',
                    'help' => 'Esse texto e usado tanto no fluxo padrao quanto em compras retomadas apos clique em planos, funis ou packs.',
                    'rows' => 4,
                ],
                'msg_request_cpf_again' => [
                    'label' => 'Mensagem de reforco do CPF',
                    'help' => 'Usada quando o usuario toca no botao de informar CPF novamente.',
                    'rows' => 4,
                ],
                'msg_cpf_saved' => [
                    'label' => 'Mensagem de CPF salvo',
                    'help' => 'Confirma que o CPF foi salvo e, em seguida, o bot retoma a compra automaticamente.',
                    'rows' => 3,
                ],
                'msg_cpf_invalid' => [
                    'label' => 'Mensagem de CPF invalido',
                    'help' => 'Resposta quando o CPF enviado nao passa na validacao.',
                    'rows' => 3,
                ],
            ],
        ],
        [
            'id' => 'pix',
            'title' => 'Pix e confirmacao de pagamento',
            'copy' => 'Aqui ficam as mensagens da geracao do Pix, confirmacao e liberacao do acesso.',
            'collapsed' => false,
            'fields' => [
                'msg_pix_generating' => [
                    'label' => 'Mensagem gerando Pix',
            'help' => 'Enviada assim que o usuario escolhe o item e o bot vai falar com a PestoPay.',
                    'rows' => 3,
                ],
                'msg_pix_generated' => [
                    'label' => 'Mensagem com o Pix gerado',
                    'help' => 'Use placeholders como {produto}, {valor}, {txid} e {pix}.',
                    'rows' => 8,
                ],
                'msg_pix_error' => [
                    'label' => 'Mensagem de erro ao gerar Pix',
            'help' => 'Fallback quando a PestoPay nao responde como esperado.',
                    'rows' => 3,
                ],
                'msg_payment_confirmed' => [
                    'label' => 'Mensagem de pagamento confirmado com convite',
                    'help' => 'Usada para produtos de grupo quando o convite foi gerado.',
                    'rows' => 6,
                ],
                'msg_payment_confirmed_no_invite' => [
                    'label' => 'Mensagem de pagamento confirmado sem convite',
                    'help' => 'Fallback quando o acesso foi liberado, mas o convite falhou.',
                    'rows' => 5,
                ],
                'msg_expired' => [
                    'label' => 'Mensagem de expiracao do acesso',
                    'help' => 'Enviada quando a rotina remove o membro por vencimento.',
                    'rows' => 4,
                ],
            ],
        ],
        [
            'id' => 'ofertas',
            'title' => 'Packs, order bump, upsell, downsell e rotinas manuais',
            'copy' => 'Campos mais avancados para ofertas complementares e acoes manuais do admin.',
            'collapsed' => true,
            'fields' => [
                'msg_pack_delivered' => [
                    'label' => 'Mensagem de entrega de pack',
                    'help' => 'Use {pack_link} para inserir o link entregue automaticamente.',
                    'rows' => 5,
                ],
                'msg_pack_missing_link' => [
                    'label' => 'Mensagem de pack sem link configurado',
                    'help' => 'Fallback quando a compra do pack foi aprovada, mas o link nao existe no produto.',
                    'rows' => 4,
                ],
                'msg_orderbump_offer' => [
                    'label' => 'Mensagem base de order bump',
                    'help' => 'O texto da oferta configurado no order bump entra em {mensagem}.',
                    'rows' => 4,
                ],
                'msg_orderbump_delivered' => [
                    'label' => 'Mensagem de entrega do order bump',
                    'help' => 'Mensagem enviada depois da compra do order bump. Pode usar {conteudo}.',
                    'rows' => 5,
                ],
                'msg_orderbump_missing_link' => [
                    'label' => 'Order bump sem link configurado',
                    'help' => 'Fallback quando a oferta extra e um pack, mas o link ainda nao existe.',
                    'rows' => 4,
                ],
                'msg_upsell_offer' => [
                    'label' => 'Mensagem base de upsell',
                    'help' => 'O texto da oferta configurado no funil entra em {mensagem}.',
                    'rows' => 4,
                ],
                'msg_downsell_offer' => [
                    'label' => 'Mensagem base de downsell',
                    'help' => 'Tambem recebe o texto da oferta em {mensagem}.',
                    'rows' => 4,
                ],
                'msg_manual_activation' => [
                    'label' => 'Mensagem de ativacao manual',
                    'help' => 'Usada quando um admin libera o acesso pelo painel.',
                    'rows' => 4,
                ],
                'msg_admin_removed' => [
                    'label' => 'Mensagem de remocao manual',
                    'help' => 'Enviada quando um admin remove o usuario manualmente.',
                    'rows' => 3,
                ],
            ],
        ],
    ];
}

function bot_journey_message_fields(): array
{
    $fields = [];
    foreach (bot_journey_message_groups() as $group) {
        foreach ($group['fields'] as $key => $meta) {
            $fields[$key] = array_merge([
                'label' => $key,
                'help' => '',
                'rows' => 4,
                'group_id' => (string) ($group['id'] ?? ''),
            ], $meta);
        }
    }

    return $fields;
}

function bot_journey_message_values(): array
{
    $values = [];
    foreach (array_keys(bot_journey_message_fields()) as $key) {
        $values[$key] = message_template($key);
    }

    return $values;
}

function bot_default_route_blueprint(): array
{
    return [
        [
            'index' => '01',
            'title' => 'Boas-vindas no /start',
            'copy' => 'O bot recebe o usuario, mostra a mensagem inicial e ja entrega o atalho para abrir as ofertas pelo botao ou por /planos.',
            'message_keys' => ['msg_start_intro', 'msg_start_after_plan', 'msg_start_ready_catalog', 'msg_start_active'],
        ],
        [
            'index' => '02',
            'title' => 'Catalogo de planos e packs',
            'copy' => 'Quando o usuario chama /planos, o sistema mostra os funis, planos e o botao para abrir o menu de packs.',
            'message_keys' => ['msg_choose_offer', 'msg_choose_plan', 'msg_choose_pack'],
        ],
        [
            'index' => '03',
            'title' => 'Escolha do item',
            'copy' => 'O cliente toca em um funil, plano ou pack. A partir daqui o sistema segue para o checkout.',
            'message_keys' => [],
        ],
        [
            'index' => '04',
            'title' => 'Checkout e dados do pagador',
            'copy' => 'Se existir um CPF fixo configurado no backend, o bot usa esse dado e segue direto. Se nao existir, o sistema ainda pode solicitar o CPF conforme a configuracao.',
            'message_keys' => ['msg_request_cpf', 'msg_request_cpf_again', 'msg_cpf_saved', 'msg_cpf_invalid'],
        ],
        [
            'index' => '05',
            'title' => 'Geracao do Pix',
            'copy' => 'O bot gera o Pix, envia o copia e cola e segue o fluxo de confirmacao, entrega de pack ou liberacao do grupo.',
            'message_keys' => ['msg_pix_generating', 'msg_pix_generated', 'msg_pix_error'],
        ],
    ];
}

function get_fluxo_por_id(int $fluxoId): ?array
{
    if ($fluxoId <= 0 || !db_has_table('fluxos')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM fluxos WHERE id = ? AND ' . tenant_scope_condition('fluxos') . ' LIMIT 1');
    $stmt->execute([$fluxoId]);
    $fluxo = $stmt->fetch();

    return $fluxo ?: null;
}

function default_start_flow_blueprint(): array
{
    return [
        'nome' => 'Fluxo inicial (/start)',
        'descricao' => 'Fluxo padrao do bot para complementar a entrada principal do /start com etapas extras, se voce quiser.',
        'gatilho' => 'start',
        'comando' => null,
        'descricao_comando' => null,
        'produto_id' => null,
        'funil_id' => null,
        'ativo' => 1,
    ];
}

function ensure_default_start_flow(): ?array
{
    if (!flow_tables_ready()) {
        return null;
    }

    $storedId = db_has_table('configuracoes') ? (int) app_setting('system_start_flow_id', '0') : 0;
    if ($storedId > 0) {
        $flow = get_fluxo_por_id($storedId);
        if ($flow) {
            return $flow;
        }
    }

    $sql = "SELECT * FROM fluxos WHERE gatilho = 'start' AND " . tenant_scope_condition('fluxos');
    if (db_has_column('fluxos', 'comando')) {
        $sql .= " AND (comando IS NULL OR comando = '')";
    }
    if (db_has_column('fluxos', 'produto_id')) {
        $sql .= ' AND produto_id IS NULL';
    }
    if (db_has_column('fluxos', 'funil_id')) {
        $sql .= ' AND funil_id IS NULL';
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';

    $flow = null;
    try {
        $flow = db()->query($sql)->fetch() ?: null;
    } catch (Throwable $e) {
        $flow = null;
    }

    if (!$flow) {
        $blueprint = default_start_flow_blueprint();

        if (db_has_column('fluxos', 'comando') && db_has_column('fluxos', 'descricao_comando')) {
            $columns = ['nome', 'descricao', 'gatilho', 'comando', 'descricao_comando', 'produto_id', 'funil_id', 'ativo', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
            $params = [
                $blueprint['nome'],
                $blueprint['descricao'],
                $blueprint['gatilho'],
                null,
                null,
                null,
                null,
                $blueprint['ativo'],
                db_now(),
            ];
            tenant_insert_append('fluxos', $columns, $placeholders, $params);
            db()->prepare(
                'INSERT INTO fluxos (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($params);
        } else {
            $columns = ['nome', 'descricao', 'gatilho', 'produto_id', 'funil_id', 'ativo', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
            $params = [
                $blueprint['nome'],
                $blueprint['descricao'],
                $blueprint['gatilho'],
                null,
                null,
                $blueprint['ativo'],
                db_now(),
            ];
            tenant_insert_append('fluxos', $columns, $placeholders, $params);
            db()->prepare(
                'INSERT INTO fluxos (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($params);
        }

        $flow = get_fluxo_por_id((int) db()->lastInsertId());
    }

    if ($flow && db_has_table('configuracoes')) {
        $flowId = (string) ((int) ($flow['id'] ?? 0));
        if ($flowId !== '' && $flowId !== (string) $storedId) {
            app_setting_save('system_start_flow_id', $flowId);
        }
    }

    return $flow ?: null;
}

function default_start_flow_id(): int
{
    $flow = ensure_default_start_flow();
    return (int) ($flow['id'] ?? 0);
}

function is_default_start_flow_id(int $fluxoId): bool
{
    return $fluxoId > 0 && $fluxoId === default_start_flow_id();
}

function runtime_smtp_host(): string
{
    return (string) app_setting('smtp_host', SMTP_HOST);
}

function runtime_smtp_port(): int
{
    return (int) app_setting('smtp_port', (string) SMTP_PORT);
}

function runtime_smtp_user(): string
{
    return (string) app_setting('smtp_user', SMTP_USER);
}

function runtime_smtp_password(): string
{
    return (string) app_setting('smtp_password', SMTP_PASSWORD);
}

function runtime_meta_pixel_id(): string
{
    return trim((string) app_setting('meta_pixel_id', ''));
}

function runtime_meta_access_token(): string
{
    return trim((string) app_setting('meta_access_token', ''));
}

function runtime_meta_test_event_code(): string
{
    return trim((string) app_setting('meta_test_event_code', ''));
}

function runtime_meta_api_version(): string
{
    return trim((string) app_setting('meta_api_version', 'v22.0'));
}

function runtime_meta_action_source(): string
{
    return trim((string) app_setting('meta_action_source', 'chat'));
}

function runtime_meta_enabled(): bool
{
    return runtime_meta_pixel_id() !== '' && runtime_meta_access_token() !== '';
}

function db_has_table(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $sql = db_is_pgsql()
            ? 'SELECT 1
               FROM information_schema.tables
               WHERE table_schema = current_schema() AND table_name = ?
               LIMIT 1'
            : 'SELECT 1
               FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = ?
               LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            db()->query('SELECT 1 FROM ' . db_quote_identifier($table) . ' LIMIT 1');
            $cache[$table] = true;
        } catch (Throwable $e2) {
            $cache[$table] = false;
        }
    }

    return $cache[$table];
}

function db_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $sql = db_is_pgsql()
            ? 'SELECT 1
               FROM information_schema.columns
               WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?
               LIMIT 1'
            : 'SELECT 1
               FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
               LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function db_order_by_clause(string $table, string $alias = '', string $preferredColumn = 'ordem'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    if ($preferredColumn !== '' && db_has_column($table, $preferredColumn)) {
        return $prefix . $preferredColumn . ' ASC, ' . $prefix . 'id ASC';
    }

    return $prefix . 'id ASC';
}

function template_default(string $key): string
{
    $defaults = [
        'msg_start_intro' => "Ola, <b>{nome}</b>.\n\nBem-vindo. Toque no botao abaixo para ver os planos e gerar o Pix automaticamente.",
        'msg_start_audio_caption' => "Ouca esse audio rapido antes de escolher seu acesso.",
        'msg_start_cta' => "<b>Escolha como quer continuar</b>\n\nToque em <b>Planos</b> para entrar no grupo ou em <b>Packs</b> para receber materiais por link.",
        'msg_start_after_plan' => "Use <b>/planos</b> ou toque no botao abaixo para ver as ofertas. Assim que voce escolher um item, o bot ja gera o Pix automaticamente.",
        'msg_start_ready_catalog' => "Seu checkout ja esta pronto. Use <b>/planos</b> ou toque no botao abaixo para escolher o acesso.",
        'msg_start_active' => "Olá, <b>{nome}</b>.\nSeu acesso está <b>ativo</b> até <b>{expira}</b>.\n\nUse <b>/planos</b> para renovar antes do vencimento.",
        'msg_request_cpf' => "Perfeito. Agora envie seu CPF com 11 numeros para eu gerar o Pix desse item.\nExemplo: <code>12345678901</code>",
        'msg_request_cpf_again' => "Me envie seu CPF com 11 numeros para continuar e gerar o Pix.",
        'msg_unknown' => "Use <b>/start</b> para começar, <b>/status</b> para ver seu acesso e <b>/planos</b> para gerar um novo Pix.",
        'msg_status' => "<b>Status do seu acesso</b>\n\nStatus: <b>{status}</b>\nExpiração: <b>{expira}</b>\nCheckout: <b>{cpf}</b>\n\nUse <b>/planos</b> para gerar novo Pix.",
        'msg_choose_offer' => "<b>Escolha uma oferta</b>\n\nSelecione abaixo o plano ou pack que deseja comprar.",
        'msg_choose_plan' => "<b>Escolha um plano</b>\n\nDepois da sua escolha, o bot ja gera o Pix automaticamente.",
        'msg_choose_pack' => "<b>Escolha um pack</b>\n\nDepois da sua escolha, o bot ja gera o Pix para entregar o link automaticamente.",
        'msg_downsell_offer' => "<b>Oferta alternativa liberada</b>\n\n{mensagem}\n\nToque no botao abaixo para gerar o Pix desta opcao.",
        'msg_orderbump_offer' => "<b>Oferta extra liberada</b>\n\n{mensagem}\n\nToque no botao abaixo para adicionar ao pedido.",
        'msg_cpf_saved' => "CPF salvo com sucesso.",
        'msg_cpf_invalid' => "Esse CPF parece inválido. Envie novamente com 11 números.",
        'msg_pix_generating' => "Gerando seu Pix para <b>{produto}</b>. Aguarde alguns segundos.",
        'msg_pix_error' => "Nao consegui gerar o Pix agora. Verifique se o CPF e o telefone fixos do checkout estao corretos e se as credenciais da PestoPay estao validas.",
        'msg_pix_generated' => "<b>Pix gerado com sucesso</b>\n\nPlano: <b>{produto}</b>\nValor: <b>{valor}</b>\nTXID: <code>{txid}</code>\n\n<b>Copia e cola</b>\n<code>{pix}</code>\n\nApós o pagamento, o bot envia seu convite automaticamente.",
        'msg_payment_confirmed' => "Pagamento confirmado.\n\nOlá, <b>{nome}</b>.\nPlano: <b>{produto}</b>\nAcesso liberado até <b>{expira}</b>.\n\nEntre no grupo pelo link abaixo:\n{convite}\n\nEsse link é individual e expira em 1 hora.",
        'msg_payment_confirmed_no_invite' => "Pagamento confirmado.\n\nOlá, <b>{nome}</b>.\nSeu acesso já foi liberado até <b>{expira}</b>, mas houve falha ao gerar o convite automático.\nEntre em contato com o suporte para receber o link.",
        'msg_expired' => "Seu acesso expirou e o grupo foi bloqueado automaticamente.\n\nUse <b>/planos</b> para renovar.",
        'msg_manual_activation' => "✅ <b>Acesso liberado manualmente!</b>\n\nSeu acesso foi ativado por <b>{dias} dias</b>.\n\n👇 Clique para entrar no grupo:\n{convite}",
        'msg_admin_removed' => "⛔ Seu acesso foi removido pelo administrador.",
        'msg_orderbump_delivered' => "<b>Oferta extra aprovada</b>\n\nOla, <b>{nome}</b>.\nO produto <b>{produto}</b> foi liberado.\n\n{conteudo}",
        'msg_orderbump_missing_link' => "<b>Oferta extra aprovada</b>\n\nOla, <b>{nome}</b>.\nO produto <b>{produto}</b> foi liberado, mas nao consegui localizar o link automaticamente.\nEntre em contato com o suporte.",
        'msg_upsell_offer' => "<b>Oferta especial liberada</b>\n\n{mensagem}\n\nToque no botão abaixo para gerar o Pix da oferta.",
    ];

    return $defaults[$key] ?? '';
}

function template_default_runtime(string $key): string
{
    $defaults = [
        'msg_start_intro' => "Ola, <b>{nome}</b>.\n\nBem-vindo. Toque no botao abaixo para ver os planos e gerar o Pix automaticamente.",
        'msg_start_audio_caption' => "Ouca esse audio rapido antes de escolher seu acesso.",
        'msg_start_cta' => "<b>Escolha como quer continuar</b>\n\nToque em <b>Planos</b> para entrar no grupo ou em <b>Packs</b> para receber materiais por link.",
        'msg_start_after_plan' => "Use <b>/planos</b> ou toque no botao abaixo para ver as ofertas. Assim que voce escolher um item, o bot ja gera o Pix automaticamente.",
        'msg_start_ready_catalog' => "Seu checkout ja esta pronto. Use <b>/planos</b> ou toque no botao abaixo para escolher o acesso.",
        'msg_start_active' => "Ola, <b>{nome}</b>.\nSeu acesso esta <b>ativo</b> ate <b>{expira}</b>.\n\nUse <b>/planos</b> para renovar antes do vencimento.",
        'msg_request_cpf' => "Perfeito. Agora envie seu CPF com 11 numeros para eu gerar o Pix desse item.\nExemplo: <code>12345678901</code>",
        'msg_request_cpf_again' => "Me envie seu CPF com 11 numeros para continuar e gerar o Pix.",
        'msg_unknown' => "Use <b>/start</b> para comecar, <b>/status</b> para ver seu acesso e <b>/planos</b> para gerar um novo Pix.",
        'msg_status' => "<b>Status do seu acesso</b>\n\nStatus: <b>{status}</b>\nExpiracao: <b>{expira}</b>\nCheckout: <b>{cpf}</b>\n\nUse <b>/planos</b> para gerar novo Pix.",
        'msg_choose_offer' => "<b>Escolha uma oferta</b>\n\nSelecione abaixo o plano ou pack que deseja comprar.",
        'msg_choose_plan' => "<b>Escolha um plano</b>\n\nDepois da sua escolha, o bot ja gera o Pix automaticamente.",
        'msg_choose_pack' => "<b>Escolha um pack</b>\n\nDepois da sua escolha, o bot ja gera o Pix para entregar o link automaticamente.",
        'msg_downsell_offer' => "<b>Oferta alternativa liberada</b>\n\n{mensagem}\n\nToque no botao abaixo para gerar o Pix desta opcao.",
        'msg_cpf_saved' => "CPF salvo com sucesso.",
        'msg_cpf_invalid' => "Esse CPF parece invalido. Envie novamente com 11 numeros.",
        'msg_pix_generating' => "Gerando seu Pix para <b>{produto}</b>. Aguarde alguns segundos.",
        'msg_pix_error' => "Nao consegui gerar o Pix agora. Verifique se o CPF e o telefone fixos do checkout estao corretos e se as credenciais da PestoPay estao validas.",
        'msg_pix_generated' => "<b>Pix gerado com sucesso</b>\n\nProduto: <b>{produto}</b>\nValor: <b>{valor}</b>\nTXID: <code>{txid}</code>\n\n<b>Copia e cola</b>\n<code>{pix}</code>\n\nApos o pagamento, o bot libera seu acesso ou envia o link automaticamente.",
        'msg_payment_confirmed' => "Pagamento confirmado.\n\nOla, <b>{nome}</b>.\nPlano: <b>{produto}</b>\nAcesso liberado ate <b>{expira}</b>.\n\nEntre no grupo pelo link abaixo:\n{convite}\n\nEsse link e individual e expira em 1 hora.",
        'msg_payment_confirmed_no_invite' => "Pagamento confirmado.\n\nOla, <b>{nome}</b>.\nSeu acesso ja foi liberado ate <b>{expira}</b>, mas houve falha ao gerar o convite automatico.\nEntre em contato com o suporte para receber o link.",
        'msg_pack_delivered' => "Pagamento confirmado.\n\nOla, <b>{nome}</b>.\nProduto: <b>{produto}</b>\n\nAqui esta o link do seu pack:\n{pack_link}",
        'msg_pack_missing_link' => "Pagamento confirmado.\n\nOla, <b>{nome}</b>.\nSeu pack <b>{produto}</b> foi aprovado, mas o link ainda nao esta configurado.\nEntre em contato com o suporte para receber o acesso.",
        'msg_expired' => "Seu acesso expirou e o grupo foi bloqueado automaticamente.\n\nUse <b>/planos</b> para renovar.",
        'msg_manual_activation' => "<b>Acesso liberado manualmente!</b>\n\nSeu acesso foi ativado por <b>{dias} dias</b>.\n\nClique para entrar no grupo:\n{convite}",
        'msg_admin_removed' => "Seu acesso foi removido pelo administrador.",
        'msg_orderbump_delivered' => "<b>Oferta extra aprovada</b>\n\nOla, <b>{nome}</b>.\nO produto <b>{produto}</b> foi liberado.\n\n{conteudo}",
        'msg_orderbump_missing_link' => "<b>Oferta extra aprovada</b>\n\nOla, <b>{nome}</b>.\nO produto <b>{produto}</b> foi liberado, mas nao consegui localizar o link automaticamente.\nEntre em contato com o suporte.",
        'msg_orderbump_offer' => "<b>Oferta extra liberada</b>\n\n{mensagem}\n\nToque no botao abaixo para adicionar ao pedido.",
        'msg_upsell_offer' => "<b>Oferta especial liberada</b>\n\n{mensagem}\n\nToque no botao abaixo para gerar o Pix da oferta.",
    ];

    return $defaults[$key] ?? template_default($key);
}

function message_template(string $key): string
{
    $message = (string) app_setting($key, template_default_runtime($key));

    if ($key === 'msg_pix_error') {
        $legacyMessages = [
            'Nao consegui gerar o Pix agora. Verifique as credenciais da PestoPay e tente novamente em alguns minutos.',
            'Não consegui gerar o Pix agora. Verifique as credenciais da PestoPay e tente novamente em alguns minutos.',
        ];

        if (in_array(trim($message), $legacyMessages, true)) {
            return template_default_runtime($key);
        }
    }

    return $message;
}

function message_template_checkout_ready(string $key): string
{
    $message = message_template($key);

    if (!runtime_checkout_uses_backend_payer()) {
        return $message;
    }

    $legacyMap = [
        'msg_start_after_plan' => [
            'Use <b>/planos</b> para ver as ofertas. O CPF so sera pedido quando voce escolher um plano.',
            'Use <b>/planos</b> ou toque no botao abaixo para ver as ofertas. Assim que voce escolher um item, o bot ja gera o Pix automaticamente.',
        ],
        'msg_choose_plan' => [
            '<b>Escolha um plano</b>' . "\n\n" . 'Depois da sua escolha, o bot pede o CPF e ja gera o Pix automaticamente.',
            '<b>Escolha um plano</b>' . "\n\n" . 'Depois da sua escolha, o bot ja gera o Pix automaticamente.',
        ],
        'msg_choose_pack' => [
            '<b>Escolha um pack</b>' . "\n\n" . 'Depois da sua escolha, o bot pede o CPF e gera o Pix para entregar o link automaticamente.',
            '<b>Escolha um pack</b>' . "\n\n" . 'Depois da sua escolha, o bot ja gera o Pix para entregar o link automaticamente.',
        ],
    ];

    if (!isset($legacyMap[$key])) {
        return $message;
    }

    [$legacy, $updated] = $legacyMap[$key];
    return trim($message) === trim($legacy) ? $updated : $message;
}

function render_template(string $template, array $vars = []): string
{
    $replace = [];
    foreach ($vars as $key => $value) {
        $replace['{' . $key . '}'] = (string) $value;
    }

    return strtr($template, $replace);
}

function flow_trigger_options(): array
{
    return [
        'start' => 'Quando o usuario usar /start',
        'comando' => 'Quando o usuario usar um comando',
        'cpf_salvo' => 'Quando o CPF for salvo',
        'pix_gerado' => 'Quando um Pix for gerado',
        'pagamento_aprovado' => 'Quando um pagamento for aprovado',
        'pack_entregue' => 'Quando um pack for entregue',
        'acesso_expirado' => 'Quando o acesso expirar',
    ];
}

function normalize_bot_command(string $command): string
{
    $command = trim($command);
    if ($command === '') {
        return '';
    }

    $command = preg_split('/\s+/', $command)[0] ?? '';
    $command = trim((string) $command);

    if ($command === '') {
        return '';
    }

    if ($command[0] !== '/') {
        $command = '/' . $command;
    }

    $command = preg_replace('/@.+$/', '', $command) ?: '';
    $command = strtolower($command);

    return preg_match('/^\/[a-z0-9_]{1,32}$/', $command) ? $command : '';
}

function flow_command_defaults(): array
{
    $defaults = [
        '/start' => 'Iniciar atendimento',
        '/status' => 'Ver status do acesso',
        '/planos' => 'Ver planos disponiveis',
        '/renovar' => 'Gerar novo Pix para renovar',
    ];

    return $defaults;
}

function flow_button_type_options(): array
{
    return [
        'none' => 'Sem botao',
        'url' => 'Abrir URL',
        'planos' => 'Abrir menu de planos',
        'packs' => 'Abrir menu de packs',
        'produto' => 'Comprar produto',
    ];
}

function flow_tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    foreach (['fluxos', 'fluxo_etapas', 'fluxo_execucoes'] as $table) {
        if (!db_has_table($table)) {
            $ready = false;
            return false;
        }
    }

    $ready = true;
    return true;
}

function formatar_status_usuario(?string $status): string
{
    return match ((string) $status) {
        'ativo' => 'Ativo',
        'expirado' => 'Expirado',
        default => 'Pendente',
    };
}

function flow_context_payload(array $context = []): array
{
    $usuario = is_array($context['usuario'] ?? null) ? $context['usuario'] : [];
    $produto = is_array($context['produto'] ?? null) ? $context['produto'] : [];
    $pix = is_array($context['pix'] ?? null) ? $context['pix'] : [];
    $pagamento = is_array($context['pagamento'] ?? null) ? $context['pagamento'] : [];
    $pixTexto = $context['pix'] ?? null;

    if (is_array($pixTexto)) {
        $pixTexto = $pixTexto['qr_code'] ?? '';
    } elseif ($pixTexto !== null && !is_scalar($pixTexto)) {
        $pixTexto = '';
    }

    $nome = (string) ($context['nome'] ?? runtime_effective_checkout_name($usuario) ?? $usuario['first_name'] ?? 'Cliente');
    $cpf = (string) ($context['cpf'] ?? '');
    if ($cpf === '') {
        $cpf = runtime_status_cpf_label($usuario);
    } else {
        $cpf = preg_replace('/\D+/', '', $cpf);
        if (strlen($cpf) === 11) {
            $cpf = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
        }
    }

    $valorAtual = (float) ($context['valor'] ?? $produto['valor'] ?? $pagamento['valor'] ?? 0);
    $valorOriginal = (float) ($context['valor_original'] ?? $produto['valor_original'] ?? $produto['valor'] ?? $pagamento['valor'] ?? 0);
    $desconto = desconto_percentual_normalizado(
        $context['desconto']
        ?? $produto['desconto_percentual']
        ?? ($valorOriginal > 0 && $valorAtual < $valorOriginal
            ? (100 - (($valorAtual / $valorOriginal) * 100))
            : 0)
    );

    $expiraBruto = (string) ($context['expira'] ?? $usuario['data_expiracao'] ?? '');
    if ($expiraBruto !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $expiraBruto)) {
        $expiraBruto = date('d/m/Y H:i', strtotime($expiraBruto));
    }

    return [
        'nome' => htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'),
        'username' => htmlspecialchars((string) ($usuario['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'cpf' => htmlspecialchars((string) $cpf, ENT_QUOTES, 'UTF-8'),
        'status' => htmlspecialchars((string) ($context['status'] ?? formatar_status_usuario($usuario['status'] ?? 'pendente')), ENT_QUOTES, 'UTF-8'),
        'produto' => htmlspecialchars((string) ($context['produto_nome'] ?? $produto['nome'] ?? $pagamento['produto_nome'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'valor' => formatar_valor($valorAtual),
        'valor_original' => formatar_valor($valorOriginal),
        'desconto' => number_format($desconto, 0, ',', '.'),
        'txid' => htmlspecialchars((string) ($context['txid'] ?? $pix['txid'] ?? $pagamento['txid'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'pix' => htmlspecialchars((string) ($pixTexto ?? $pix['qr_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'expira' => htmlspecialchars((string) $expiraBruto, ENT_QUOTES, 'UTF-8'),
        'convite' => htmlspecialchars((string) ($context['convite'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'dias' => htmlspecialchars((string) ($context['dias'] ?? $produto['dias_acesso'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'pack_link' => htmlspecialchars((string) ($context['pack_link'] ?? $produto['pack_link'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'comando' => htmlspecialchars((string) normalize_bot_command((string) ($context['comando'] ?? '')), ENT_QUOTES, 'UTF-8'),
        'mensagem' => (string) ($context['mensagem'] ?? ''),
    ];
}

function flow_step_reply_markup(array $etapa): ?string
{
    $tipo = strtolower(trim((string) ($etapa['botao_tipo'] ?? 'none')));
    $texto = trim((string) ($etapa['botao_texto'] ?? ''));
    $botao = null;

    if ($tipo === 'url') {
        $url = trim((string) ($etapa['botao_url'] ?? ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $botao = [
            'text' => $texto !== '' ? $texto : 'Abrir link',
            'url' => $url,
        ];
    }

    if ($tipo === 'planos') {
        $botao = [
            'text' => $texto !== '' ? $texto : runtime_start_plan_button_text(),
            'callback_data' => 'menu_catalogo',
        ];
    }

    if ($tipo === 'packs') {
        $botao = [
            'text' => $texto !== '' ? $texto : runtime_start_pack_button_text(),
            'callback_data' => 'menu_packs',
        ];
    }

    if ($tipo === 'produto') {
        $produtoId = (int) ($etapa['botao_produto_id'] ?? 0);
        if ($produtoId <= 0) {
            return null;
        }

        $botao = [
            'text' => $texto !== '' ? $texto : 'Comprar agora',
            'callback_data' => 'comprar:' . $produtoId,
        ];
    }

    if (!$botao) {
        return null;
    }

    return json_encode([
        'inline_keyboard' => [[$botao]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function enviar_etapa_fluxo(int $chatId, array $etapa, array $payload): bool
{
    $mensagem = render_template((string) ($etapa['mensagem'] ?? ''), $payload);
    if ($mensagem === '' && trim((string) ($etapa['media_url'] ?? '')) === '') {
        return false;
    }

    $extra = [];
    $replyMarkup = flow_step_reply_markup($etapa);
    if ($replyMarkup !== null) {
        $extra['reply_markup'] = $replyMarkup;
    }

    $response = enviar_conteudo_telegram(
        $chatId,
        $mensagem,
        (string) ($etapa['media_tipo'] ?? 'none'),
        (string) ($etapa['media_url'] ?? ''),
        $extra
    );

    return is_array($response) && !empty($response['ok']);
}

function get_fluxo_etapas(int $fluxoId, bool $onlyActive = true): array
{
    if (!flow_tables_ready()) {
        return [];
    }

    $sql = 'SELECT * FROM fluxo_etapas WHERE fluxo_id = ? AND ' . tenant_scope_condition('fluxo_etapas');
    if ($onlyActive) {
        $sql .= ' AND ativo = 1';
    }
    $sql .= ' ORDER BY ' . db_order_by_clause('fluxo_etapas');

    $stmt = db()->prepare($sql);
    $stmt->execute([$fluxoId]);
    return $stmt->fetchAll();
}

function disparar_fluxos(string $gatilho, array $context = []): array
{
    if (!flow_tables_ready()) {
        return ['fluxos' => 0, 'etapas' => 0, 'enviadas' => 0, 'agendadas' => 0];
    }

    $usuario = is_array($context['usuario'] ?? null) ? $context['usuario'] : [];
    $usuarioId = (int) ($usuario['id'] ?? 0);
    $chatId = (int) ($usuario['telegram_id'] ?? 0);
    if ($usuarioId <= 0) {
        return ['fluxos' => 0, 'etapas' => 0, 'enviadas' => 0, 'agendadas' => 0];
    }

    $produto = is_array($context['produto'] ?? null) ? $context['produto'] : [];
    $produtoId = (int) ($context['produto_id'] ?? $produto['id'] ?? 0);
    $funilId = (int) ($context['funil_id'] ?? 0);
    $comando = normalize_bot_command((string) ($context['comando'] ?? ''));
    $payload = flow_context_payload($context);

    $sql = 'SELECT * FROM fluxos WHERE ativo = 1 AND gatilho = ? AND ' . tenant_scope_condition('fluxos');
    $params = [$gatilho];

    if (db_has_column('fluxos', 'comando')) {
        if ($gatilho === 'comando') {
            if ($comando === '') {
                return ['fluxos' => 0, 'etapas' => 0, 'enviadas' => 0, 'agendadas' => 0];
            }

            $sql .= ' AND comando = ?';
            $params[] = $comando;
        } else {
            $sql .= " AND (comando IS NULL OR comando = '')";
        }
    }

    if ($produtoId > 0) {
        $sql .= ' AND (produto_id IS NULL OR produto_id = ?)';
        $params[] = $produtoId;
    } else {
        $sql .= ' AND produto_id IS NULL';
    }

    if ($funilId > 0) {
        $sql .= ' AND (funil_id IS NULL OR funil_id = ?)';
        $params[] = $funilId;
    } else {
        $sql .= ' AND funil_id IS NULL';
    }

    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fluxos = $stmt->fetchAll();

    $etapasTotal = 0;
    $enviadas = 0;
    $agendadas = 0;
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $referenciaTipo = trim((string) ($context['referencia_tipo'] ?? ''));
    $referenciaId = (int) ($context['referencia_id'] ?? 0);

    foreach ($fluxos as $fluxo) {
        $etapas = get_fluxo_etapas((int) $fluxo['id'], true);
        foreach ($etapas as $etapa) {
            $etapasTotal++;
            $delayMinutes = max(0, (int) ($etapa['delay_minutes'] ?? 0));

            if ($delayMinutes === 0 && $chatId > 0) {
                if (enviar_etapa_fluxo($chatId, $etapa, $payload)) {
                    $enviadas++;
                }
                continue;
            }

            $scheduledAt = date('Y-m-d H:i:s', strtotime('+' . $delayMinutes . ' minutes'));
            $columns = ['fluxo_id', 'etapa_id', 'usuario_id', 'gatilho', 'referencia_tipo', 'referencia_id', 'payload_context', 'status', 'scheduled_at', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
            $params = [
                (int) $fluxo['id'],
                (int) $etapa['id'],
                $usuarioId,
                $gatilho,
                $referenciaTipo !== '' ? $referenciaTipo : null,
                $referenciaId > 0 ? $referenciaId : null,
                $payloadJson,
                'pendente',
                $scheduledAt,
                db_now(),
            ];
            tenant_insert_append('fluxo_execucoes', $columns, $placeholders, $params);
            db()->prepare(
                'INSERT INTO fluxo_execucoes
                 (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($params);
            $agendadas++;
        }
    }

    if ($fluxos) {
        log_evento('flow_disparado', 'Fluxo disparado.', [
            'gatilho' => $gatilho,
            'comando' => $comando !== '' ? $comando : null,
            'usuario_id' => $usuarioId,
            'produto_id' => $produtoId ?: null,
            'funil_id' => $funilId ?: null,
            'fluxos' => count($fluxos),
            'enviadas' => $enviadas,
            'agendadas' => $agendadas,
        ]);
    }

    return [
        'fluxos' => count($fluxos),
        'etapas' => $etapasTotal,
        'enviadas' => $enviadas,
        'agendadas' => $agendadas,
    ];
}

function processar_fluxos_pendentes(int $limit = 20): array
{
    if (!flow_tables_ready()) {
        return ['processados' => 0, 'enviados' => 0, 'falhas' => 0];
    }

    $limit = max(1, min(100, $limit));
    $tenantSelect = db_has_column('fluxo_execucoes', 'tenant_id')
        ? ', fe.tenant_id AS tenant_id'
        : (db_has_column('usuarios', 'tenant_id') ? ', u.tenant_id AS tenant_id' : ', NULL AS tenant_id');

    $stmt = db()->prepare(
        'SELECT fe.*, u.telegram_id,
                ' . ltrim($tenantSelect, ',') . ',
                fl.ativo AS fluxo_ativo,
                et.ativo AS etapa_ativa, et.mensagem, et.media_tipo, et.media_url,
                et.botao_tipo, et.botao_texto, et.botao_url, et.botao_produto_id
         FROM fluxo_execucoes fe
         JOIN usuarios u ON u.id = fe.usuario_id
         JOIN fluxos fl ON fl.id = fe.fluxo_id
         JOIN fluxo_etapas et ON et.id = fe.etapa_id
         WHERE fe.status = ?
           AND fe.scheduled_at <= ?
         ORDER BY fe.scheduled_at ASC, fe.id ASC
         LIMIT ' . $limit
    );
    $stmt->execute(['pendente', db_now()]);
    $rows = $stmt->fetchAll();

    $processados = 0;
    $enviados = 0;
    $falhas = 0;
    $previousTenant = current_tenant();

    foreach ($rows as $row) {
        $processados++;
        if (tenants_enabled() && !empty($row['tenant_id'])) {
            set_current_tenant_context(get_tenant_by_id((int) $row['tenant_id']));
        }

        if ((int) ($row['fluxo_ativo'] ?? 0) !== 1 || (int) ($row['etapa_ativa'] ?? 0) !== 1) {
            db()->prepare("UPDATE fluxo_execucoes SET status = 'cancelado', last_error = ? WHERE id = ?")
                ->execute(['Fluxo ou etapa inativa.', (int) $row['id']]);
            continue;
        }

        if ((int) ($row['telegram_id'] ?? 0) <= 0) {
            $falhas++;
            db()->prepare("UPDATE fluxo_execucoes SET status = 'falhou', last_error = ? WHERE id = ?")
                ->execute(['Usuario sem telegram_id valido.', (int) $row['id']]);
            continue;
        }

        $payload = json_decode((string) ($row['payload_context'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $ok = enviar_etapa_fluxo((int) $row['telegram_id'], $row, $payload);
        if ($ok) {
            $enviados++;
            db()->prepare("UPDATE fluxo_execucoes SET status = 'enviado', sent_at = ?, last_error = NULL WHERE id = ?")
                ->execute([db_now(), (int) $row['id']]);
        } else {
            $falhas++;
            db()->prepare("UPDATE fluxo_execucoes SET status = 'falhou', last_error = ? WHERE id = ?")
                ->execute(['Falha ao enviar via Telegram.', (int) $row['id']]);
        }
    }

    set_current_tenant_context($previousTenant);

    return [
        'processados' => $processados,
        'enviados' => $enviados,
        'falhas' => $falhas,
    ];
}

function meta_hash_value(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return hash('sha256', strtolower($value));
}

function meta_event_endpoint(): string
{
    return sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        rawurlencode(runtime_meta_api_version()),
        rawurlencode(runtime_meta_pixel_id()),
        rawurlencode(runtime_meta_access_token())
    );
}

function meta_build_user_data(array $usuario = []): array
{
    $userData = [];

    if (!empty($usuario['telegram_id'])) {
        $userData['external_id'] = meta_hash_value('telegram:' . (string) $usuario['telegram_id']);
    } elseif (!empty($usuario['id'])) {
        $userData['external_id'] = meta_hash_value('usuario:' . (string) $usuario['id']);
    }

    if (!empty($usuario['first_name'])) {
        $userData['fn'] = meta_hash_value((string) $usuario['first_name']);
    }

    return array_filter($userData, static fn($value) => $value !== null && $value !== '');
}

function meta_send_event(string $eventName, array $usuario = [], array $customData = [], array $options = []): bool
{
    if (!runtime_meta_enabled()) {
        return false;
    }

    $event = [
        'event_name' => $eventName,
        'event_time' => $options['event_time'] ?? time(),
        'action_source' => $options['action_source'] ?? runtime_meta_action_source(),
        'event_source_url' => $options['event_source_url'] ?? (runtime_base_url() . '/telegram'),
        'user_data' => meta_build_user_data($usuario),
        'custom_data' => $customData,
    ];

    if (!empty($options['event_id'])) {
        $event['event_id'] = (string) $options['event_id'];
    }

    $payload = ['data' => [$event]];

    $testEventCode = runtime_meta_test_event_code();
    if ($testEventCode !== '') {
        $payload['test_event_code'] = $testEventCode;
    }

    $ch = curl_init(meta_event_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_evento('meta_curl_error', 'Falha ao enviar evento Meta.', [
            'event_name' => $eventName,
            'error' => $error,
        ]);
        return false;
    }

    $decoded = json_decode((string) $response, true);
    $ok = $httpCode >= 200 && $httpCode < 300 && empty($decoded['error']);

    if ($ok) {
        log_evento('meta_event_sent', 'Evento Meta enviado com sucesso.', [
            'event_name' => $eventName,
            'event_id' => $event['event_id'] ?? null,
        ]);
        return true;
    }

    log_evento('meta_event_failed', 'Falha ao enviar evento Meta.', [
        'event_name' => $eventName,
        'http_code' => $httpCode,
        'response' => $decoded ?: $response,
    ]);

    return false;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        if (DB_DSN !== '') {
            $dsn = DB_DSN;
        } elseif (db_is_pgsql()) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_SSLMODE
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => DB_EMULATE_PREPARES === '1',
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        if (db_is_pgsql()) {
            $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");
        }
    }

    return $pdo;
}

function build_automacao_payload(string $evento, array $payload = [], string $source = 'telegram-bot-app'): array
{
    return [
        'event' => $evento,
        'sent_at' => date(DATE_ATOM),
        'source' => $source,
        'site_url' => runtime_site_url(),
        'base_url' => runtime_base_url(),
        'tenant' => current_tenant() ? [
            'id' => current_tenant_id(),
            'slug' => current_tenant_slug(),
            'nome' => current_tenant_name(),
        ] : null,
        'payload' => $payload,
    ];
}

function disparar_webhook_externo(string $evento, string $webhookUrl, array $payload = [], string $secret = '', string $source = 'telegram-bot-app'): bool
{
    $webhookUrl = trim($webhookUrl);
    if (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $headers = [
        'Content-Type: application/json',
        'X-App-Event: ' . $evento,
    ];

    $secret = trim($secret);
    if ($secret !== '') {
        $headers[] = 'X-App-Secret: ' . $secret;
    }

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => runtime_n8n_timeout(),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(build_automacao_payload($evento, $payload, $source), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_evento('webhook_automacao_erro', 'Falha ao disparar webhook externo.', [
            'event' => $evento,
            'url' => $webhookUrl,
            'error' => $error,
        ]);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        log_evento('webhook_automacao_ok', 'Webhook externo enviado.', [
            'event' => $evento,
            'url' => $webhookUrl,
            'http_code' => $httpCode,
        ]);
        return true;
    }

    log_evento('webhook_automacao_falhou', 'Webhook externo respondeu com erro.', [
        'event' => $evento,
        'url' => $webhookUrl,
        'http_code' => $httpCode,
        'response' => substr((string) $response, 0, 2000),
    ]);
    return false;
}

function disparar_n8n_evento(string $evento, array $payload = []): bool
{
    if (!runtime_n8n_enabled()) {
        return false;
    }

    return disparar_webhook_externo(
        $evento,
        runtime_n8n_webhook_url(),
        $payload,
        runtime_n8n_secret(),
        'telegram-bot-app'
    );
}

function usuario_ja_virou_cliente(array $usuario): bool
{
    if (($usuario['status'] ?? '') === 'ativo') {
        return true;
    }

    $usuarioId = (int) ($usuario['id'] ?? 0);
    if ($usuarioId <= 0 || !db_has_table('pagamentos')) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM pagamentos
         WHERE usuario_id = ?
           AND status = 'pago'
           AND " . tenant_scope_condition('pagamentos')
    );
    $stmt->execute([$usuarioId]);

    return (int) $stmt->fetchColumn() > 0;
}

function remarketing_event_options(): array
{
    return [
        'lead_start' => 'Lead iniciou no /start (nao cliente)',
        'pix_gerado' => 'Pix gerado',
        'pagamento_aprovado' => 'Pagamento aprovado',
        'pack_entregue' => 'Pack entregue',
        'acesso_expirado' => 'Acesso expirado',
        'orderbump_ofertado' => 'Order bump ofertado',
        'upsell_ofertado' => 'Upsell ofertado',
        'downsell_ofertado' => 'Downsell ofertado',
    ];
}

function disparar_remarketing_webhooks(string $evento, array $payload = []): int
{
    if (!db_has_table('remarketing_webhooks')) {
        log_evento('remarketing_sem_tabela', 'Tabela de remarketing nao encontrada para disparo.', [
            'event' => $evento,
        ]);
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT * FROM remarketing_webhooks
         WHERE ativo = 1 AND evento = ? AND ' . tenant_scope_condition('remarketing_webhooks') . '
         ORDER BY ' . db_order_by_clause('remarketing_webhooks')
    );
    $stmt->execute([$evento]);
    $webhooks = $stmt->fetchAll();

    if (!$webhooks) {
        log_evento('remarketing_sem_regras', 'Nenhuma regra ativa de remarketing encontrada para o evento.', [
            'event' => $evento,
            'tenant' => current_tenant_slug(),
        ]);
        return 0;
    }

    $sent = 0;
    foreach ($webhooks as $webhook) {
        if (disparar_webhook_externo(
            $evento,
            (string) ($webhook['webhook_url'] ?? ''),
            $payload,
            (string) ($webhook['webhook_secret'] ?? ''),
            'remarketing'
        )) {
            $sent++;
        }
    }

    log_evento('remarketing_disparo', 'Disparo de remarketing processado.', [
        'event' => $evento,
        'tenant' => current_tenant_slug(),
        'rules' => count($webhooks),
        'sent' => $sent,
    ]);

    return $sent;
}

function log_evento(string $tipo, string $mensagem, array $dados = []): void
{
    $linha = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($tipo),
        $mensagem,
        $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    file_put_contents(LOG_FILE, $linha, FILE_APPEND | LOCK_EX);

    try {
        $columns = ['tipo', 'mensagem', 'dados'];
        $placeholders = ['?', '?', '?'];
        $params = [
            $tipo,
            $mensagem,
            $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];

        tenant_insert_append('logs', $columns, $placeholders, $params);

        db()->prepare(
            'INSERT INTO logs (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        )->execute($params);
    } catch (Throwable $e) {
        // Evita loop de erro ao logar.
    }
}

function executar_worker_com_lock(string $nome, callable $callback): array
{
    $job = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($nome))) ?: 'worker';
    $lockPath = LOGS_DIR . '/worker-' . $job . '.lock';
    $handle = @fopen($lockPath, 'c+');

    if ($handle === false) {
        return [
            'ok' => false,
            'status' => 'error',
            'job' => $job,
            'lock_path' => $lockPath,
            'error' => 'Nao foi possivel abrir o arquivo de lock do worker.',
        ];
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return [
            'ok' => true,
            'status' => 'locked',
            'job' => $job,
            'lock_path' => $lockPath,
        ];
    }

    $startedAt = microtime(true);

    try {
        $result = $callback();
        return [
            'ok' => true,
            'status' => 'completed',
            'job' => $job,
            'lock_path' => $lockPath,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'result' => is_array($result) ? $result : ['value' => $result],
        ];
    } catch (Throwable $e) {
        log_evento('worker_exception', 'Falha ao executar worker.', [
            'job' => $job,
            'error' => $e->getMessage(),
        ]);

        return [
            'ok' => false,
            'status' => 'error',
            'job' => $job,
            'lock_path' => $lockPath,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $e->getMessage(),
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function telegram_request(string $method, array $params = []): ?array
{
    if (runtime_telegram_bot_token() === 'SEU_TOKEN_DO_BOT') {
        log_evento('telegram_config', 'Token do bot ainda não configurado.');
        return null;
    }

    $ch = curl_init(runtime_telegram_api() . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_evento('telegram_curl_error', $error, ['method' => $method]);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if ($httpCode >= 400 || !is_array($decoded)) {
        log_evento('telegram_http_error', 'Falha na API do Telegram', [
            'method' => $method,
            'http_code' => $httpCode,
            'response' => $response,
        ]);
        return null;
    }

    return $decoded;
}

function get_fluxos_comando_ativos(): array
{
    if (!flow_tables_ready() || !db_has_column('fluxos', 'comando')) {
        return [];
    }

    try {
        return db()->query(
            'SELECT * FROM fluxos
             WHERE ativo = 1
               AND ' . tenant_scope_condition('fluxos') . '
               AND gatilho = \'comando\'
               AND comando IS NOT NULL
               AND comando <> \'\'
             ORDER BY ' . db_order_by_clause('fluxos')
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function telegram_sync_commands(): array
{
    $defaults = flow_command_defaults();
    $commands = [];
    $map = [];

    foreach ($defaults as $command => $description) {
        $normalized = normalize_bot_command($command);
        if ($normalized === '') {
            continue;
        }

        $commands[] = [
            'command' => ltrim($normalized, '/'),
            'description' => $description,
        ];
        $map[$normalized] = count($commands) - 1;
    }

    foreach (get_fluxos_comando_ativos() as $fluxo) {
        $normalized = normalize_bot_command((string) ($fluxo['comando'] ?? ''));
        if ($normalized === '') {
            continue;
        }

        $description = trim((string) ($fluxo['descricao_comando'] ?? $fluxo['descricao'] ?? $fluxo['nome'] ?? ''));
        if ($description === '') {
            $description = 'Abrir comando';
        }

        $description = function_exists('mb_substr')
            ? mb_substr($description, 0, 256)
            : substr($description, 0, 256);

        $payload = [
            'command' => ltrim($normalized, '/'),
            'description' => $description,
        ];

        if (array_key_exists($normalized, $map)) {
            $commands[$map[$normalized]] = $payload;
        } else {
            $map[$normalized] = count($commands);
            $commands[] = $payload;
        }
    }

    $response = telegram_request('setMyCommands', [
        'commands' => $commands,
    ]);

    return [
        'ok' => is_array($response) && !empty($response['ok']),
        'commands' => $commands,
        'response' => $response,
    ];
}

function enviar_mensagem(int $chatId, string $texto, array $extra = []): ?array
{
    $payload = array_merge([
        'chat_id' => $chatId,
        'text' => $texto,
        'parse_mode' => 'HTML',
    ], $extra);

    return telegram_request('sendMessage', $payload);
}

function normalizar_media_tipo(?string $tipo, bool $allowNone = true): string
{
    $tipo = strtolower(trim((string) $tipo));

    return match ($tipo) {
        'photo', 'imagem', 'image' => 'photo',
        'video' => 'video',
        'audio', 'voice' => 'audio',
        'document', 'arquivo', 'file' => 'document',
        default => $allowNone ? 'none' : 'photo',
    };
}

function telegram_media_nome_arquivo(string $mediaUrl, string $fallback = 'arquivo'): string
{
    $mediaUrl = trim($mediaUrl);
    if ($mediaUrl === '') {
        return $fallback;
    }

    $filename = '';
    $query = parse_url($mediaUrl, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $queryParams);
        foreach (['n', 'filename', 'file', 'name'] as $key) {
            $value = $queryParams[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $filename = trim($value);
                break;
            }
        }
    }

    if ($filename === '') {
        $path = (string) parse_url($mediaUrl, PHP_URL_PATH);
        $filename = basename($path);
    }

    $filename = trim($filename);
    return $filename !== '' ? $filename : $fallback;
}

function telegram_media_tipo_resolvido(string $mediaTipo, string $mediaUrl = ''): string
{
    $mediaTipo = normalizar_media_tipo($mediaTipo);
    if ($mediaTipo !== 'audio') {
        return $mediaTipo;
    }

    $filename = strtolower(telegram_media_nome_arquivo($mediaUrl, 'audio'));
    if (str_contains($filename, '.ogg') || str_contains($filename, '.oga') || str_contains($filename, 'voice_')) {
        return 'voice';
    }

    return 'audio';
}

function telegram_media_metodo_campo(string $mediaTipo): array
{
    return match ($mediaTipo) {
        'video' => ['sendVideo', 'video'],
        'audio' => ['sendAudio', 'audio'],
        'voice' => ['sendVoice', 'voice'],
        'document' => ['sendDocument', 'document'],
        default => ['sendPhoto', 'photo'],
    };
}

function telegram_request_multipart(string $method, array $params = []): ?array
{
    if (runtime_telegram_bot_token() === 'SEU_TOKEN_DO_BOT') {
        log_evento('telegram_config', 'Token do bot ainda não configurado.');
        return null;
    }

    $ch = curl_init(runtime_telegram_api() . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_evento('telegram_curl_error', $error, ['method' => $method, 'multipart' => true]);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if ($httpCode >= 400 || !is_array($decoded)) {
        log_evento('telegram_http_error', 'Falha na API do Telegram', [
            'method' => $method,
            'http_code' => $httpCode,
            'response' => $response,
            'multipart' => true,
        ]);
        return null;
    }

    return $decoded;
}

function baixar_arquivo_temporario(string $url, string $prefix = 'tg_'): ?array
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), $prefix);
    if ($tmpPath === false) {
        return null;
    }

    $handle = fopen($tmpPath, 'wb');
    if ($handle === false) {
        @unlink($tmpPath);
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 Codex Telegram Media Relay',
    ]);

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    fclose($handle);

    if ($ok === false || $error !== '' || $httpCode >= 400 || filesize($tmpPath) === 0) {
        log_evento('download_midia_falhou', 'Nao foi possivel baixar a midia remota para reenviar ao Telegram.', [
            'url' => $url,
            'http_code' => $httpCode,
            'erro' => $error,
        ]);
        @unlink($tmpPath);
        return null;
    }

    $filename = telegram_media_nome_arquivo($effectiveUrl !== '' ? $effectiveUrl : $url, 'arquivo');
    return [
        'path' => $tmpPath,
        'filename' => $filename,
        'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
    ];
}

function telegram_media_upload_filename(string $mediaTipo, string $filename): string
{
    $filename = trim($filename);
    if ($mediaTipo === 'voice') {
        return 'audio.ogg';
    }

    return $filename !== '' ? $filename : 'arquivo';
}

function enviar_midia_telegram(int $chatId, string $mediaTipo, string $mediaUrl, string $caption = '', array $extra = []): ?array
{
    $mediaTipo = telegram_media_tipo_resolvido($mediaTipo, $mediaUrl);
    $mediaUrl = trim($mediaUrl);

    if ($mediaTipo === 'none' || $mediaUrl === '') {
        return enviar_mensagem($chatId, $caption, $extra);
    }

    [$method, $mediaField] = telegram_media_metodo_campo($mediaTipo);

    $payload = array_merge([
        'chat_id' => $chatId,
        $mediaField => $mediaUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ], $extra);

    $response = telegram_request($method, $payload);
    if (is_array($response) && !empty($response['ok'])) {
        return $response;
    }

    $download = baixar_arquivo_temporario($mediaUrl, 'tgm_');
    if (!$download) {
        return $response;
    }

    try {
        $payload[$mediaField] = new CURLFile(
            $download['path'],
            (string) $download['content_type'],
            telegram_media_upload_filename($mediaTipo, (string) $download['filename'])
        );

        return telegram_request_multipart($method, $payload);
    } finally {
        @unlink($download['path']);
    }
}

function enviar_conteudo_telegram(int $chatId, string $texto, ?string $mediaTipo = 'none', ?string $mediaUrl = '', array $extra = []): ?array
{
    $mediaTipo = normalizar_media_tipo($mediaTipo);
    $mediaUrl = trim((string) $mediaUrl);

    if ($mediaTipo === 'none' || $mediaUrl === '') {
        return enviar_mensagem($chatId, $texto, $extra);
    }

    return enviar_midia_telegram($chatId, $mediaTipo, $mediaUrl, $texto, $extra);
}

function enviar_qrcode_imagem(int $chatId, string $base64): bool
{
    $base64 = trim($base64);
    if (preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $base64) === 1) {
        $base64 = preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', $base64) ?? $base64;
    }

    $imageData = base64_decode($base64, true);
    if ($imageData === false) {
        return false;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'qr_');
    if ($tmp === false) {
        return false;
    }

    file_put_contents($tmp, $imageData);

    $payload = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($tmp, 'image/png', 'qrcode.png'),
    ];

    $ch = curl_init(runtime_telegram_api() . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);

    if ($error) {
        log_evento('telegram_send_photo_error', $error);
        return false;
    }

    $decoded = json_decode((string) $response, true);
    return isset($decoded['ok']) && $decoded['ok'] === true;
}

function gerar_link_convite(): ?string
{
    $result = telegram_request('createChatInviteLink', [
        'chat_id' => runtime_telegram_group_id(),
        'member_limit' => 1,
        'expire_date' => time() + 3600,
    ]);

    return $result['result']['invite_link'] ?? null;
}

function remover_do_grupo(int $telegramId): bool
{
    $ban = telegram_request('banChatMember', [
        'chat_id' => runtime_telegram_group_id(),
        'user_id' => $telegramId,
        'revoke_messages' => false,
    ]);

    if (!isset($ban['ok']) || $ban['ok'] !== true) {
        log_evento('telegram_remove_fail', 'Falha ao banir usuário', ['telegram_id' => $telegramId, 'response' => $ban]);
        return false;
    }

    telegram_request('unbanChatMember', [
        'chat_id' => runtime_telegram_group_id(),
        'user_id' => $telegramId,
        'only_if_banned' => true,
    ]);

    return true;
}

function extrair_start_payload(string $texto): string
{
    $texto = trim($texto);
    if (!preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/i', $texto, $matches)) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

function upsert_usuario(int $telegramId, string $firstName = '', string $username = '', array $extras = []): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE telegram_id = ? AND ' . tenant_scope_condition('usuarios'));
    $stmt->execute([$telegramId]);
    $usuario = $stmt->fetch();

    $payload = [
        'telegram_id' => $telegramId,
        'first_name' => $firstName,
        'username' => $username !== '' ? $username : null,
        'last_name' => trim((string) ($extras['last_name'] ?? '')) ?: null,
        'language_code' => trim((string) ($extras['language_code'] ?? '')) ?: null,
        'chat_type' => trim((string) ($extras['chat_type'] ?? 'private')) ?: 'private',
        'chat_username' => trim((string) ($extras['chat_username'] ?? '')) ?: null,
        'is_premium' => !empty($extras['is_premium']) ? 1 : 0,
        'start_payload' => trim((string) ($extras['start_payload'] ?? '')) ?: null,
        'last_seen_at' => db_now(),
        'ultimo_start_em' => !empty($extras['is_start']) ? db_now() : null,
        'telegram_meta' => json_encode([
            'from' => $extras['from'] ?? [],
            'chat' => $extras['chat'] ?? [],
            'source' => $extras['source'] ?? '',
            'message_id' => $extras['message_id'] ?? null,
            'text' => $extras['text'] ?? null,
            'callback_data' => $extras['callback_data'] ?? null,
            'message' => $extras['message'] ?? null,
            'callback_query' => $extras['callback_query'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    if ($usuario) {
        $updates = [
            'first_name = ?',
            'username = ?',
            'last_seen_at = ?',
        ];
        $params = [
            $firstName !== '' ? $firstName : ($usuario['first_name'] ?? null),
            $username !== '' ? $username : ($usuario['username'] ?? null),
            $payload['last_seen_at'],
        ];

        $optionalColumns = [
            'last_name' => 'last_name',
            'language_code' => 'language_code',
            'chat_type' => 'chat_type',
            'chat_username' => 'chat_username',
            'is_premium' => 'is_premium',
            'start_payload' => 'start_payload',
            'telegram_meta' => 'telegram_meta',
        ];

        foreach ($optionalColumns as $column => $payloadKey) {
            if (db_has_column('usuarios', $column)) {
                $updates[] = $column . ' = ?';
                $params[] = $payload[$payloadKey] ?? null;
            }
        }

        if (!empty($extras['is_start']) && db_has_column('usuarios', 'ultimo_start_em')) {
            $updates[] = 'ultimo_start_em = ?';
            $params[] = $payload['ultimo_start_em'];
        }

        $params[] = $telegramId;
        $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $updates) . ' WHERE telegram_id = ? AND ' . tenant_scope_condition('usuarios'))
            ->execute($params);

        $stmt->execute([$telegramId]);
        return $stmt->fetch();
    }

    $columns = ['telegram_id', 'first_name', 'username', 'status', 'grupo_adicionado', 'estado_bot'];
    $values = ['?', '?', '?', '?', '?', '?'];
    $params = [$telegramId, $firstName, $username !== '' ? $username : null, 'pendente', 0, ''];

    $insertOptionalColumns = [
        'last_name' => $payload['last_name'],
        'language_code' => $payload['language_code'],
        'chat_type' => $payload['chat_type'],
        'chat_username' => $payload['chat_username'],
        'is_premium' => $payload['is_premium'],
        'start_payload' => $payload['start_payload'],
        'last_seen_at' => $payload['last_seen_at'],
        'ultimo_start_em' => $payload['ultimo_start_em'],
        'telegram_meta' => $payload['telegram_meta'],
    ];

    foreach ($insertOptionalColumns as $column => $value) {
        if (db_has_column('usuarios', $column)) {
            $columns[] = $column;
            $values[] = '?';
            $params[] = $value;
        }
    }

    tenant_insert_append('usuarios', $columns, $values, $params);

    $pdo->prepare(
        'INSERT INTO usuarios (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    )->execute($params);

    $stmt->execute([$telegramId]);
    $created = $stmt->fetch();
    if ($created) {
        return $created;
    }

    return [
        'id' => (int) $pdo->lastInsertId(),
        'telegram_id' => $telegramId,
        'first_name' => $firstName,
        'username' => $username,
        'status' => 'pendente',
        'data_expiracao' => null,
        'grupo_adicionado' => 0,
        'cpf' => null,
        'estado_bot' => '',
    ];
}

function atualizar_estado_bot(int $usuarioId, string $estado): void
{
    db()->prepare('UPDATE usuarios SET estado_bot = ? WHERE id = ?')->execute([$estado, $usuarioId]);
}

function salvar_cpf_usuario(int $usuarioId, string $cpf): void
{
    db()->prepare("UPDATE usuarios SET cpf = ?, estado_bot = '' WHERE id = ?")->execute([$cpf, $usuarioId]);

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        disparar_fluxos('cpf_salvo', [
            'usuario' => $usuario,
            'cpf' => $cpf,
        ]);
    }
}

function ativar_usuario(int $usuarioId, int $dias): void
{
    $stmt = db()->prepare('SELECT data_expiracao FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $atual = $stmt->fetchColumn();

    $base = ($atual && strtotime((string) $atual) > time()) ? (string) $atual : date('Y-m-d H:i:s');
    $novaExpiracao = date('Y-m-d H:i:s', strtotime($base . " +{$dias} days"));

    db()->prepare(
        'UPDATE usuarios
         SET status = ?, data_expiracao = ?, grupo_adicionado = 1, estado_bot = ?
         WHERE id = ?'
    )->execute(['ativo', $novaExpiracao, '', $usuarioId]);
}

function expirar_usuario(int $usuarioId): void
{
    db()->prepare(
        'UPDATE usuarios SET status = ?, grupo_adicionado = 0, estado_bot = ? WHERE id = ?'
    )->execute(['expirado', '', $usuarioId]);
}

function get_produtos_ativos(): array
{
    try {
        $rows = db()->query(
            'SELECT * FROM produtos WHERE ativo = 1 AND ' . tenant_scope_condition('produtos') . ' ORDER BY ' . db_order_by_clause('produtos')
        )->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    if ($rows) {
        return $rows;
    }

    return [[
        'id' => 0,
        'nome' => runtime_default_product_name(),
        'descricao' => runtime_default_product_description(),
        'valor' => runtime_default_product_price(),
        'dias_acesso' => runtime_default_product_days(),
        'tipo' => 'grupo',
        'pack_link' => '',
        'ativo' => 1,
        'ordem' => 1,
    ]];
}

function get_produto_por_id(int $produtoId): ?array
{
    if ($produtoId === 0) {
        return get_produtos_ativos()[0] ?? null;
    }

    $stmt = db()->prepare('SELECT * FROM produtos WHERE id = ? AND ' . tenant_scope_condition('produtos') . ' LIMIT 1');
    $stmt->execute([$produtoId]);
    $produto = $stmt->fetch();
    return $produto ?: null;
}

function produto_tipo(array $produto): string
{
    $tipo = strtolower(trim((string) ($produto['tipo'] ?? 'grupo')));
    return $tipo === 'pack' ? 'pack' : 'grupo';
}

function produto_is_pack(array $produto): bool
{
    return produto_tipo($produto) === 'pack';
}

function produto_pack_link(array $produto): string
{
    return trim((string) ($produto['pack_link'] ?? ''));
}

function produto_rotulo_bot(array $produto): string
{
    $prefixo = produto_is_pack($produto) ? 'PACK | ' : '';
    return sprintf(
        '%s%s - %s',
        $prefixo,
        (string) ($produto['nome'] ?? 'Produto'),
        formatar_valor((float) ($produto['valor'] ?? 0))
    );
}

function desconto_percentual_normalizado($valor): float
{
    $desconto = (float) $valor;
    if ($desconto < 0) {
        return 0.0;
    }
    if ($desconto > 100) {
        return 100.0;
    }

    return round($desconto, 2);
}

function aplicar_desconto_percentual(float $valor, $descontoPercentual): float
{
    $desconto = desconto_percentual_normalizado($descontoPercentual);
    $valorFinal = $valor * ((100 - $desconto) / 100);
    return max(0.01, round($valorFinal, 2));
}

function produto_com_desconto(array $produto, $descontoPercentual): array
{
    $desconto = desconto_percentual_normalizado($descontoPercentual);
    $produto['valor_original'] = (float) ($produto['valor'] ?? 0);
    $produto['desconto_percentual'] = $desconto;
    $produto['valor'] = $desconto > 0
        ? aplicar_desconto_percentual((float) $produto['valor_original'], $desconto)
        : (float) $produto['valor_original'];

    return $produto;
}

function produto_desconto_percentual(array $produto): float
{
    return desconto_percentual_normalizado($produto['desconto_percentual'] ?? 0);
}

function get_produto_oferta_funil(array $funil, string $tipoOferta): ?array
{
    $produtoId = funil_produto_id_por_tipo($funil, $tipoOferta);
    if ($produtoId <= 0) {
        return null;
    }

    $produto = get_produto_por_id($produtoId);
    if (!$produto || !((int) ($produto['ativo'] ?? 1))) {
        return null;
    }

    if ($tipoOferta === 'upsell') {
        return produto_com_desconto($produto, funil_upsell_desconto($funil));
    }

    return $produto;
}

function get_produto_oferta_downsell(array $downsell): ?array
{
    $produtoId = (int) ($downsell['produto_id'] ?? 0);
    if ($produtoId <= 0) {
        return null;
    }

    $produto = get_produto_por_id($produtoId);
    if (!$produto || !((int) ($produto['ativo'] ?? 1))) {
        return null;
    }

    return produto_com_desconto($produto, downsell_desconto_percentual($downsell));
}

function get_packs_ativos(): array
{
    return array_values(array_filter(get_produtos_ativos(), 'produto_is_pack'));
}

function get_produtos_grupo_ativos(): array
{
    return array_values(array_filter(
        get_produtos_ativos(),
        static fn(array $produto): bool => !produto_is_pack($produto)
    ));
}

function funil_produto_id_por_tipo(array $funil, string $tipoOferta): int
{
    return match ($tipoOferta) {
        'upsell' => (int) ($funil['upsell_produto_id'] ?? 0),
        'downsell' => (int) ($funil['downsell_produto_id'] ?? 0),
        default => (int) ($funil['produto_principal_id'] ?? 0),
    };
}

function funil_tem_downsell(array $funil): bool
{
    return (int) ($funil['downsell_produto_id'] ?? 0) > 0;
}

function funil_upsell_desconto(array $funil): float
{
    return desconto_percentual_normalizado($funil['upsell_desconto_percentual'] ?? 0);
}

function funil_mensagem_por_tipo(array $funil, string $tipoOferta): string
{
    return match ($tipoOferta) {
        'upsell' => trim((string) ($funil['mensagem_upsell'] ?? '')),
        'downsell' => trim((string) ($funil['mensagem_downsell'] ?? '')),
        default => trim((string) ($funil['descricao'] ?? '')),
    };
}

function funil_media_tipo_por_tipo(array $funil, string $tipoOferta): string
{
    return normalizar_media_tipo(match ($tipoOferta) {
        'upsell' => (string) ($funil['upsell_media_tipo'] ?? 'none'),
        'downsell' => (string) ($funil['downsell_media_tipo'] ?? 'none'),
        default => 'none',
    });
}

function funil_media_url_por_tipo(array $funil, string $tipoOferta): string
{
    return trim((string) match ($tipoOferta) {
        'upsell' => ($funil['upsell_media_url'] ?? ''),
        'downsell' => ($funil['downsell_media_url'] ?? ''),
        default => '',
    });
}

function orderbump_desconto_percentual(array $orderbump): float
{
    return desconto_percentual_normalizado($orderbump['desconto_percentual'] ?? 0);
}

function get_orderbump_por_id(int $orderbumpId): ?array
{
    if ($orderbumpId <= 0 || !db_has_table('orderbumps')) {
        return null;
    }

    $hasPackLink = db_has_column('produtos', 'pack_link');
    $stmt = db()->prepare(
        'SELECT o.*,
                pm.nome AS produto_principal_nome, pm.valor AS produto_principal_valor,
                pb.nome AS produto_nome, pb.valor AS produto_valor, pb.dias_acesso AS produto_dias_acesso, ' .
                ($hasPackLink ? 'pb.pack_link' : 'NULL') . ' AS produto_pack_link
         FROM orderbumps o
         LEFT JOIN produtos pm ON pm.id = o.produto_principal_id AND ' . tenant_scope_condition('produtos', 'pm') . '
         LEFT JOIN produtos pb ON pb.id = o.produto_id AND ' . tenant_scope_condition('produtos', 'pb') . '
         WHERE o.id = ? AND ' . tenant_scope_condition('orderbumps', 'o') . '
         LIMIT 1'
    );
    $stmt->execute([$orderbumpId]);
    $orderbump = $stmt->fetch();
    return $orderbump ?: null;
}

function get_orderbump_ativo_por_produto(int $produtoId): ?array
{
    if ($produtoId <= 0 || !db_has_table('orderbumps')) {
        return null;
    }

    $hasPackLink = db_has_column('produtos', 'pack_link');
    $stmt = db()->prepare(
        'SELECT o.*,
                pm.nome AS produto_principal_nome, pm.valor AS produto_principal_valor,
                pb.nome AS produto_nome, pb.valor AS produto_valor, pb.dias_acesso AS produto_dias_acesso, ' .
                ($hasPackLink ? 'pb.pack_link' : 'NULL') . ' AS produto_pack_link
         FROM orderbumps o
         LEFT JOIN produtos pm ON pm.id = o.produto_principal_id AND ' . tenant_scope_condition('produtos', 'pm') . '
         LEFT JOIN produtos pb ON pb.id = o.produto_id AND ' . tenant_scope_condition('produtos', 'pb') . '
         WHERE o.ativo = 1 AND o.produto_principal_id = ? AND ' . tenant_scope_condition('orderbumps', 'o') . '
         ORDER BY ' . db_order_by_clause('orderbumps', 'o') . '
         LIMIT 1'
    );
    $stmt->execute([$produtoId]);
    $orderbump = $stmt->fetch();
    return $orderbump ?: null;
}

function get_produto_oferta_orderbump(array $orderbump): ?array
{
    $produtoId = (int) ($orderbump['produto_id'] ?? 0);
    if ($produtoId <= 0) {
        return null;
    }

    $produto = get_produto_por_id($produtoId);
    if (!$produto || !((int) ($produto['ativo'] ?? 1))) {
        return null;
    }

    return produto_com_desconto($produto, orderbump_desconto_percentual($orderbump));
}

function enviar_oferta_orderbump(int $chatId, array $orderbump, array $vars = []): bool
{
    $produto = get_produto_oferta_orderbump($orderbump);
    if (!$produto) {
        return false;
    }

    $produtoNome = (string) ($produto['nome'] ?? 'Oferta');
    $produtoValorOriginal = (float) ($produto['valor_original'] ?? $produto['valor'] ?? 0);
    $produtoValor = (float) ($produto['valor'] ?? 0);
    $desconto = orderbump_desconto_percentual($orderbump);
    $mensagemOferta = trim((string) ($orderbump['mensagem'] ?? ''));
    if ($mensagemOferta === '') {
        $mensagemOferta = "<b>Oferta extra liberada</b>\n\nAdicione tambem <b>{produto}</b> por <b>{valor}</b>.";
    }

    $mensagem = render_template($mensagemOferta, array_merge([
        'produto' => htmlspecialchars($produtoNome, ENT_QUOTES, 'UTF-8'),
        'valor' => formatar_valor($produtoValor),
        'valor_original' => formatar_valor($produtoValorOriginal),
        'desconto' => number_format($desconto, 0, ',', '.'),
    ], $vars));

    $extra = [
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => runtime_orderbump_accept_button_text(), 'callback_data' => 'orderbump:aceitar:' . (int) $orderbump['id']],
                ],
                [
                    ['text' => runtime_orderbump_skip_button_text(), 'callback_data' => 'orderbump:recusar:' . (int) $orderbump['id']],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $response = enviar_conteudo_telegram(
        $chatId,
        $mensagem,
        (string) ($orderbump['media_tipo'] ?? 'none'),
        (string) ($orderbump['media_url'] ?? ''),
        $extra
    );

    $ok = is_array($response) && !empty($response['ok']);
    if ($ok) {
        $payload = [
            'chat_id' => $chatId,
            'orderbump' => $orderbump,
            'produto' => $produto,
        ];
        disparar_webhook_externo(
            'orderbump_ofertado',
            (string) ($orderbump['webhook_url'] ?? ''),
            $payload,
            (string) ($orderbump['webhook_secret'] ?? ''),
            'orderbump'
        );
        disparar_remarketing_webhooks('orderbump_ofertado', $payload);
    }

    return $ok;
}

function enviar_entrega_orderbump_pos_pagamento(array $usuario, array $pagamento): bool
{
    $orderbumpId = (int) ($pagamento['orderbump_id'] ?? 0);
    if ($orderbumpId <= 0) {
        return false;
    }

    $orderbump = get_orderbump_por_id($orderbumpId);
    if (!$orderbump) {
        log_evento('orderbump_nao_encontrado', 'Order bump aprovado nao encontrado no banco.', [
            'orderbump_id' => $orderbumpId,
            'txid' => $pagamento['txid'] ?? null,
        ]);
        return false;
    }

    $produto = get_produto_oferta_orderbump($orderbump);
    if (!$produto) {
        log_evento('orderbump_sem_produto', 'Order bump aprovado sem produto valido.', [
            'orderbump_id' => $orderbumpId,
            'txid' => $pagamento['txid'] ?? null,
        ]);
        return false;
    }

    $chatId = (int) ($usuario['telegram_id'] ?? 0);
    if ($chatId <= 0) {
        return false;
    }

    $nome = htmlspecialchars((string) ($usuario['first_name'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8');
    $produtoNome = htmlspecialchars((string) ($produto['nome'] ?? 'Oferta'), ENT_QUOTES, 'UTF-8');

    if (produto_is_pack($produto)) {
        $packLink = produto_pack_link($produto);
        if ($packLink === '') {
            return enviar_mensagem($chatId, render_template(message_template('msg_orderbump_missing_link'), [
                'nome' => $nome,
                'produto' => $produtoNome,
            ])) !== null;
        }

        return enviar_mensagem($chatId, render_template(message_template('msg_orderbump_delivered'), [
            'nome' => $nome,
            'produto' => $produtoNome,
            'conteudo' => "Aqui esta o link do seu pack:\n" . htmlspecialchars($packLink, ENT_QUOTES, 'UTF-8'),
        ])) !== null;
    }

    $dias = (int) ($produto['dias_acesso'] ?? runtime_default_product_days());
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

    if (!$convite) {
        log_evento('orderbump_convite_falhou', 'Nao foi possivel gerar o convite do order bump.', [
            'usuario_id' => (int) $usuario['id'],
            'orderbump_id' => $orderbumpId,
        ]);
    }

    $conteudo = $convite
        ? "Acesso liberado ate <b>{$expira}</b>.\n\nEntre no grupo pelo link abaixo:\n{$convite}\n\nEsse link e individual e expira em 1 hora."
        : "Acesso liberado ate <b>{$expira}</b>, mas nao consegui gerar o convite automaticamente.";

    return enviar_mensagem($chatId, render_template(message_template('msg_orderbump_delivered'), [
        'nome' => $nome,
        'produto' => $produtoNome,
        'conteudo' => $conteudo,
        'expira' => $expira,
        'convite' => $convite ?: '',
    ])) !== null;
}

function get_downsells_ativos(): array
{
    if (!db_has_table('downsells')) {
        return [];
    }

    try {
        $hasTipoProduto = db_has_column('produtos', 'tipo');
        $hasPackLink = db_has_column('produtos', 'pack_link');
        return db()->query(
            'SELECT d.*, f.nome AS funil_nome,
                    p.nome AS produto_nome, p.valor AS produto_valor, ' .
                    ($hasTipoProduto ? 'p.tipo' : "'grupo'") . ' AS produto_tipo, ' .
                    ($hasPackLink ? 'p.pack_link' : 'NULL') . ' AS produto_pack_link, p.dias_acesso AS produto_dias_acesso
             FROM downsells d
             LEFT JOIN funis f ON f.id = d.funil_id AND ' . tenant_scope_condition('funis', 'f') . '
             LEFT JOIN produtos p ON p.id = d.produto_id AND ' . tenant_scope_condition('produtos', 'p') . '
             WHERE d.ativo = 1
               AND ' . tenant_scope_condition('downsells', 'd') . '
             ORDER BY d.id ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function get_downsell_por_id(int $downsellId): ?array
{
    if (!db_has_table('downsells')) {
        return null;
    }

    $hasTipoProduto = db_has_column('produtos', 'tipo');
    $hasPackLink = db_has_column('produtos', 'pack_link');
    $stmt = db()->prepare(
        'SELECT d.*, f.nome AS funil_nome,
                p.nome AS produto_nome, p.valor AS produto_valor, ' .
                ($hasTipoProduto ? 'p.tipo' : "'grupo'") . ' AS produto_tipo, ' .
                ($hasPackLink ? 'p.pack_link' : 'NULL') . ' AS produto_pack_link, p.dias_acesso AS produto_dias_acesso
         FROM downsells d
         LEFT JOIN funis f ON f.id = d.funil_id AND ' . tenant_scope_condition('funis', 'f') . '
         LEFT JOIN produtos p ON p.id = d.produto_id AND ' . tenant_scope_condition('produtos', 'p') . '
         WHERE d.id = ? AND ' . tenant_scope_condition('downsells', 'd') . '
         LIMIT 1'
    );
    $stmt->execute([$downsellId]);
    $downsell = $stmt->fetch();
    return $downsell ?: null;
}

function downsell_desconto_percentual(array $downsell): float
{
    return desconto_percentual_normalizado($downsell['desconto_percentual'] ?? 0);
}

function enviar_oferta_downsell(int $chatId, array $downsell, array $vars = []): bool
{
    $produtoId = (int) ($downsell['produto_id'] ?? 0);
    if ($produtoId <= 0) {
        return false;
    }

    $produtoNome = (string) ($downsell['produto_nome'] ?? 'Oferta');
    $valorOriginal = (float) ($downsell['produto_valor'] ?? 0);
    $desconto = downsell_desconto_percentual($downsell);
    $valorFinal = $desconto > 0 ? aplicar_desconto_percentual($valorOriginal, $desconto) : $valorOriginal;
    $mensagemOferta = trim((string) ($downsell['mensagem'] ?? ''));
    if ($mensagemOferta === '') {
        $mensagemOferta = 'Temos uma opcao especial: <b>{produto}</b> por <b>{valor}</b>.';
    }

    $mensagem = render_template(message_template('msg_downsell_offer'), [
        'mensagem' => render_template($mensagemOferta, array_merge([
            'produto' => htmlspecialchars($produtoNome, ENT_QUOTES, 'UTF-8'),
            'valor' => formatar_valor($valorFinal),
            'valor_original' => formatar_valor($valorOriginal),
            'desconto' => number_format($desconto, 0, ',', '.'),
        ], $vars)),
    ]);

    $extra = [
        'reply_markup' => json_encode([
            'inline_keyboard' => [[
                ['text' => runtime_downsell_button_text(), 'callback_data' => 'downsell:' . (int) $downsell['id']],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $response = enviar_conteudo_telegram(
        $chatId,
        $mensagem,
        (string) ($downsell['media_tipo'] ?? 'none'),
        (string) ($downsell['media_url'] ?? ''),
        $extra
    );

    $ok = is_array($response) && !empty($response['ok']);
    if ($ok) {
        $payload = [
            'chat_id' => $chatId,
            'downsell' => $downsell,
            'produto' => [
                'id' => $produtoId,
                'nome' => $produtoNome,
                'valor_original' => $valorOriginal,
                'valor' => $valorFinal,
                'desconto' => $desconto,
            ],
        ];
        disparar_webhook_externo(
            'downsell_ofertado',
            (string) ($downsell['webhook_url'] ?? ''),
            $payload,
            (string) ($downsell['webhook_secret'] ?? ''),
            'downsell'
        );
        disparar_remarketing_webhooks('downsell_ofertado', $payload);
    }

    return $ok;
}

function formatar_valor(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function gerar_txid(): string
{
    return substr(preg_replace('/[^a-zA-Z0-9]/', '', bin2hex(random_bytes(24))), 0, 35);
}

function criar_pagamento(
    int $usuarioId,
    ?int $produtoId,
    string $txid,
    float $valor,
    string $qrCode,
    string $qrImg,
    ?int $funilId = null,
    string $tipoOferta = 'principal',
    array $extras = []
): int {
    $columns = ['usuario_id'];
    $placeholders = ['?'];
    $params = [$usuarioId];

    if (db_has_column('pagamentos', 'produto_id')) {
        $columns[] = 'produto_id';
        $placeholders[] = '?';
        $params[] = $produtoId;
    }

    if (db_has_column('pagamentos', 'funil_id')) {
        $columns[] = 'funil_id';
        $placeholders[] = '?';
        $params[] = $funilId;
    }

    if (db_has_column('pagamentos', 'tipo_oferta')) {
        $columns[] = 'tipo_oferta';
        $placeholders[] = '?';
        $params[] = $tipoOferta;
    }

    if (db_has_column('pagamentos', 'orderbump_id') && array_key_exists('orderbump_id', $extras)) {
        $columns[] = 'orderbump_id';
        $placeholders[] = '?';
        $params[] = (int) $extras['orderbump_id'];
    }

    $columns[] = 'txid';
    $placeholders[] = '?';
    $params[] = $txid;

    $columns[] = 'valor';
    $placeholders[] = '?';
    $params[] = $valor;

    $columns[] = 'status';
    $placeholders[] = '?';
    $params[] = 'pendente';

    $columns[] = 'qr_code';
    $placeholders[] = '?';
    $params[] = $qrCode;

    $columns[] = 'qr_code_img';
    $placeholders[] = '?';
    $params[] = $qrImg;

    tenant_insert_append('pagamentos', $columns, $placeholders, $params);

    db()->prepare(
        'INSERT INTO pagamentos (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    )->execute($params);

    return (int) db()->lastInsertId();
}

function confirmar_pagamento(string $txid): ?array
{
    $stmt = db()->prepare('SELECT * FROM pagamentos WHERE txid = ? AND ' . tenant_scope_condition('pagamentos') . ' LIMIT 1');
    $stmt->execute([$txid]);
    $pagamento = $stmt->fetch();

    if (!$pagamento) {
        return null;
    }

    if ($pagamento['status'] !== 'pendente') {
        $pagamento['already_processed'] = true;
        return $pagamento;
    }

    if ($pagamento['status'] === 'pendente') {
        db()->prepare("UPDATE pagamentos SET status = 'pago', paid_at = ? WHERE txid = ? AND " . tenant_scope_condition('pagamentos'))
            ->execute([db_now(), $txid]);

        $stmt->execute([$txid]);
        $pagamento = $stmt->fetch();
    }

    $pagamento['already_processed'] = false;
    return $pagamento;
}

function ecompag_request(string $method, string $path, array $data = []): array
{
    $method = strtoupper(trim($method));
    $url = PESTOPAY_API_BASE . '/' . ltrim($path, '/');
    if ($method === 'GET' && $data) {
        $url .= '?' . http_build_query($data);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $headers = [
        'Accept: application/json',
        'X-Public-Key: ' . runtime_pestopay_public_key(),
        'X-Secret-Key: ' . runtime_pestopay_secret_key(),
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_evento('pestopay_curl_error', $error, ['path' => $path, 'url' => $url]);
        return ['ok' => false, 'http_code' => 0, 'data' => null];
    }

    $decoded = json_decode((string) $response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_evento('pestopay_resposta_invalida', 'Resposta da PestoPay nao era JSON valido', [
            'path' => $path,
            'http_code' => $httpCode,
            'response' => substr((string) $response, 0, 2000),
            'json_error' => json_last_error_msg(),
        ]);
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        log_evento('pestopay_http_error', 'Falha na API da PestoPay', [
            'path' => $path,
            'http_code' => $httpCode,
            'request' => $data,
            'response' => substr((string) $response, 0, 2000),
        ]);
    }

    return [
        'ok' => $httpCode === 200 || $httpCode === 201,
        'http_code' => $httpCode,
        'data' => $decoded,
    ];
}

function pestopay_gateway_email(array $usuario): string
{
    $email = trim((string) ($usuario['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return strtolower($email);
    }

    $host = (string) parse_url(runtime_site_url(), PHP_URL_HOST);
    $host = preg_replace('/^www\./i', '', trim($host));
    if ($host === '' || strpos($host, '.') === false) {
        $host = 'example.com';
    }

    $identifier = (int) ($usuario['telegram_id'] ?? $usuario['id'] ?? 0);
    if ($identifier <= 0) {
        $identifier = random_int(1000, 999999);
    }

    return 'telegram+' . $identifier . '@' . $host;
}

function pestopay_extract_pix_response(array $data): array
{
    $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];
    $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
    $pixInformation = is_array($transaction['pixInformation'] ?? null) ? $transaction['pixInformation'] : [];

    $transactionId = (string) (
        $data['transactionId']
        ?? $transaction['id']
        ?? $pix['transactionId']
        ?? $pixInformation['transactionId']
        ?? ''
    );

    $qrCode = (string) (
        $pix['code']
        ?? $pix['qrCode']
        ?? $pix['copyAndPaste']
        ?? $pixInformation['code']
        ?? $pixInformation['qrCode']
        ?? $data['qrcode']
        ?? $data['qrCode']
        ?? $data['pixCode']
        ?? ''
    );

    $qrImg = (string) (
        $pix['qrCodeImage']
        ?? $pix['base64']
        ?? $pix['image']
        ?? $pixInformation['qrCodeImage']
        ?? $pixInformation['base64']
        ?? $data['imagemQrcode']
        ?? $data['base64']
        ?? $data['qrCodeImage']
        ?? ''
    );

    return [
        'transaction_id' => trim($transactionId),
        'qr_code' => trim($qrCode),
        'qr_img' => trim($qrImg),
        'token' => trim((string) ($data['token'] ?? '')),
    ];
}

function gerar_pix_para_usuario(array $usuario, array $produto, ?int $funilId = null, string $tipoOferta = 'principal', array $extras = []): ?array
{
    $cpf = runtime_effective_checkout_cpf($usuario);
    if (preg_match('/^\d{11}$/', $cpf) !== 1) {
        log_evento('pix_sem_cpf', 'Tentativa de gerar PIX sem CPF de checkout configurado', ['usuario_id' => $usuario['id']]);
        return null;
    }

    $telefonePagador = runtime_pestopay_checkout_phone($usuario);
    if ($telefonePagador === '') {
        log_evento('pix_sem_telefone', 'Tentativa de gerar PIX sem telefone valido para a PestoPay.', [
            'usuario_id' => (int) ($usuario['id'] ?? 0),
        ]);
        return null;
    }

    $nomePagador = texto_ascii_seguro(runtime_effective_checkout_name($usuario), 60);
    if ($nomePagador === '') {
        $nomePagador = 'Cliente Telegram';
    }

    $produtoNome = texto_ascii_seguro((string) ($produto['nome'] ?? 'Produto'), 120);
    if ($produtoNome === '') {
        $produtoNome = 'Produto';
    }

    $valor = round((float) ($produto['valor'] ?? 0), 2);
    $identifier = sprintf(
        'tg-%s-u%s-%s',
        current_tenant_slug() !== '' ? current_tenant_slug() : 'default',
        (int) ($usuario['id'] ?? 0),
        substr(bin2hex(random_bytes(6)), 0, 12)
    );

    $response = ecompag_request('POST', '/gateway/pix/receive', [
        'identifier' => $identifier,
        'amount' => $valor,
        'callbackUrl' => runtime_ecompag_notify_url(),
        'client' => [
            'name' => $nomePagador,
            'email' => pestopay_gateway_email($usuario),
            'phone' => $telefonePagador,
            'document' => $cpf,
        ],
        'products' => [[
            'id' => (string) (isset($produto['id']) ? (int) $produto['id'] : 'default'),
            'name' => $produtoNome,
            'quantity' => 1,
            'price' => $valor,
            'externalId' => (string) (isset($produto['id']) ? (int) $produto['id'] : 'default'),
        ]],
        'metadata' => [
            'tenant' => current_tenant_slug() !== '' ? current_tenant_slug() : 'default',
            'usuarioId' => (string) (int) ($usuario['id'] ?? 0),
            'telegramId' => (string) (int) ($usuario['telegram_id'] ?? 0),
            'produtoId' => (string) (isset($produto['id']) ? (int) $produto['id'] : 0),
            'tipoOferta' => $tipoOferta,
        ],
    ]);

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $pixPayload = pestopay_extract_pix_response($data);
    if (!$response['ok'] || $pixPayload['qr_code'] === '' || $pixPayload['transaction_id'] === '') {
        log_evento('pix_geracao_falhou', 'Não foi possível gerar QR Code', [
            'response' => $data,
            'usuario_id' => (int) ($usuario['id'] ?? 0),
            'produto_id' => isset($produto['id']) ? (int) $produto['id'] : null,
            'produto_nome' => (string) ($produto['nome'] ?? ''),
            'valor' => $valor,
            'tipo_oferta' => $tipoOferta,
            'extras' => $extras,
        ]);
        return null;
    }

    criar_pagamento(
        (int) $usuario['id'],
        isset($produto['id']) ? (int) $produto['id'] : null,
        $pixPayload['transaction_id'],
        $valor,
        $pixPayload['qr_code'],
        $pixPayload['qr_img'],
        $funilId,
        $tipoOferta,
        $extras
    );

    if ($pixPayload['token'] !== '' && !hash_equals(runtime_pestopay_webhook_token(), $pixPayload['token'])) {
        app_setting_save('pestopay_webhook_token', $pixPayload['token']);
    }

    meta_send_event('InitiateCheckout', $usuario, [
        'currency' => 'BRL',
        'value' => $valor,
        'content_name' => (string) ($produto['nome'] ?? runtime_default_product_name()),
        'content_type' => 'product',
        'content_ids' => [isset($produto['id']) ? (string) (int) $produto['id'] : 'default'],
        'contents' => [[
            'id' => isset($produto['id']) ? (string) (int) $produto['id'] : 'default',
            'quantity' => 1,
            'item_price' => $valor,
        ]],
        'order_id' => $pixPayload['transaction_id'],
    ], [
        'event_id' => 'initiate_checkout_' . $pixPayload['transaction_id'],
    ]);

    disparar_fluxos('pix_gerado', [
        'usuario' => $usuario,
        'produto' => $produto,
        'pix' => [
            'txid' => $pixPayload['transaction_id'],
            'qr_code' => $pixPayload['qr_code'],
        ],
        'pagamento' => [
            'txid' => $pixPayload['transaction_id'],
            'valor' => $valor,
            'funil_id' => $funilId,
            'tipo_oferta' => $tipoOferta,
            'orderbump_id' => isset($extras['orderbump_id']) ? (int) $extras['orderbump_id'] : null,
        ],
    ]);

    disparar_n8n_evento('pix_gerado', [
        'usuario' => $usuario,
        'produto' => $produto,
        'pagamento' => [
            'txid' => $pixPayload['transaction_id'],
            'valor' => $valor,
            'funil_id' => $funilId,
            'tipo_oferta' => $tipoOferta,
            'orderbump_id' => isset($extras['orderbump_id']) ? (int) $extras['orderbump_id'] : null,
        ],
        'pix' => [
            'qr_code' => $pixPayload['qr_code'],
            'qr_img' => $pixPayload['qr_img'],
        ],
    ]);
    disparar_remarketing_webhooks('pix_gerado', [
        'usuario' => $usuario,
        'produto' => $produto,
        'pagamento' => [
            'txid' => $pixPayload['transaction_id'],
            'valor' => $valor,
            'funil_id' => $funilId,
            'tipo_oferta' => $tipoOferta,
            'orderbump_id' => isset($extras['orderbump_id']) ? (int) $extras['orderbump_id'] : null,
        ],
        'pix' => [
            'qr_code' => $pixPayload['qr_code'],
            'qr_img' => $pixPayload['qr_img'],
        ],
    ]);

    return [
        'txid' => $pixPayload['transaction_id'],
        'qr_code' => $pixPayload['qr_code'],
        'qr_img' => $pixPayload['qr_img'],
        'valor' => $valor,
        'produto' => $produto,
    ];
}

function consultar_status_pix(string $transactionId): ?array
{
    $transactionId = trim($transactionId);
    if ($transactionId === '') {
        return null;
    }

    $response = ecompag_request('GET', '/gateway/transactions/' . rawurlencode($transactionId));
    return $response['ok'] ? ($response['data'] ?? null) : null;
}

function montar_teclado_planos(array $produtos, bool $mostrarBotaoPacks = false): string
{
    $keyboard = [];
    foreach ($produtos as $produto) {
        $keyboard[] = [[
            'text' => produto_rotulo_bot($produto),
            'callback_data' => 'comprar:' . (int) $produto['id'],
        ]];
    }

    if ($mostrarBotaoPacks) {
        $keyboard[] = [[
            'text' => runtime_start_pack_button_text(),
            'callback_data' => 'menu_packs',
        ]];
    }

    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function montar_teclado_catalogo(array $funis, array $produtos, bool $mostrarBotaoPacks = false): string
{
    $keyboard = [];

    foreach ($funis as $funil) {
        $texto = trim((string) ($funil['headline'] ?? ''));
        if ($texto === '') {
            $texto = (string) ($funil['nome'] ?? 'Oferta');
        }
        if (!empty($funil['produto_principal_valor'])) {
            $texto .= ' - ' . formatar_valor((float) $funil['produto_principal_valor']);
        }

        $keyboard[] = [[
            'text' => $texto,
            'callback_data' => 'funil:' . (int) $funil['id'],
        ]];
    }

    foreach ($produtos as $produto) {
        $keyboard[] = [[
            'text' => produto_rotulo_bot($produto),
            'callback_data' => 'comprar:' . (int) $produto['id'],
        ]];
    }

    if ($mostrarBotaoPacks) {
        $keyboard[] = [[
            'text' => runtime_start_pack_button_text(),
            'callback_data' => 'menu_packs',
        ]];
    }

    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function montar_teclado_packs(array $packs, bool $mostrarVoltar = true): string
{
    $keyboard = [];

    foreach ($packs as $produto) {
        $keyboard[] = [[
            'text' => produto_rotulo_bot($produto),
            'callback_data' => 'comprar:' . (int) $produto['id'],
        ]];
    }

    if ($mostrarVoltar) {
        $keyboard[] = [[
            'text' => runtime_pack_back_button_text(),
            'callback_data' => 'menu_catalogo',
        ]];
    }

    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function get_funis_ativos(): array
{
    if (!db_has_table('funis')) {
        return [];
    }

    try {
        return db()->query(
            'SELECT f.*,
                    p.nome AS produto_principal_nome, p.valor AS produto_principal_valor,
                    u.nome AS upsell_produto_nome, u.valor AS upsell_produto_valor
             FROM funis f
             LEFT JOIN produtos p ON p.id = f.produto_principal_id AND ' . tenant_scope_condition('produtos', 'p') . '
             LEFT JOIN produtos u ON u.id = f.upsell_produto_id AND ' . tenant_scope_condition('produtos', 'u') . '
             WHERE f.ativo = 1
               AND ' . tenant_scope_condition('funis', 'f') . '
             ORDER BY ' . db_order_by_clause('funis', 'f')
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function get_funil_por_id(int $funilId): ?array
{
    if (!db_has_table('funis')) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT f.*, 
                p.nome AS produto_principal_nome, p.valor AS produto_principal_valor, p.dias_acesso AS produto_principal_dias,
                u.nome AS upsell_produto_nome, u.valor AS upsell_produto_valor, u.dias_acesso AS upsell_produto_dias
         FROM funis f
         LEFT JOIN produtos p ON p.id = f.produto_principal_id AND ' . tenant_scope_condition('produtos', 'p') . '
         LEFT JOIN produtos u ON u.id = f.upsell_produto_id AND ' . tenant_scope_condition('produtos', 'u') . '
         WHERE f.id = ? AND ' . tenant_scope_condition('funis', 'f') . '
         LIMIT 1'
    );
    $stmt->execute([$funilId]);
    $funil = $stmt->fetch();
    return $funil ?: null;
}

function enviar_oferta_funil(int $chatId, array $funil, string $tipoOferta, array $vars = []): bool
{
    $tipoOferta = in_array($tipoOferta, ['upsell', 'downsell'], true) ? $tipoOferta : 'upsell';
    $produto = get_produto_oferta_funil($funil, $tipoOferta);
    if (!$produto) {
        return false;
    }

    $produtoNome = (string) ($produto['nome'] ?? 'Oferta');
    $produtoValorOriginal = (float) ($produto['valor_original'] ?? $produto['valor'] ?? 0);
    $produtoValor = (float) ($produto['valor'] ?? 0);
    $desconto = produto_desconto_percentual($produto);

    $mensagemOferta = funil_mensagem_por_tipo($funil, $tipoOferta);
    if ($mensagemOferta === '') {
        $mensagemOferta = 'Adicione tambem <b>{produto}</b> por <b>{valor}</b>.';
    }

    $mensagem = render_template(message_template($tipoOferta === 'downsell' ? 'msg_downsell_offer' : 'msg_upsell_offer'), [
        'mensagem' => render_template($mensagemOferta, array_merge([
            'produto' => htmlspecialchars($produtoNome, ENT_QUOTES, 'UTF-8'),
            'valor' => formatar_valor($produtoValor),
            'valor_original' => formatar_valor($produtoValorOriginal),
            'desconto' => number_format($desconto, 0, ',', '.'),
        ], $vars)),
    ]);

    $botoes = [[
        [
            'text' => $tipoOferta === 'downsell' ? runtime_downsell_button_text() : runtime_upsell_button_text(),
            'callback_data' => ($tipoOferta === 'downsell' ? 'downsell:' : 'upsell:') . (int) $funil['id'],
        ],
    ]];

    $extra = [
        'reply_markup' => json_encode(['inline_keyboard' => $botoes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $response = enviar_conteudo_telegram(
        $chatId,
        $mensagem,
        funil_media_tipo_por_tipo($funil, $tipoOferta),
        funil_media_url_por_tipo($funil, $tipoOferta),
        $extra
    );

    $ok = is_array($response) && !empty($response['ok']);
    if ($ok) {
        $webhookUrl = $tipoOferta === 'downsell'
            ? (string) ($funil['downsell_webhook_url'] ?? '')
            : (string) ($funil['upsell_webhook_url'] ?? '');
        $webhookSecret = $tipoOferta === 'downsell'
            ? (string) ($funil['downsell_webhook_secret'] ?? '')
            : (string) ($funil['upsell_webhook_secret'] ?? '');
        $event = $tipoOferta === 'downsell' ? 'downsell_ofertado' : 'upsell_ofertado';
        $payload = [
            'chat_id' => $chatId,
            'funil' => $funil,
            'tipo_oferta' => $tipoOferta,
            'produto' => $produto,
        ];
        disparar_webhook_externo($event, $webhookUrl, $payload, $webhookSecret, 'funil');
        disparar_remarketing_webhooks($event, $payload);
    }

    return $ok;
}

function mailing_filters(): array
{
    return [
        'todos' => 'Todos os usuarios',
        'ativo' => 'Somente ativos',
        'pendente' => 'Somente pendentes',
        'expirado' => 'Somente expirados',
    ];
}

function mailing_count_targets(string $filtroStatus): int
{
    if (!db_has_table('usuarios')) {
        return 0;
    }

    $where = '';
    $params = [];

    if ($filtroStatus !== 'todos') {
        $where = 'WHERE status = ?';
        $params[] = $filtroStatus;
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM usuarios {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function criar_mailing(array $data, int $adminId): int
{
    $pdo = db();
    $filtroStatus = array_key_exists($data['filtro_status'] ?? '', mailing_filters())
        ? (string) $data['filtro_status']
        : 'todos';
    $totalAlvo = mailing_count_targets($filtroStatus);

    $pdo->beginTransaction();

    try {
        $createdAt = db_now();
        $pdo->prepare(
            'INSERT INTO mailings
             (nome, filtro_status, mensagem, media_tipo, media_url, botao_texto, botao_url, status, total_alvo, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            trim((string) ($data['nome'] ?? 'Mailing')),
            $filtroStatus,
            trim((string) ($data['mensagem'] ?? '')),
            normalizar_media_tipo((string) ($data['media_tipo'] ?? 'none')),
            trim((string) ($data['media_url'] ?? '')),
            trim((string) ($data['botao_texto'] ?? '')),
            trim((string) ($data['botao_url'] ?? '')),
            'pendente',
            $totalAlvo,
            $adminId,
            $createdAt,
        ]);

        $mailingId = (int) $pdo->lastInsertId();

        $where = '';
        $params = [];
        if ($filtroStatus !== 'todos') {
            $where = 'WHERE status = ?';
            $params[] = $filtroStatus;
        }

        $stmt = $pdo->prepare("SELECT id FROM usuarios {$where} ORDER BY id ASC");
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();

        if ($usuarios) {
            $stmtInsert = $pdo->prepare(
                'INSERT INTO mailing_envios (mailing_id, usuario_id, status, tentativas, created_at)
                 VALUES (?, ?, ?, 0, ?)'
            );

            foreach ($usuarios as $usuario) {
                $stmtInsert->execute([$mailingId, (int) $usuario['id'], 'pendente', $createdAt]);
            }
        } else {
            $pdo->prepare(
                "UPDATE mailings SET status = 'concluido', finished_at = ? WHERE id = ?"
            )->execute([db_now(), $mailingId]);
        }

        $pdo->commit();
        return $mailingId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function atualizar_estatisticas_mailing(int $mailingId): void
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = \'enviado\' THEN 1 ELSE 0 END) AS enviados,
            SUM(CASE WHEN status = \'falhou\' THEN 1 ELSE 0 END) AS falhas,
            SUM(CASE WHEN status = \'pendente\' THEN 1 ELSE 0 END) AS pendentes
         FROM mailing_envios
         WHERE mailing_id = ?'
    );
    $stmt->execute([$mailingId]);
    $stats = $stmt->fetch() ?: [];

    $pendentes = (int) ($stats['pendentes'] ?? 0);
    $status = $pendentes > 0 ? 'processando' : 'concluido';
    $now = db_now();
    $finishedAt = $status === 'concluido' ? $now : null;

    db()->prepare(
        'UPDATE mailings
         SET total_alvo = ?, total_enviado = ?, total_falhou = ?, status = ?,
             started_at = COALESCE(started_at, ?),
             finished_at = ?
         WHERE id = ?'
    )->execute([
        (int) ($stats['total'] ?? 0),
        (int) ($stats['enviados'] ?? 0),
        (int) ($stats['falhas'] ?? 0),
        $status,
        $now,
        $finishedAt,
        $mailingId,
    ]);
}

function processar_mailings_pendentes(int $limit = 20): array
{
    if (!db_has_table('mailings') || !db_has_table('mailing_envios')) {
        return ['processados' => 0, 'enviados' => 0, 'falhas' => 0];
    }

    $limit = max(1, min(100, $limit));
    $tenantSelect = db_has_column('mailing_envios', 'tenant_id')
        ? ', me.tenant_id AS tenant_id'
        : (db_has_column('mailings', 'tenant_id')
            ? ', m.tenant_id AS tenant_id'
            : (db_has_column('usuarios', 'tenant_id') ? ', u.tenant_id AS tenant_id' : ', NULL AS tenant_id'));

    $stmt = db()->prepare(
        'SELECT me.id, me.mailing_id, me.usuario_id, me.tentativas,
                m.nome, m.mensagem, m.media_tipo, m.media_url, m.botao_texto, m.botao_url, m.status AS mailing_status,
                u.telegram_id' . $tenantSelect . '
         FROM mailing_envios me
         JOIN mailings m ON m.id = me.mailing_id
         JOIN usuarios u ON u.id = me.usuario_id
         WHERE me.status = ? AND m.status IN (?, ?)
         ORDER BY me.id ASC
         LIMIT ' . $limit
    );
    $stmt->execute(['pendente', 'pendente', 'processando']);
    $rows = $stmt->fetchAll();

    $processados = 0;
    $enviados = 0;
    $falhas = 0;
    $mailingsAfetados = [];
    $previousTenant = current_tenant();

    foreach ($rows as $row) {
        $processados++;
        $mailingsAfetados[(int) $row['mailing_id']] = true;
        if (tenants_enabled() && !empty($row['tenant_id'])) {
            set_current_tenant_context(get_tenant_by_id((int) $row['tenant_id']));
        }

        if (($row['mailing_status'] ?? '') === 'pendente') {
            db()->prepare("UPDATE mailings SET status = 'processando', started_at = COALESCE(started_at, ?) WHERE id = ?")
                ->execute([db_now(), (int) $row['mailing_id']]);
        }

        $extra = [];
        $botaoTexto = trim((string) ($row['botao_texto'] ?? ''));
        $botaoUrl = trim((string) ($row['botao_url'] ?? ''));
        if ($botaoTexto !== '' && $botaoUrl !== '') {
            $extra['reply_markup'] = json_encode([
                'inline_keyboard' => [[
                    ['text' => $botaoTexto, 'url' => $botaoUrl],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = enviar_conteudo_telegram(
            (int) $row['telegram_id'],
            (string) ($row['mensagem'] ?? ''),
            (string) ($row['media_tipo'] ?? 'none'),
            (string) ($row['media_url'] ?? ''),
            $extra
        );

        if (is_array($response) && !empty($response['ok'])) {
            $enviados++;
            db()->prepare(
                'UPDATE mailing_envios
                 SET status = ?, tentativas = tentativas + 1, sent_at = ?, last_error = NULL
                 WHERE id = ?'
            )->execute(['enviado', db_now(), (int) $row['id']]);
        } else {
            $falhas++;
            db()->prepare(
                'UPDATE mailing_envios
                 SET status = ?, tentativas = tentativas + 1, last_error = ?
                 WHERE id = ?'
            )->execute(['falhou', 'Falha ao enviar via Telegram.', (int) $row['id']]);
        }
    }

    foreach (array_keys($mailingsAfetados) as $mailingId) {
        atualizar_estatisticas_mailing((int) $mailingId);
    }

    set_current_tenant_context($previousTenant);

    return [
        'processados' => $processados,
        'enviados' => $enviados,
        'falhas' => $falhas,
    ];
}

function processar_downsells_pendentes(int $limit = 20): array
{
    if (!db_has_table('downsells') || !db_has_table('downsell_disparos')) {
        return ['processados' => 0, 'enviados' => 0, 'falhas' => 0];
    }

    $limit = max(1, min(100, $limit));
    $hasTipoProduto = db_has_column('produtos', 'tipo');
    $hasPackLink = db_has_column('produtos', 'pack_link');

    $downsellsDueCondition = db_is_pgsql()
        ? "p.created_at <= (CURRENT_TIMESTAMP - ((d.delay_minutes)::text || ' minutes')::interval)"
        : 'TIMESTAMPDIFF(MINUTE, p.created_at, NOW()) >= d.delay_minutes';

    $tenantSelect = db_has_column('downsells', 'tenant_id')
        ? ', d.tenant_id AS tenant_id'
        : (db_has_column('pagamentos', 'tenant_id') ? ', p.tenant_id AS tenant_id' : ', NULL AS tenant_id');

    $stmt = db()->prepare(
        'SELECT d.*,
                p.id AS pagamento_id, p.usuario_id, p.txid, p.created_at AS pagamento_criado_em,
                u.telegram_id, u.first_name' . $tenantSelect . ',
                pr.nome AS produto_nome, pr.valor AS produto_valor, ' .
                ($hasTipoProduto ? 'pr.tipo' : "'grupo' AS tipo") . ', ' .
                ($hasPackLink ? 'pr.pack_link' : 'NULL AS pack_link') . '
         FROM downsells d
         JOIN funis f ON f.id = d.funil_id AND f.ativo = 1
         JOIN pagamentos p ON p.funil_id = d.funil_id AND p.status = ? AND p.tipo_oferta = ?
         JOIN usuarios u ON u.id = p.usuario_id
         JOIN produtos pr ON pr.id = d.produto_id AND pr.ativo = 1
         LEFT JOIN downsell_disparos dd ON dd.downsell_id = d.id AND dd.pagamento_id = p.id
         WHERE d.ativo = 1
           AND u.telegram_id IS NOT NULL
           AND dd.id IS NULL
           AND ' . $downsellsDueCondition . '
         ORDER BY p.id ASC
         LIMIT ' . $limit
    );
    $stmt->execute(['pendente', 'principal']);
    $rows = $stmt->fetchAll();

    $processados = 0;
    $enviados = 0;
    $falhas = 0;
    $previousTenant = current_tenant();

    foreach ($rows as $row) {
        $processados++;
        if (tenants_enabled() && !empty($row['tenant_id'])) {
            set_current_tenant_context(get_tenant_by_id((int) $row['tenant_id']));
        }

        $ok = enviar_oferta_downsell((int) $row['telegram_id'], $row, [
            'nome' => htmlspecialchars((string) ($row['first_name'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'),
        ]);

        try {
            $sentAt = db_now();
            $columns = ['downsell_id', 'pagamento_id', 'usuario_id', 'status', 'sent_at', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?'];
            $params = [
                (int) $row['id'],
                (int) $row['pagamento_id'],
                (int) $row['usuario_id'],
                $ok ? 'enviado' : 'falhou',
                $sentAt,
                $sentAt,
            ];
            tenant_insert_append('downsell_disparos', $columns, $placeholders, $params);
            db()->prepare(
                'INSERT INTO downsell_disparos (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($params);
        } catch (Throwable $e) {
            continue;
        }

        if ($ok) {
            $enviados++;
        } else {
            $falhas++;
        }
    }

    set_current_tenant_context($previousTenant);

    return [
        'processados' => $processados,
        'enviados' => $enviados,
        'falhas' => $falhas,
    ];
}

function montar_teclado_funis(array $funis): string
{
    $keyboard = [];
    foreach ($funis as $funil) {
        $texto = $funil['nome'];
        if (!empty($funil['produto_principal_valor'])) {
            $texto .= ' - ' . formatar_valor((float) $funil['produto_principal_valor']);
        }
        $keyboard[] = [[
            'text' => $texto,
            'callback_data' => 'funil:' . (int) $funil['id'],
        ]];
    }

    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
