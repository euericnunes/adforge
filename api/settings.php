<?php
// ============================================================
// AdForge — settings.php
// Configurações de provedores de IA (apenas admin)
//
// GET   ?action=get          → lista provedores com chaves decriptadas
// POST  ?action=save         → { provider, enabled, api_key, enabled_models }
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'  && $action === 'get'  => handle_get(),
    $method === 'POST' && $action === 'save' => handle_save(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=get ──────────────────────────────────────────
function handle_get(): void {
    $user = auth_required();

    $stmt = db()->query('SELECT * FROM ai_settings');
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $entry = [
            'provider'       => $r['provider'],
            'enabled'        => (bool) $r['enabled'],
            'enabled_models' => json_decode($r['enabled_models'] ?? '[]', true) ?: [],
            'has_key'        => !empty($r['api_key']),
        ];
        // Admin vê a chave descriptografada; outros, apenas o flag has_key
        if ($user['role'] === 'admin' && !empty($r['api_key'])) {
            try {
                $entry['api_key'] = decrypt_key($r['api_key']);
            } catch (\Throwable) {
                $entry['api_key'] = '';
            }
        }
        $result[$r['provider']] = $entry;
    }

    json_ok($result);
}

// ── POST ?action=save ────────────────────────────────────────
function handle_save(): void {
    admin_required();

    $data     = require_body('provider');
    $provider = $data['provider'];

    $validProviders = ['anthropic', 'openai', 'google', 'groq'];
    if (!in_array($provider, $validProviders, true)) {
        json_error('Provedor inválido.');
    }

    $fields = [];
    $params = [];

    if (isset($data['enabled'])) {
        $fields[] = 'enabled = ?';
        $params[] = $data['enabled'] ? 1 : 0;
    }
    if (isset($data['api_key'])) {
        $key = trim($data['api_key']);
        $fields[] = 'api_key = ?';
        $params[] = $key !== '' ? encrypt_key($key) : null;
    }
    if (isset($data['enabled_models']) && is_array($data['enabled_models'])) {
        if (empty($data['enabled_models'])) json_error('Pelo menos 1 modelo deve estar habilitado.');
        $fields[] = 'enabled_models = ?';
        $params[] = json_encode($data['enabled_models']);
    }

    if (empty($fields)) json_error('Nenhum campo para atualizar.');

    // Upsert — garante que o registro existe
    $params[] = $provider;
    db()->prepare(
        'UPDATE ai_settings SET ' . implode(', ', $fields) . ' WHERE provider = ?'
    )->execute($params);

    json_ok(['message' => "Configurações de $provider salvas."]);
}
