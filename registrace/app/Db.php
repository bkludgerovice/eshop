<?php
declare(strict_types=1);

namespace Bkl;

use PDO;
use PDOStatement;

/** Tenká vrstva nad PDO – vždy připravené dotazy (ochrana proti SQL injection). */
final class Db
{
    public PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function fromConfig(Config $c): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c->str('db.host', 'localhost'),
            (int) $c->get('db.port', 3306),
            $c->str('db.name')
        );
        $pdo = new PDO($dsn, $c->str('db.user'), $c->str('db.password'), [
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci',
        ]);
        $db = new self($pdo);
        // Časy v DB jsou místní čas Europe/Prague (DATETIME bez zóny) – i výchozí CURRENT_TIMESTAMP.
        $offset = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('P');
        $db->pdo->exec("SET time_zone = '" . $offset . "'");
        $db->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        return $db;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_BOOL : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $st->bindValue($key, is_bool($v) ? (int) $v : $v, $type === PDO::PARAM_BOOL ? PDO::PARAM_INT : $type);
        }
        $st->execute();
        return $st;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
        $this->run($sql, $row);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $where */
    public function update(string $table, array $row, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($row as $k => $v) {
            $set[] = "`$k` = :s_$k";
            $params["s_$k"] = $v;
        }
        $w = [];
        foreach ($where as $k => $v) {
            $w[] = "`$k` = :w_$k";
            $params["w_$k"] = $v;
        }
        return $this->run('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w), $params)->rowCount();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn(); // vnořené volání běží v rámci vnější transakce
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
