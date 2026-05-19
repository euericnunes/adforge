<?php
// ============================================================
// AdForge — brain.php
//
// GET    ?action=get&project_id=N          → cérebro completo do projeto
// POST   ?action=add_context               → { project_id, type, content }
// DELETE ?action=delete_context&id=N       → remove bloco de contexto
// POST   ?action=save_voice                → { project_id, ...voice }
// POST   ?action=add_reference             → { project_id, type, description, image_path?, image_mime? }
// DELETE ?action=delete_reference&id=N     → remove referência
// POST   ?action=add_learning              → { project_id, text }
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'    && $action === 'get'              => handle_get(),
    $method === 'POST'   && $action === 'add_context'      => handle_add_context(),
    $method === 'DELETE' && $action === 'delete_context'   => handle_delete_context(),
    $method === 'POST'   && $action === 'save_voice'       => handle_save_voice(),
    $method === 'POST'   && $action === 'add_reference'    => handle_add_reference(),
    $method === 'DELETE' && $action === 'delete_reference' => handle_delete_reference(),
    $method === 'POST'   && $action === 'add_learning'     => handle_add_learning(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=get&project_id=N ────────────────────────────
function handle_get(): void {
    $user      = auth_required();
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_error('project_id obrigatório.');

    require_project_access($user['id'], $projectId, $user['role']);

    // Contextos
    $ctxStmt = db()->prepare('SELECT * FROM brain_contexts WHERE project_id = ? ORDER BY created_at DESC');
    $ctxStmt->execute([$projectId]);
    $contexts = $ctxStmt->fetchAll();
    foreach ($contexts as &$c) { $c['id'] = (int) $c['id']; }

    // Tom de voz
    $voiceStmt = db()->prepare('SELECT * FROM brain_voice WHERE project_id = ?');
    $voiceStmt->execute([$projectId]);
    $voiceRow = $voiceStmt->fetch();
    $voice    = null;
    if ($voiceRow) {
        $voice = [
            'personality'  => $voiceRow['personality'],
            'useWords'     => json_decode($voiceRow['use_words']   ?? '[]', true) ?: [],
            'avoidWords'   => json_decode($voiceRow['avoid_words'] ?? '[]', true) ?: [],
            'structure'    => $voiceRow['structure'],
            'visualStyle'  => $voiceRow['visual_style'],
            'examples'     => json_decode($voiceRow['examples']    ?? '[]', true) ?: [],
        ];
    }

    // Referências (retorna image_path para o frontend montar URL)
    $refStmt = db()->prepare('SELECT * FROM brain_references WHERE project_id = ? ORDER BY created_at DESC');
    $refStmt->execute([$projectId]);
    $references = $refStmt->fetchAll();
    foreach ($references as &$r) {
        $r['id'] = (int) $r['id'];
        $r['imageUrl'] = $r['image_path'] ? UPLOAD_URL . $r['image_path'] : null;
        unset($r['image_path']);
    }

    // Aprendizados
    $learnStmt = db()->prepare('SELECT * FROM brain_learnings WHERE project_id = ? ORDER BY created_at DESC');
    $learnStmt->execute([$projectId]);
    $learnings = $learnStmt->fetchAll();
    foreach ($learnings as &$l) { $l['id'] = (int) $l['id']; }

    json_ok([
        'contexts'   => $contexts,
        'voice'      => $voice,
        'references' => $references,
        'learnings'  => $learnings,
    ]);
}

// ── POST ?action=add_context ─────────────────────────────────
function handle_add_context(): void {
    $user = auth_required();
    $data = require_body('project_id', 'content');

    $projectId = (int) $data['project_id'];
    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    $validTypes = ['positioning','audience','product','campaign','restriction','other'];
    $type       = in_array($data['type'] ?? '', $validTypes, true) ? $data['type'] : 'other';

    $stmt = db()->prepare('
        INSERT INTO brain_contexts (project_id, type, content, created_by)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$projectId, $type, trim($data['content']), $user['id']]);
    $id = (int) db()->lastInsertId();

    $row = db()->prepare('SELECT * FROM brain_contexts WHERE id = ?');
    $row->execute([$id]);
    $ctx = $row->fetch();
    $ctx['id'] = $id;

    json_ok($ctx, 201);
}

