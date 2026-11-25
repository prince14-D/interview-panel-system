<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class AuditLogger
{
    public static function log(
        PDO $pdo,
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $details = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip, :ua)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'), 0, 250),
        ]);
    }
}
