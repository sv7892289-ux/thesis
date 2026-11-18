<?php
namespace ThesisGuard;

class Dashboard
{
    public static function scope(array $u): array
    {
        return match ($u['role']) {
            'admin' => ['1=1', []],

            'coordinator' => [
                'p.career_id=?',
                [$u['career_id']]
            ],

            'reviewer' => [
                'EXISTS (
                    SELECT 1
                    FROM project_reviewers pr
                    WHERE pr.project_id=p.id
                    AND pr.reviewer_id=?
                )',
                [$u['id']]
            ],

            default => [
                'p.owner_id=?',
                [$u['id']]
            ],
        };
    }

    public static function data(array $u): array
    {
        [$scope, $args] = self::scope($u);

        // Estadisticas de proyectos
        $stats = one(
            "SELECT
                COUNT(*) AS projects,
                COALESCE(SUM(p.status='aprobado'),0) AS approved,
                COALESCE(SUM(p.status='en_revision'),0) AS reviewing,
                COALESCE(SUM(p.status='observado'),0) AS observed
             FROM projects p
             WHERE $scope",
            $args
        );

        // Documentos
        $stats['documents'] = (int) (
            one(
                "SELECT COUNT(*) AS n
                 FROM documents d
                 JOIN projects p ON p.id=d.project_id
                 WHERE $scope",
                $args
            )['n']
        );

        // Analisis completados
        $stats['analyses'] = (int) (
            one(
                "SELECT COUNT(*) AS n
                 FROM analyses a
                 JOIN projects p ON p.id=a.project_id
                 WHERE a.status='completado'
                 AND $scope",
                $args
            )['n']
        );

        // Proyectos sin revisor asignado
        $stats['unassigned'] = (int) (
            one(
                "SELECT COUNT(*) AS n
                 FROM projects p
                 WHERE $scope
                 AND p.status NOT IN (
                    'aprobado','archivado'
                 )
                 AND NOT EXISTS (
                    SELECT 1
                    FROM project_reviewers pr
                    JOIN users ru
                      ON ru.id=pr.reviewer_id
                    WHERE ru.active=1
                    AND ru.role='reviewer'
                    AND pr.project_id=p.id
                 )",
                $args
            )['n']
        );

        // Revisiones pendientes
        $reviewScope = $u['role'] === 'reviewer'
            ? ' AND r.user_id=' . (int)$u['id']
            : '';

        $pending = "
            a.status='completado'
            AND p.status NOT IN ('aprobado','archivado')
            AND NOT EXISTS (
                SELECT 1
                FROM reviews r
                WHERE r.analysis_id=a.id
                $reviewScope
            )
        ";

        $stats['pending_reviews'] = (int) (
            one(
                "SELECT COUNT(*) AS n
                 FROM analyses a
                 JOIN projects p ON p.id=a.project_id
                 WHERE $scope AND $pending",
                $args
            )['n']
        );

        // Administradores
        if ($u['role'] === 'admin') {
            $stats['users'] = (int) (
                one(
                    "SELECT COUNT(*) AS n
                     FROM users
                     WHERE active=1"
                )['n']
            );
        }

        // Coordinadores
        if ($u['role'] === 'coordinator') {
            $stats['reviewers'] = (int) (
                one(
                    "SELECT COUNT(*) AS n
                     FROM users
                     WHERE role='reviewer'
                     AND active=1
                     AND career_id=?",
                    [$u['career_id']]
                )['n']
            );
        }

        // Proyectos recientes
        $recent = all(
            "SELECT p.*,c.name AS career
             FROM projects p
             JOIN careers c ON c.id=p.career_id
             WHERE $scope
             ORDER BY p.id DESC
             LIMIT 8",
            $args
        );

        // Cola de revisiones
        $queue = all(
            "SELECT
                a.id,
                a.project_id,
                p.title,
                a.created_at
             FROM analyses a
             JOIN projects p ON p.id=a.project_id
             WHERE $scope AND $pending
             ORDER BY a.created_at,a.id
             LIMIT 8",
            $args
        );

        // Proyectos sin asignar
        $unassigned = [];

        if (
            in_array(
                $u['role'],
                ['admin','coordinator'],
                true
            )
        ) {
            $unassigned = all(
                "SELECT p.id,p.title
                 FROM projects p
                 WHERE $scope
                 AND p.status NOT IN (
                    'aprobado','archivado'
                 )
                 AND NOT EXISTS (
                    SELECT 1
                    FROM project_reviewers pr
                    JOIN users ru
                      ON ru.id=pr.reviewer_id
                    WHERE ru.active=1
                    AND ru.role='reviewer'
                    AND pr.project_id=p.id
                 )
                 ORDER BY p.created_at,p.id
                 LIMIT 8",
                $args
            );
        }

        // Observaciones del estudiante
        $feedback = [];

        if ($u['role'] === 'student') {
            $feedback = all(
                "SELECT
                    r.id,
                    r.analysis_id,
                    r.verdict,
                    r.observations,
                    r.created_at,
                    p.title,
                    u.name AS reviewer
                 FROM reviews r
                 JOIN analyses a ON a.id=r.analysis_id
                 JOIN projects p ON p.id=a.project_id
                 JOIN users u ON u.id=r.user_id
                 WHERE $scope
                 ORDER BY r.id DESC
                 LIMIT 6",
                $args
            );
        }

        // Actividad administrativa
        $activity = [];

        if ($u['role'] === 'admin') {
            $activity = all(
                "SELECT a.*,u.name AS actor
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id=a.user_id
                 ORDER BY a.id DESC
                 LIMIT 6"
            );
        }

        return compact(
            'stats',
            'recent',
            'queue',
            'unassigned',
            'feedback',
            'activity'
        );
    }
}
