<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Database;
use Throwable;

class AuditLogger
{
    private static array $sensitiveKeys = [
        'password', 'password_hash', 'current_password', 'new_password', 
        'confirm_password', 'token', 'csrf_token', 'secret', 'authorization'
    ];

    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): void {
        try {
            $pdo = Database::getConnection();

            if ($userId === null) {
                $user = Session::get('user');
                $userId = $user['id'] ?? null;
            }

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

            $oldClean = $oldValues !== null ? json_encode(self::sanitize($oldValues)) : null;
            $newClean = $newValues !== null ? json_encode(self::sanitize($newValues)) : null;

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
                VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)
            ");

            $stmt->execute([
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldClean,
                'new_values'  => $newClean,
                'ip_address'  => $ip,
                'user_agent'  => $ua
            ]);
        } catch (Throwable $e) {
            // Never break the main business flow if audit logging encounters a problem
            error_log("Audit logging error: " . $e->getMessage());
        }
    }

    private static function sanitize(array $data): array
    {
        $sanitized = [];
        foreach ($data as $k => $v) {
            $lower = strtolower((string)$k);
            if (in_array($lower, self::$sensitiveKeys, true)) {
                $sanitized[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $sanitized[$k] = self::sanitize($v);
            } else {
                $sanitized[$k] = $v;
            }
        }
        return $sanitized;
    }
}
