<?php
// AdForge — intelligence.php
// GET    ?action=list         → lista padrões da plataforma
// DELETE ?action=delete&id=N → remove padrão

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'    && $action === 'list'   => handle_list(),
    $method === 'DELETE' && $action === 'delete' => handle_delete(),
    default => json_error('Rota não encontrada.', 404),
};

function handle_list(): void {
    auth_required();
    $limit = min((int) ($_GET['limit'] ?? 30), 100);
    $stmt = db()->prepare('SELECT * FROM platform_intelligence ORDER BY created_at DESC LIMIT ?');
    $stmt->execute([$limit]);
    json_ok($stmt->fetchAll());
}

function handle_delete(): void {
    admin_required();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('ID inválido.');
    db()->prepare('DELETE FROM platform_intelligence WHERE id = ?')->execute([$id]);
    json_ok(['message' => 'Padrão removido.']);
}
