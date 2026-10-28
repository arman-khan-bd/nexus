<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Env;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Class DB
 *
 * Hardened Database Connection Manager & Gateway for Nexus.
 *
 * Security Architecture:
 * - Forces native server-side prepared statements (ATTR_EMULATE_PREPARES = false)
 * - Strict exception reporting (ATTR_ERRMODE = ERRMODE_EXCEPTION)
 * - Associative fetching only (ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC)
 * - Native transaction lifecycle with automatic rollbacks
 */
final class DB
{
    private static ?PDO $pdo = null;

    /**
     * Singleton PDO connection factory with hardened security attributes.
     */
    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = Env::string('DB_CONNECTION', 'mysql');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // SECURITY CRITICAL: Disables client-side emulation, forcing native server-side prepared statements
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $database = Env::string('DB_DATABASE', ':memory:');
                $dsn = "sqlite:{$database}";
                self::$pdo = new PDO($dsn, null, null, $options);
            } else {
                // Default: MySQL for cPanel hosting
                $host = Env::string('DB_HOST', '127.0.0.1');
                $port = Env::int('DB_PORT', 3306);
                $database = Env::string('DB_DATABASE', '');
                $username = Env::string('DB_USERNAME', 'root');
                $password = Env::string('DB_PASSWORD', '');
                $charset = Env::string('DB_CHARSET', 'utf8mb4');
                $collation = Env::string('DB_COLLATION', 'utf8mb4_unicode_ci');

                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

                // MySQL specific hardening: utf8mb4 collation command
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$charset}' COLLATE '{$collation}'";
                }

                self::$pdo = new PDO($dsn, $username, $password, $options);
            }
        } catch (PDOException $e) {
            // Never leak raw database connection credentials in exceptions!
            throw new RuntimeException("Database Connection Error: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        return self::$pdo;
    }

    /**
     * Set explicit PDO instance (ideal for in-memory testing or custom connections).
     */
    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Initiate a fluent query builder on a table.
     */
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(self::connection(), $table);
    }

    /**
     * Execute a parameterized SELECT query.
     */
    public static function select(string $query, array $bindings = []): array
    {
        $stmt = self::connection()->prepare($query);
        self::bindValues($stmt, $bindings);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute a parameterized INSERT query and return last insert ID.
     */
    public static function insert(string $query, array $bindings = []): int
    {
        $pdo = self::connection();
        $stmt = $pdo->prepare($query);
        self::bindValues($stmt, $bindings);
        $stmt->execute();
        return (int)$pdo->lastInsertId();
    }

    /**
     * Execute a parameterized UPDATE query and return affected rows.
     */
    public static function update(string $query, array $bindings = []): int
    {
        $stmt = self::connection()->prepare($query);
        self::bindValues($stmt, $bindings);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Execute a parameterized DELETE query and return affected rows.
     */
    public static function delete(string $query, array $bindings = []): int
    {
        $stmt = self::connection()->prepare($query);
        self::bindValues($stmt, $bindings);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Execute an arbitrary parameterized statement (e.g. DDL / schema operations).
     */
    public static function statement(string $query, array $bindings = []): bool
    {
        $stmt = self::connection()->prepare($query);
        self::bindValues($stmt, $bindings);
        return $stmt->execute();
    }

    /**
     * Begin a database transaction.
     */
    public static function beginTransaction(): bool
    {
        return self::connection()->beginTransaction();
    }

    /**
     * Commit active database transaction.
     */
    public static function commit(): bool
    {
        return self::connection()->commit();
    }

    /**
     * Rollback active database transaction.
     */
    public static function rollBack(): bool
    {
        return self::connection()->rollBack();
    }

    /**
     * Execute a callback wrapped in an atomic database transaction.
     * Automatically commits on success, and rolls back on any exception.
     *
     * @throws Throwable
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        try {
            $result = $callback(self::connection());
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    /**
     * Close the connection.
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /**
     * Bind parameter values into prepared statement.
     */
    private static function bindValues(\PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $paramType = PDO::PARAM_STR;
            if (is_int($value)) $paramType = PDO::PARAM_INT;
            elseif (is_bool($value)) $paramType = PDO::PARAM_BOOL;
            elseif ($value === null) $paramType = PDO::PARAM_NULL;

            if (is_int($key)) {
                // 1-indexed for positional ? placeholders
                $stmt->bindValue($key + 1, $value, $paramType);
            } else {
                $placeholder = str_starts_with($key, ':') ? $key : ":{$key}";
                $stmt->bindValue($placeholder, $value, $paramType);
            }
        }
    }
}
