-- ============================================================
-- AdForge — Schema MySQL completo
-- Hospedagem: cPanel / MySQL 5.7+
-- Executar via phpMyAdmin ou mysql CLI
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '-03:00';

-- ============================================================
-- TABELA: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `email`        VARCHAR(180) NOT NULL,
  `password`     VARCHAR(255) NOT NULL COMMENT 'bcrypt via password_hash()',
  `role`         ENUM('admin','editor','approver','viewer') NOT NULL DEFAULT 'editor',
  `status`       ENUM('active','inactive','pending')        NOT NULL DEFAULT 'pending',
  `invite_token` VARCHAR(64)  NULL DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_role`   (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         VARCHAR(128) NOT NULL,
  `user_id`    INT          NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: projects
-- ============================================================
CREATE TABLE IF NOT EXISTS `projects` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `context`    TEXT         NULL,
  `tags`       VARCHAR(500) NULL     COMMENT 'JSON array: ["saúde","beleza"]',
  `color`      VARCHAR(10)  NOT NULL DEFAULT '#d4f24a',
  `logo`       VARCHAR(80)  NULL,
  `created_by` INT          NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_projects_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: project_assignments
-- ============================================================
CREATE TABLE IF NOT EXISTS `project_assignments` (
  `id`         INT  NOT NULL AUTO_INCREMENT,
  `project_id` INT  NOT NULL,
  `user_id`    INT  NOT NULL,
  `role`       ENUM('admin','editor','approver','viewer','none') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment` (`project_id`, `user_id`),
  KEY `idx_pa_user`    (`user_id`),
  KEY `idx_pa_project` (`project_id`),
  CONSTRAINT `fk_pa_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: ads
-- ============================================================
CREATE TABLE IF NOT EXISTS `ads` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `project_id`       INT          NOT NULL,
  `name`             VARCHAR(200) NOT NULL,
  `type`             ENUM('single','carousel')                                   NOT NULL DEFAULT 'single',
  `objective`        ENUM('conversion','awareness','engagement','retention')     NOT NULL DEFAULT 'conversion',
  `status`           ENUM('draft','review','approved','rejected')                NOT NULL DEFAULT 'draft',
  `bg_color`         VARCHAR(10)  NOT NULL DEFAULT '#1e2024',
  `text_color`       VARCHAR(10)  NOT NULL DEFAULT '#ffffff',
  `accent_color`     VARCHAR(10)  NOT NULL DEFAULT '#d4f24a',
  `slides`           LONGTEXT     NOT NULL COMMENT 'JSON array de slides [{tag,headline,body,cta}]',
  `sizes`            VARCHAR(200) NULL     COMMENT 'JSON array: ["feed","stories"]',
  `rejection_reason` TEXT         NULL,
  `generated_by`     VARCHAR(80)  NULL     COMMENT 'Nome do provedor de IA usado',
  `created_by`       INT          NOT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ads_project`  (`project_id`),
  KEY `idx_ads_status`   (`status`),
  KEY `idx_ads_user`     (`created_by`),
  KEY `idx_ads_created`  (`created_at`),
  CONSTRAINT `fk_ads_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ads_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: brain_contexts
-- ============================================================
CREATE TABLE IF NOT EXISTS `brain_contexts` (
  `id`         INT  NOT NULL AUTO_INCREMENT,
  `project_id` INT  NOT NULL,
  `type`       ENUM('positioning','audience','product','campaign','restriction','other') NOT NULL DEFAULT 'other',
  `content`    TEXT NOT NULL,
  `created_by` INT  NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bc_project` (`project_id`),
  KEY `idx_bc_type`    (`type`),
  CONSTRAINT `fk_bc_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bc_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: brain_references
-- ============================================================
CREATE TABLE IF NOT EXISTS `brain_references` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `project_id`  INT          NOT NULL,
  `type`        ENUM('competitor','inspiration','campaign','brand','other') NOT NULL DEFAULT 'other',
  `description` TEXT         NOT NULL,
  `image_path`  VARCHAR(300) NULL COMMENT 'Caminho relativo em uploads/{project_id}/',
  `image_mime`  VARCHAR(50)  NULL,
  `created_by`  INT          NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_br_project` (`project_id`),
  CONSTRAINT `fk_br_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_br_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: brain_voice
-- ============================================================
CREATE TABLE IF NOT EXISTS `brain_voice` (
  `id`           INT  NOT NULL AUTO_INCREMENT,
  `project_id`   INT  NOT NULL,
  `personality`  TEXT NULL,
  `use_words`    VARCHAR(500) NULL COMMENT 'JSON array de palavras a usar',
  `avoid_words`  VARCHAR(300) NULL COMMENT 'JSON array de palavras a evitar',
  `structure`    TEXT NULL,
  `visual_style` TEXT NULL,
  `examples`     TEXT NULL    COMMENT 'JSON array de headlines de exemplo',
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bv_project` (`project_id`),
  CONSTRAINT `fk_bv_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: brain_learnings
-- (complementa os learnings manuais que no protótipo ficam no brain.learnings)
-- ============================================================
CREATE TABLE IF NOT EXISTS `brain_learnings` (
  `id`         INT  NOT NULL AUTO_INCREMENT,
  `project_id` INT  NOT NULL,
  `text`       TEXT NOT NULL,
  `ad_id`      INT  NULL COMMENT 'Anúncio que originou o aprendizado (se houver)',
  `ad_name`    VARCHAR(200) NULL,
  `source`     ENUM('rejection','approval','manual') NOT NULL DEFAULT 'manual',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bl_project` (`project_id`),
  KEY `idx_bl_source`  (`source`),
  CONSTRAINT `fk_bl_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: ai_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id`             INT  NOT NULL AUTO_INCREMENT,
  `provider`       ENUM('anthropic','openai','google','groq') NOT NULL,
  `enabled`        TINYINT(1) NOT NULL DEFAULT 0,
  `api_key`        TEXT NULL  COMMENT 'Criptografar em produção com AES ou variável de ambiente',
  `enabled_models` TEXT NULL  COMMENT 'JSON array de model IDs habilitados',
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

-- Admin padrão: email = admin@adforge.com / senha = Admin@123
-- Hash gerado com password_hash('Admin@123', PASSWORD_BCRYPT)
-- TROQUE A SENHA imediatamente após o primeiro login
INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`)
VALUES (
  'Administrador',
  'admin@adforge.com',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin',
  'active'
) ON DUPLICATE KEY UPDATE `id` = `id`;

-- Configurações padrão dos provedores de IA
INSERT INTO `ai_settings` (`provider`, `enabled`, `api_key`, `enabled_models`) VALUES
  ('anthropic', 1, NULL, '["claude-sonnet-4-20250514","claude-haiku-4-5-20251001","claude-opus-4-6"]'),
  ('openai',    0, NULL, '["gpt-4o","gpt-4o-mini"]'),
  ('google',    0, NULL, '["gemini-2.0-flash","gemini-1.5-pro","gemini-1.5-flash"]'),
  ('groq',      0, NULL, '["llama-3.3-70b-versatile","llama-3.1-8b-instant","mixtral-8x7b-32768"]')
ON DUPLICATE KEY UPDATE `id` = `id`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- ÍNDICES ADICIONAIS úteis para buscas frequentes
-- ============================================================

-- Busca de anúncios por status dentro de um projeto
CREATE INDEX IF NOT EXISTS `idx_ads_project_status`
  ON `ads` (`project_id`, `status`);

-- Listagem de sessões ativas por usuário
CREATE INDEX IF NOT EXISTS `idx_sessions_user_expires`
  ON `sessions` (`user_id`, `expires_at`);
