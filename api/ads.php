<?php
// ============================================================
// AdForge — ads.php
//
// GET    ?action=list&project_id=N        → anúncios do projeto
// POST   ?action=create                   → novo anúncio
// PUT    ?action=update&id=N              → editar anúncio
// PATCH  ?action=status&id=N             → { status, rejection_reason }
// POST   ?action=duplicate&id=N          → duplica como rascunho
// DELETE ?action=delete&id=N             → exclui anúncio
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'    && $action === 'list'      => handle_list(),
    $method === 'POST'   && $action === 'create'    => handle_create(),
    $method === 'PUT'    && $action === 'update'    => handle_update(),
    $method === 'PATCH'  && $action === 'status'    => handle_status(),
    $method === 'POST'   && $action === 'duplicate' => handle_duplicate(),
    $method === 'DELETE' && $action === 'delete'    => handle_delete(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=list&project_id=N ───────────────────────────
function handle_list(): void {
    $user      = auth_required();
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_error('project_id obrigatório.');

    $role = require_project_access($user['id'], $projectId, $user['role']);

    $stmt = db()->prepare('SELECT * FROM ads WHERE project_id = ? ORDER BY created_at DESC');
    $stmt->execute([$projectId]);
    $ads = $stmt->fetchAll();

    foreach ($ads as &$ad) {
        $ad = normalize_ad($ad, $role);
    }

    json_ok($ads);
}

// ── POST ?action=create ──────────────────────────────────────
function handle_create(): void {
    $user = auth_required();
    $data = require_body('project_id', 'name', 'slides');

    $projectId = (int) $data['project_id'];
    require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

    $slides = is_array($data['slides']) ? $data['slides'] : json_decode($data['slides'], true);
    if (empty($slides)) json_error('O anúncio precisa de pelo menos 1 slide.');

    validate_slides($slides);

    $type      = in_array($data['type'] ?? '', ['single', 'carousel']) ? $data['type'] : 'single';
    $objective = in_array($data['objective'] ?? '', ['conversion','awareness','engagement','retention'])
                 ? $data['objective'] : 'conversion';
    $sizes     = is_array($data['sizes'] ?? null) ? $data['sizes'] : ['feed'];

    db()->prepare('
        INSERT INTO ads
          (project_id, name, type, objective, status, bg_color, text_color, accent_color,
           bg_image, html_canvas, slides, sizes, generated_by, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $projectId,
        trim($data['name']),
        $type,
        $objective,
        'draft',
        sanitize_color($data['bg_color'] ?? '#1e2024'),
        sanitize_color($data['text_color'] ?? '#ffffff'),
        sanitize_color($data['accent_color'] ?? '#d4f24a'),
        $data['bgImage'] ?? null,
        $data['htmlCanvas'] ?? null,
        json_encode($slides, JSON_UNESCAPED_UNICODE),
        json_encode($sizes),
        trim($data['generated_by'] ?? ''),
        $user['id'],
    ]);

    $id   = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$id]);
    $ad = normalize_ad($stmt->fetch(), 'admin');

    json_ok($ad, 201);
}

