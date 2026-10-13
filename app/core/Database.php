<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Small PDO wrapper: one shared handle, prepared statements everywhere,
 * Riyadh time zone pinned for NOW()/CURDATE() so date logic can never drift.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $ex) {
            log_fatal('database connection failed: ' . $ex->getMessage());

            throw new RuntimeException(
                'Unable to reach the database. Check the credentials in app/config/config.php '
                . 'and make sure the database exists (php tools/install.php).'
            );
        }

        // Pin the session clock: Saudi Arabia is fixed at UTC+3 (no DST).
        self::$pdo->exec("SET time_zone = '" . APP_TZ_OFFSET . "'");

        return self::$pdo;
    }

    /** Replace the handle (used by the CLI tools and tests). */
    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Run raw SQL without a prepared statement.
     *
     * MySQL refuses to prepare certain statements (TRUNCATE, SET, most DDL),
     * and the seed files are applied this way by the CLI installer too.
     */
    public static function exec(string $sql): int
    {
        return (int)self::pdo()->exec($sql);
    }

    /**
     * Split a schema/seed SQL file into individual statements.
     *
     * The project's SQL files keep semicolons out of string literals, so a
     * newline-terminated split is enough - and it lets the app reuse the exact
     * files tools/install.php applies.
     *
     * @return array<int, string>
     */
    public static function statementsFromFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing SQL file: ' . $path);
        }

        $raw = (string)file_get_contents($path);
        $raw = (string)preg_replace('/^\s*--.*$/m', '', $raw); // strip -- comments

        $statements = [];

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $raw) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        return $statements;
    }

    /** Fetch a single row, or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Fetch a single scalar column of the first row. */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** INSERT a row and return the new primary key. */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql     = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';

        self::run($sql, array_values($data));

        return (int)self::pdo()->lastInsertId();
    }

    /**
     * UPDATE a row. $where is raw SQL such as "id = ?".
     * Returns the number of affected rows.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = '`' . $column . '` = ?';
        }

        $stmt = self::run(
            'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            array_merge(array_values($data), $whereParams)
        );

        return $stmt->rowCount();
    }

    /** Run $fn inside a transaction; rolls back on any throwable. */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    /** True when at least one row matches. */
    public static function exists(string $sql, array $params = []): bool
    {
        return self::scalar($sql, $params) !== null;
    }
}
