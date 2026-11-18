-- ThesisGuard migration 002: restore reviewer-to-project assignments.
-- Source of truth: database/002_project_reviewers.sql in
-- storage/archive/sql-historicos-e7d32360.zip.
--
-- Run only after selecting the intended database and confirming the preflight
-- output below. This script creates a missing table, never drops or updates
-- existing assignment rows. If a table with a different shape already exists,
-- IF NOT EXISTS leaves it untouched and the migration marker is not added.

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('migrations', 'projects', 'users', 'project_reviewers')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

CREATE TABLE IF NOT EXISTS `project_reviewers` (
    `project_id` INT UNSIGNED NOT NULL,
    `reviewer_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED NOT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`project_id`, `reviewer_id`),
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`),
    FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`version`)
SELECT '002_project_reviewers'
WHERE
    (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
          AND (
              (COLUMN_NAME = 'project_id' AND COLUMN_TYPE LIKE 'int%unsigned' AND IS_NULLABLE = 'NO')
              OR (COLUMN_NAME = 'reviewer_id' AND COLUMN_TYPE LIKE 'int%unsigned' AND IS_NULLABLE = 'NO')
              OR (COLUMN_NAME = 'assigned_by' AND COLUMN_TYPE LIKE 'int%unsigned' AND IS_NULLABLE = 'NO')
              OR (
                  COLUMN_NAME = 'assigned_at'
                  AND DATA_TYPE = 'timestamp'
                  AND IS_NULLABLE = 'NO'
                  AND LOWER(COLUMN_DEFAULT) IN ('current_timestamp', 'current_timestamp()')
              )
          )
    ) = 4
    AND (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
    ) = 4
    AND (
        SELECT COUNT(*)
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
          AND CONSTRAINT_NAME = 'PRIMARY'
          AND (
              (COLUMN_NAME = 'project_id' AND ORDINAL_POSITION = 1)
              OR (COLUMN_NAME = 'reviewer_id' AND ORDINAL_POSITION = 2)
          )
    ) = 2
    AND (
        SELECT COUNT(*)
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND (
              (COLUMN_NAME = 'project_id' AND REFERENCED_TABLE_NAME = 'projects' AND REFERENCED_COLUMN_NAME = 'id')
              OR (COLUMN_NAME = 'reviewer_id' AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id')
              OR (COLUMN_NAME = 'assigned_by' AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id')
          )
    ) = 3
    AND (
        SELECT COUNT(*)
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ) = 3
    AND EXISTS (
        SELECT 1
        FROM information_schema.KEY_COLUMN_USAGE AS k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE()
          AND k.TABLE_NAME = 'project_reviewers'
          AND k.COLUMN_NAME = 'project_id'
          AND k.REFERENCED_TABLE_NAME = 'projects'
          AND r.DELETE_RULE = 'CASCADE'
    )
    AND EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_reviewers'
          AND ENGINE = 'InnoDB'
          AND TABLE_COLLATION = 'utf8mb4_unicode_ci'
    );

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'project_reviewers'
ORDER BY ORDINAL_POSITION;

SELECT `version`
FROM `migrations`
WHERE `version` = '002_project_reviewers';