// ── PUT ?action=update&id=N ──────────────────────────────────
function handle_update(): void {
    $user  = auth_required();
    $adId  = (int) ($_GET['id'] ?? 0);
    if (!$adId) json_error('ID de anúncio inválido.');

    $ad = fetch_ad_or_fail($adId);
    require_project_access($user['id'], (int) $ad['project_id'], $user['role'], ['admin', 'editor']);

    $data   = request_body();
    $fields = [];
    $params = [];

    if (isset($data['name']) && trim($data['name']) !== '') {
        $fields[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (isset($data['slides'])) {
        $slides = is_array($data['slides']) ? $data['slides'] : json_decode($data['slides'], true);
        validate_slides($slides);
        $fields[] = 'slides = ?';
        $params[] = json_encode($slides, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('bgImage', $data)) {
        $fields[] = 'bg_image = ?';
        $params[] = $data['bgImage'] ?: null;
    }
    if (array_key_exists('htmlCanvas', $data)) {
        $fields[] = 'html_canvas = ?';
        $params[] = $data['htmlCanvas'] ?: null;
    }
    foreach (['bg_color','text_color','accent_color'] as $col) {
        $key = str_replace('_', '', lcfirst(ucwords($col, '_')));
        $jsKey = match ($col) {
            'bg_color'     => 'bgColor',
            'text_color'   => 'textColor',
            'accent_color' => 'accentColor',
        };
        if (isset($data[$jsKey])) {
            $fields[] = "$col = ?";
            $params[] = sanitize_color($data[$jsKey]);
        }
    }
    if (isset($data['sizes']) && is_array($data['sizes'])) {
        $fields[] = 'sizes = ?';
        $params[] = json_encode($data['sizes']);
    }

    if (empty($fields)) json_error('Nenhum campo para atualizar.');

    $params[] = $adId;
    db()->prepare('UPDATE ads SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $stmt = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$adId]);
    json_ok(normalize_ad($stmt->fetch(), 'admin'));
}

// ── PATCH ?action=status&id=N ────────────────────────────────
function handle_status(): void {
    $user  = auth_required();
    $adId  = (int) ($_GET['id'] ?? 0);
    if (!$adId) json_error('ID de anúncio inválido.');

    $ad   = fetch_ad_or_fail($adId);
    $role = require_project_access($user['id'], (int) $ad['project_id'], $user['role']);

    $data   = require_body('status');
    $status = $data['status'];

    $validTransitions = [
        'draft'    => ['review'],
        'review'   => ['approved', 'rejected', 'draft'],
        'approved' => ['draft'],
        'rejected' => ['draft'],
    ];

    $currentStatus = $ad['status'];
    if (!in_array($status, $validTransitions[$currentStatus] ?? [], true)) {
        json_error("Transição de status inválida: $currentStatus → $status.");
    }

    // Aprovação/rejeição: apenas approver ou admin
    if (in_array($status, ['approved', 'rejected'], true)
        && !in_array($role, ['admin', 'approver'], true)) {
        json_error('Sem permissão para aprovar ou rejeitar.', 403);
    }

    $rejectionReason = null;
    if ($status === 'rejected') {
        $reason = trim($data['rejection_reason'] ?? '');
        if ($reason === '') json_error('Motivo da rejeição é obrigatório.');
        $rejectionReason = $reason;

        // Salva nos aprendizados do projeto
        db()->prepare('
            INSERT INTO brain_learnings (project_id, text, ad_id, ad_name, source)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $ad['project_id'],
            "Rejeição: $reason",
            $adId,
            $ad['name'],
            'rejection',
        ]);
    }

    if ($status === 'approved') {
        db()->prepare('
            INSERT INTO brain_learnings (project_id, text, ad_id, ad_name, source)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $ad['project_id'],
            "Anúncio aprovado: \"{$ad['name']}\".",
            $adId,
            $ad['name'],
            'approval',
        ]);

        // Acumula inteligência de plataforma (cross-project learning)
        $slides = json_decode($ad['slides'] ?? '[]', true) ?: [];
        $headline = $slides[0]['headline'] ?? '';
        $body     = $slides[0]['body'] ?? '';
        $objective = $ad['objective'] ?? 'conversion';
        $hasHtml   = !empty($ad['html_canvas']);
        if ($headline || $hasHtml) {
            $patternContent = "Objetivo: {$objective}";
            if ($headline) $patternContent .= " | Headline: {$headline}";
            if ($body)     $patternContent .= " | Texto: {$body}";
            if ($hasHtml)  $patternContent .= " | Layout: anúncio HTML completo aprovado";
            $projName = db()->prepare('SELECT name FROM projects WHERE id = ?');
            $projName->execute([$ad['project_id']]);
            $pName = $projName->fetchColumn() ?: '';
            db()->prepare('
                INSERT INTO platform_intelligence
                  (pattern_type, content, source_project_id, source_project_name, source_ad_id, source_ad_name)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute(['copy', $patternContent, $ad['project_id'], $pName, $adId, $ad['name']]);
        }
    }

    db()->prepare('UPDATE ads SET status = ?, rejection_reason = ? WHERE id = ?')
        ->execute([$status, $rejectionReason, $adId]);

    $stmt = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$adId]);
    json_ok(normalize_ad($stmt->fetch(), $role));
}

// ── POST ?action=duplicate&id=N ──────────────────────────────
function handle_duplicate(): void {
    $user  = auth_required();
    $adId  = (int) ($_GET['id'] ?? 0);
    if (!$adId) json_error('ID de anúncio inválido.');

    $ad = fetch_ad_or_fail($adId);
    require_project_access($user['id'], (int) $ad['project_id'], $user['role'], ['admin', 'editor']);

    db()->prepare('
        INSERT INTO ads
          (project_id, name, type, objective, status, bg_color, text_color, accent_color,
           bg_image, html_canvas, slides, sizes, generated_by, created_by)
        SELECT project_id, CONCAT(name, " (cópia)"), type, objective, "draft",
               bg_color, text_color, accent_color, bg_image, html_canvas,
               slides, sizes, generated_by, ?
        FROM ads WHERE id = ?
    ')->execute([$user['id'], $adId]);

    $newId = (int) db()->lastInsertId();
    $stmt  = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$newId]);

    json_ok(normalize_ad($stmt->fetch(), 'admin'), 201);
}

