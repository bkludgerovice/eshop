<?php
declare(strict_types=1);

namespace Bkl;

/** Auditní log (kdo co změnil) a systémový log chyb. Nikdy do logu nepiš hesla ani tokeny. */
final class Log
{
    public function __construct(private Db $db)
    {
    }

    public function audit(string $entity, ?int $id, string $action, mixed $old, mixed $new, string $actor): void
    {
        $this->db->insert('audit_log', [
            'entity' => $entity,
            'entity_id' => $id,
            'action' => $action,
            'old_value' => $old === null ? null : Util::json($old),
            'new_value' => $new === null ? null : Util::json($new),
            'actor' => mb_substr($actor, 0, 120),
        ]);
    }

    /** @param array<string,mixed> $context */
    public function system(string $level, string $source, string $message, array $context = []): void
    {
        try {
            $this->db->insert('system_log', [
                'level' => $level,
                'source' => $source,
                'message' => mb_substr(self::scrub($message), 0, 2000),
                'context' => $context ? Util::json(self::scrubArr($context)) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[BKL] ' . $level . ' ' . $source . ': ' . $message);
        }
    }

    public static function scrub(string $s): string
    {
        // tokeny Fio v URL a dlouhé tajné řetězce
        $s = preg_replace('#(/rest/[a-z-]+/)[A-Za-z0-9]{20,}#', '$1***', $s) ?? $s;
        return preg_replace('/(password|heslo|token|secret|key)=\S+/i', '$1=***', $s) ?? $s;
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private static function scrubArr(array $a): array
    {
        foreach ($a as $k => $v) {
            if (preg_match('/pass|token|secret|key/i', (string) $k)) {
                $a[$k] = '***';
            } elseif (is_string($v)) {
                $a[$k] = self::scrub($v);
            } elseif (is_array($v)) {
                $a[$k] = self::scrubArr($v);
            }
        }
        return $a;
    }
}
