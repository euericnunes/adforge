<?php
// ============================================================
// AdForge — users.php
//
// GET    ?action=list                        → lista usuários [admin]
// PUT    ?action=update&id=N                → editar usuário [admin]
// DELETE ?action=delete&id=N               → remover usuário [admin]
// GET    ?action=assignments&project_id=N  → atribuições do projeto [admin]
// POST   ?action=assign                    → salvar atribuição [admin]
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'    && $action === 'list'        => handle_list(),
    $method === 'PUT'    && $action === 'update'      => handle_update(),
    $method === 'DELETE' && $action === 'delete'      => handle_delete(),
    $method === 'GET'    && $action === 'assignments' => handle_assignments(),
    $method === 'POST'   && $action === 'assign'      => handle_assign(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=list ─────────────────────────────────────────
function handle_list(): void {
    admin_required();

    $stmt = db()->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY created_at DESC');
    $users = $stmt->fetchAll();

    foreach ($users as &$u) {
        $u['id'] = (int) $u['id'];
        // Conta atribuições ativas por usuário
        $cnt = db()->prepare('
            SELECT COUNT(*) FROM project_assignments WHERE user_id = ? AND role != ?
        ');
        $cnt->execute([$u['id'], 'none']);
        $u['assignments_count'] = (int) $cnt->fetchColumn();
        $u['initials'] = initials($u['name']);
    }

    json_ok($users);
}

// ── PUT ?action=update&id=N ──────────────────────────────────
function handle_update(): void {
    $me     = admin_required();
    $userId = (int) ($_GET['id'] ?? 0);
    if (!$userId) json_error('ID de usuário inválido.');

    $stmt = db()->prepare('SELECT id, role, status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) json_error('Usuário não encontrado.', 404);

    $data   = request_body();
    $fields = [];
    $params = [];

    if (isset($data['name']) && trim($data['name']) !== '') {
        $fields[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (isset($data['role']) && in_array($data['role'], ['admin','editor','approver','viewer'], true)) {
        // Impede remover o último admin ativo
        if ($target['role'] === 'admin' && $data['role'] !== 'admin') {
            $cnt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($cnt <= 1) json_error('Deve existir pelo menos 1 admin ativo.');
        }
        $fields[] = 'role = ?';
        $params[] = $data['role'];
    }
    if (isset($data['password']) && strlen($data['password']) >= 8) {
        $fields[] = 'password = ?';
        $params[] = password_hash($data['password'], PASSWORD_BCRYPT);
    } elseif (isset($data['password']) && $data['password'] !== '') {
        json_error('A senha deve ter no mínimo 8 caracteres.');
    }
    if (isset($data['status']) && in_array($data['status'], ['active','inactive'], true)) {
        // Não desativa o próprio usuário nem o último admin
        if ($data['status'] === 'inactive' && $userId === (int) $me['id']) {
            json_error('Você não pode desativar sua própria conta.');
        }
        if ($data['status'] === 'inactive' && $target['role'] === 'admin') {
            $cnt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($cnt <= 1) json_error('Deve existir pelo menos 1 admin ativo.');
        }
        $fields[] = 'status = ?';
        $params[] = $data['status'];
    }

    if (empty($fields)) json_error('Nenhum campo para atualizar.');

    $params[] = $userId;
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $stmt = db()->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $row['id']       = (int) $row['id'];
    $row['initials'] = initials($row['name']);

    json_ok($row);
}

// ── DELETE ?action=delete&id=N ───────────────────────────────
function handle_delete(): void {
    $me     = admin_required();
    $userId = (int) ($_GET['id'] ?? 0);
    if (!$userId) json_error('ID de usuário inválido.');
    if ($userId === (int) $me['id']) json_error('Você não pode remover sua própria conta.');

    $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) json_error('Usuário não encontrado.', 404);

    if ($target['role'] === 'admin') {
        $cnt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
        if ($cnt <= 1) json_error('Deve existir pelo menos 1 admin ativo.');
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    json_ok(['message' => 'Usuário removido.']);
}

// ── GET ?action=assignments&project_id=N ─────────────────────
function handle_assignments(): void {
    admin_required();
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_error('project_id obrigatório.');

    $stmt = db()->prepare('
        SELECT u.id, u.name, u.email, u.role AS global_role,
               COALESCE(pa.role, "none") AS project_role
        FROM users u
        LEFT JOIN project_assignments pa
               ON pa.user_id = u.id AND pa.project_id = ?
        ORDER BY u.name
    ');
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['id']       = (int) $r['id'];
        $r['initials'] = initials($r['name']);
    }

    json_ok($rows);
}

// ── POST ?action=assign ──────────────────────────────────────
function handle_assign(): void {
    admin_required();
    $data      = require_body('user_id', 'project_id', 'role');
    $userId    = (int) $data['user_id'];
    $projectId = (int) $data['project_id'];
    $role      = $data['role'];

    $validRoles = ['admin','editor','approver','viewer','none'];
    if (!in_array($role, $validRoles, true)) json_error('Perfil inválido.');

    db()->prepare('
        INSERT INTO project_assignments (project_id, user_id, role)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ')->execute([$projectId, $userId, $role]);

    json_ok(['message' => 'Atribuição salva.']);
}

// ── Helper ───────────────────────────────────────────────────
function initials(string $name): string {
    $words = explode(' ', trim($name));
    $ini   = '';
    foreach ($words as $w) {
        if ($w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($ini) >= 2) break;
    }
    return $ini;
}