// ── DELETE ?action=delete&id=N ───────────────────────────────
function handle_delete(): void {
    $user  = auth_required();
    $adId  = (int) ($_GET['id'] ?? 0);
    if (!$adId) json_error('ID de anúncio inválido.');

    $ad = fetch_ad_or_fail($adId);
    require_project_access($user['id'], (int) $ad['project_id'], $user['role'], ['admin', 'editor']);

    db()->prepare('DELETE FROM ads WHERE id = ?')->execute([$adId]);

    json_ok(['message' => 'Anúncio excluído.']);
}

// ── Helpers ──────────────────────────────────────────────────
function fetch_ad_or_fail(int $id): array {
    $stmt = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$id]);
    $ad = $stmt->fetch();
    if (!$ad) json_error('Anúncio não encontrado.', 404);
    return $ad;
}

function normalize_ad(array $ad, string $role): array {
    $ad['id']         = (int) $ad['id'];
    $ad['project_id'] = (int) $ad['project_id'];
    $ad['created_by'] = (int) $ad['created_by'];
    $ad['slides']     = json_decode($ad['slides'], true) ?: [];
    $ad['sizes']      = json_decode($ad['sizes'] ?? '["feed"]', true) ?: ['feed'];
    // Mapeia nomes DB → frontend
    $ad['bgColor']     = $ad['bg_color'];
    $ad['textColor']   = $ad['text_color'];
    $ad['accentColor'] = $ad['accent_color'];
    $ad['bgImage']     = $ad['bg_image'] ?? null;
    $ad['htmlCanvas']  = $ad['html_canvas'] ?? null;
    $ad['generatedBy'] = $ad['generated_by'];
    $ad['rejectionReason'] = $ad['rejection_reason'];
    $ad['createdAt']   = $ad['created_at'];
    unset($ad['bg_color'], $ad['text_color'], $ad['accent_color'], $ad['bg_image'],
          $ad['html_canvas'], $ad['generated_by'], $ad['rejection_reason'],
          $ad['created_at'], $ad['updated_at']);
    return $ad;
}

function validate_slides(array $slides): void {
    if (count($slides) > 10) json_error('Máximo de 10 slides por anúncio.');
    foreach ($slides as $i => $s) {
        if (empty($s['headline'])) json_error("Slide " . ($i + 1) . " precisa de um headline.");
    }
}

function sanitize_color(string $color): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1e2024';
}
