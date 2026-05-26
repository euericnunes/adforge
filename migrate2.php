<?php
// AdForge 2.0 — Migração. DELETE este arquivo após usar.
require __DIR__ . '/api/config.php';
$pdo = db();

$cols = $pdo->query("SHOW COLUMNS FROM ads LIKE 'html_canvas'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE ads ADD COLUMN html_canvas MEDIUMTEXT NULL AFTER bg_image");
    echo '<b style="color:green">✅ Coluna html_canvas adicionada à tabela ads.</b><br>';
} else {
    echo '✓ html_canvas já existe.<br>';
}

$tables = $pdo->query("SHOW TABLES LIKE 'platform_intelligence'")->fetchAll();
if (!$tables) {
    $pdo->exec("CREATE TABLE platform_intelligence (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pattern_type VARCHAR(50) NOT NULL,
        content TEXT NOT NULL,
        source_project_id INT NULL,
        source_project_name VARCHAR(200) NULL,
        source_ad_id INT NULL,
        source_ad_name VARCHAR(200) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (pattern_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo '<b style="color:green">✅ Tabela platform_intelligence criada.</b><br>';
} else {
    echo '✓ platform_intelligence já existe.<br>';
}

echo '<br><b style="color:red">DELETE este arquivo agora via File Manager ou FTP!</b>';
