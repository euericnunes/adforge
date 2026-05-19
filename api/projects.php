<?php
// ============================================================
// AdForge — projects.php
//
// GET    ?action=list            → lista projetos do usuário
// POST   ?action=create         → { name, context, tags, color, logo }
// PUT    ?action=update&id=N    → campos alterados
// DELETE ?action=delete&id=N   → exclui projeto (admin)
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'    && $action === 'list'   => handle_list(),
    $method === 'POST'   && $action === 'create' => handle_create(),
    $method === 'PUT'    && $action === 'update' => handle_update(),
    $method === 'DELETE' && $action === 'delete' => handle_delete(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=list ─────────────────────────────────────────
function handle_list(): void {
    $user = auth_required();
    $userId = (int) $user['id'];

    if ($user['role'] === 'admin') {
        // Admin vê todos os projetos
        $stmt = db()->prepare('SELECT * FROM projects ORDER BY created_at DESC');
        $stmt->execute();
    } else {
        // Outros vêem apenas projetos com atribuição ativa
        $stmt = db()->prepare('
            SELECT p.*
            FROM projects p
            JOIN project_assignments pa ON pa.project_id = p.id
            WHERE pa.user_id = ? AND pa.role != ?
            ORDER BY p.created_at DESC
        ');
        $stmt->execute([$userId, 'none']);
    }

    $projects = $stmt->fetchAll();
    foreach ($projects as &$p) {
        $p['id']   = (int) $p['id'];
        $p['tags'] = json_decode($p['tags'] ?? '[]', true) ?: [];
        // Contagem de anúncios
        $cnt = db()->prepare('SELECT COUNT(*) FROM ads WHERE project_id = ?');
        $cnt->execute([$p['id']]);
        $p['ads_count'] = (int) $cnt->fetchColumn();
    }

    json_ok($projects);
}

// ── POST ?action=create ──────────────────────────────────────
function handle_create(): void {
    $user = auth_required();
    if (!in_array($user['role'], ['admin', 'editor'], true)) {
        json_error('Sem permissão para criar projetos.', 403);
    }

    $data = require_body('name');
    $name  = trim($data['name']);
    $ctx   = trim($data['context'] ?? '');
    $tags  = json_encode(
        array_values(array_filter(array_map('trim', (array) ($data['tags'] ?? []))))
    );
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'] ?? '') ? $data['color'] : '#d4f24a';
    $logo  = trim($data['logo'] ?? '') ?: mb_strtoupper(mb_substr($name, 0, 2));

    $stmt = db()->prepare('
        INSERT INTO projects (name, context, tags, color, logo, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$name, $ctx, $tags, $color, $logo, $user['id']]);
    $id = (int) db()->lastInsertId();

    // Auto-atribui o criador como admin do projeto
    db()->prepare('
        INSERT INTO project_assignments (project_id, user_id, role)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ')->execute([$id, $user['id'], 'admin']);

    $project = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $project->execute([$id]);
    $row = $project->fetch();
    $row['id']   = $id;
    $row['tags'] = json_decode($row['tags'], true) ?: [];
    $row['ads_count'] = 0;

    json_ok($row, 201);
}

// ── PUT ?action=update&id=N ──────────────────────────────────
function handle_update(): void {
    $user      = auth_required();
    $projectId = (int) ($_GET['id'] ?? 0);
    if (!$projectId) json_error('ID de projeto inválido.');

    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    $data   = request_body();
    $fields = [];
    $params = [];

    if (isset($data['name']) && trim($data['name']) !== '') {
        $fields[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (array_key_exists('context', $data)) {
        $fields[] = 'context = ?';
        $params[] = trim($data['context']);
    }
    if (isset($data['tags'])) {
        $fields[] = 'tags = ?';
        $params[] = json_encode(array_values(array_filter(array_map('trim', (array) $data['tags']))));
    }
    if (isset($data['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'])) {
        $fields[] = 'color = ?';
        $params[] = $data['color'];
    }
    if (isset($data['logo'])) {
        $fields[] = 'logo = ?';
        $params[] = trim($data['logo']);
    }

    if (empty($fields)) json_error('Nenhum campo para atualizar.');

    $params[] = $projectId;
    db()->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    $row['id']   = (int) $row['id'];
    $row['tags'] = json_decode($row['tags'], true) ?: [];

    json_ok($row);
}

// ── DELETE ?action=delete&id=N ───────────────────────────────
function handle_delete(): void {
    $user      = admin_required();
    $projectId = (int) ($_GET['id'] ?? 0);
    if (!$projectId) json_error('ID de projeto inválido.');

    // CASCADE no banco apaga ads, brain_*, assignments automaticamente
    $stmt = db()->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);

    if ($stmt->rowCount() === 0) json_error('Projeto não encontrado.', 404);

    json_ok(['message' => 'Projeto excluído.']);
}
