<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Append-only audit trail; used for every financial and security-relevant change. */
final class Audit
{
    public static function log(string $action, string $entityType, int|string|null $entityId = null, ?array $old = null, ?array $new = null): void
    {
        try {
            Database::insert('audit_logs', [
                'user_id'     => Auth::id(),
                'action'      => mb_substr($action, 0, 60),
                'entity_type' => mb_substr($entityType, 0, 60),
                'entity_id'   => $entityId === null ? null : mb_substr((string) $entityId, 0, 40),
                'old_values'  => $old === null ? null : json_encode(self::clean($old), JSON_UNESCAPED_UNICODE),
                'new_values'  => $new === null ? null : json_encode(self::clean($new), JSON_UNESCAPED_UNICODE),
                'ip_address'  => Request::ip(),
                'user_agent'  => Request::userAgent(),
            ]);
        } catch (Throwable $e) {
            error_log('Audit log failure: ' . $e->getMessage());
            // Inside a financial transaction, a failed audit write must abort the change.
            if (Database::pdo()->inTransaction()) {
                throw $e;
            }
        }
    }

    /** Never store secrets in the audit trail. */
    private static function clean(array $data): array
    {
        foreach (['password', 'password_hash', 'token', 'token_hash', 'csrf'] as $k) {
            unset($data[$k]);
        }
        return $data;
    }

    /** Fields that differ between two rows (for concise update logs). */
    public static function diff(array $old, array $new): array
    {
        $o = [];
        $n = [];
        foreach ($new as $k => $v) {
            $ov = $old[$k] ?? null;
            if ((string) $ov !== (string) $v) {
                $o[$k] = $ov;
                $n[$k] = $v;
            }
        }
        return [$o, $n];
    }
}