// ── DELETE ?action=delete_context&id=N ──────────────────────
function handle_delete_context(): void {
    $user = auth_required();
    $id   = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('ID inválido.');

    $stmt = db()->prepare('SELECT project_id FROM brain_contexts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Bloco de contexto não encontrado.', 404);

    require_project_access($user['id'], (int) $row['project_id'], $user['role'], ['admin', 'editor']);

    db()->prepare('DELETE FROM brain_contexts WHERE id = ?')->execute([$id]);
    json_ok(['message' => 'Contexto removido.']);
}

// ── POST ?action=save_voice ──────────────────────────────────
function handle_save_voice(): void {
    $user = auth_required();
    $data = require_body('project_id');

    $projectId = (int) $data['project_id'];
    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    $useWords   = json_encode($data['useWords']   ?? [], JSON_UNESCAPED_UNICODE);
    $avoidWords = json_encode($data['avoidWords'] ?? [], JSON_UNESCAPED_UNICODE);
    $examples   = json_encode($data['examples']   ?? [], JSON_UNESCAPED_UNICODE);

    db()->prepare('
        INSERT INTO brain_voice (project_id, personality, use_words, avoid_words, structure, visual_style, examples)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          personality  = VALUES(personality),
          use_words    = VALUES(use_words),
          avoid_words  = VALUES(avoid_words),
          structure    = VALUES(structure),
          visual_style = VALUES(visual_style),
          examples     = VALUES(examples)
    ')->execute([
        $projectId,
        trim($data['personality']  ?? ''),
        $useWords,
        $avoidWords,
        trim($data['structure']   ?? ''),
        trim($data['visualStyle'] ?? ''),
        $examples,
    ]);

    json_ok(['message' => 'Tom de voz salvo.']);
}

// ── POST ?action=add_reference ───────────────────────────────
function handle_add_reference(): void {
    $user = auth_required();
    $data = require_body('project_id', 'description');

    $projectId = (int) $data['project_id'];
    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    $validTypes = ['competitor','inspiration','campaign','brand','other'];
    $type       = in_array($data['type'] ?? '', $validTypes, true) ? $data['type'] : 'other';

    // image_path já é o caminho salvo pelo upload.php
    $imagePath = !empty($data['image_path']) ? trim($data['image_path']) : null;
    $imageMime = !empty($data['image_mime']) ? trim($data['image_mime']) : null;

    db()->prepare('
        INSERT INTO brain_references (project_id, type, description, image_path, image_mime, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([$projectId, $type, trim($data['description']), $imagePath, $imageMime, $user['id']]);

    $id  = (int) db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM brain_references WHERE id = ?');
    $row->execute([$id]);
    $ref = $row->fetch();
    $ref['id']       = $id;
    $ref['imageUrl'] = $imagePath ? UPLOAD_URL . $imagePath : null;
    unset($ref['image_path']);

    json_ok($ref, 201);
}

// ── DELETE ?action=delete_reference&id=N ─────────────────────
function handle_delete_reference(): void {
    $user = auth_required();
    $id   = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('ID inválido.');

    $stmt = db()->prepare('SELECT project_id, image_path FROM brain_references WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Referência não encontrada.', 404);

    require_project_access($user['id'], (int) $row['project_id'], $user['role'], ['admin', 'editor']);

    // Remove arquivo físico se existir
    if ($row['image_path']) {
        $path = UPLOAD_DIR . $row['image_path'];
        if (file_exists($path)) @unlink($path);
    }

    db()->prepare('DELETE FROM brain_references WHERE id = ?')->execute([$id]);
    json_ok(['message' => 'Referência removida.']);
}

// ── POST ?action=add_learning ────────────────────────────────
function handle_add_learning(): void {
    $user = auth_required();
    $data = require_body('project_id', 'text');

    $projectId = (int) $data['project_id'];
    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    db()->prepare('
        INSERT INTO brain_learnings (project_id, text, source)
        VALUES (?, ?, ?)
    ')->execute([$projectId, trim($data['text']), 'manual']);

    json_ok(['message' => 'Aprendizado adicionado.'], 201);
}
