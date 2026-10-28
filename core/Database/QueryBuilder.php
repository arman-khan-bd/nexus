<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Closure;

/**
 * Class QueryBuilder
 *
 * SQL-Injection-Proof Fluent Query Builder for Nexus.
 *
 * Guarantees:
 * - 100% Parameterized value bindings on all prepared statements.
 * - Strict regex whitelist and identifier quotation preventing column/table identifier injection.
 * - Operator whitelist defense preventing SQL clause breakout.
 * - Seamless integration with Model hydration and automatic field encryption.
 */
final class QueryBuilder
{
    private PDO $pdo;
    private string $table = '';
    private string $tableAlias = '';
    private array $columns = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $joins = [];
    private array $orders = [];
    private array $groups = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private ?Closure $modelHydrator = null;

    /**
     * Whitelist of permitted comparison operators.
     */
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'REGEXP',
        'IS', 'IS NOT',
    ];

    public function __construct(PDO $pdo, string $table = '')
    {
        $this->pdo = $pdo;
        if ($table !== '') {
            $this->from($table);
        }
    }

    /**
     * Set target table with strict identifier validation.
     */
    public function from(string $table): self
    {
        $table = trim($table);
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i', $table, $matches)) {
            $this->table = $matches[1];
            $this->tableAlias = $matches[2] ?? '';
            return $this;
        }

        throw new InvalidArgumentException("NEXUS SQL DEFENSE: Invalid table identifier format [{$table}].");
    }

    /**
     * Specify columns to select.
     */
    public function select(string|array ...$columns): self
    {
        $this->columns = [];
        $flat = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                $flat = array_merge($flat, $col);
            } else {
                $flat[] = $col;
            }
        }

        foreach ($flat as $column) {
            $this->columns[] = $this->validateAndWrapColumn($column);
        }

        return $this;
    }

    /**
     * Add a WHERE condition to the query.
     */
    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtoupper(trim((string)$operator));
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("NEXUS SQL DEFENSE: Disallowed comparison operator [{$operator}].");
        }

        $wrappedColumn = $this->validateAndWrapColumn($column);
        $placeholder = ':p_' . count($this->bindings);

        if ($value === null && ($operator === '=' || $operator === 'IS')) {
            $this->wheres[] = [
                'type'    => 'raw',
                'sql'     => "{$wrappedColumn} IS NULL",
                'boolean' => $boolean,
            ];
            return $this;
        }

        if ($value === null && ($operator === '!=' || $operator === '<>' || $operator === 'IS NOT')) {
            $this->wheres[] = [
                'type'    => 'raw',
                'sql'     => "{$wrappedColumn} IS NOT NULL",
                'boolean' => $boolean,
            ];
            return $this;
        }

        $this->wheres[] = [
            'type'        => 'basic',
            'column'      => $wrappedColumn,
            'operator'    => $operator,
            'placeholder' => $placeholder,
            'boolean'     => $boolean,
        ];

        $this->bindings[$placeholder] = $value;
        return $this;
    }

    /**
     * Add an OR WHERE condition.
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Add a WHERE NULL condition.
     */
    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $wrapped = $this->validateAndWrapColumn($column);
        $this->wheres[] = [
            'type'    => 'raw',
            'sql'     => "{$wrapped} IS NULL",
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Add a WHERE NOT NULL condition.
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $wrapped = $this->validateAndWrapColumn($column);
        $this->wheres[] = [
            'type'    => 'raw',
            'sql'     => "{$wrapped} IS NOT NULL",
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Add a WHERE IN condition with parameterized bindings.
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        $wrapped = $this->validateAndWrapColumn($column);

        if (empty($values)) {
            $condition = $not ? "1=1" : "0=1";
            $this->wheres[] = ['type' => 'raw', 'sql' => $condition, 'boolean' => $boolean];
            return $this;
        }

        $placeholders = [];
        foreach ($values as $val) {
            $ph = ':p_' . count($this->bindings);
            $placeholders[] = $ph;
            $this->bindings[$ph] = $val;
        }

        $clause = $not ? 'NOT IN' : 'IN';
        $joinedPlaceholders = implode(', ', $placeholders);

        $this->wheres[] = [
            'type'    => 'raw',
            'sql'     => "{$wrapped} {$clause} ({$joinedPlaceholders})",
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN condition.
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a JOIN clause with strict identifier validation.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $table = trim($table);
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i', $table, $matches)) {
            throw new InvalidArgumentException("NEXUS SQL DEFENSE: Invalid join table identifier [{$table}].");
        }

        $wrappedTable = $this->wrapSingle($matches[1]);
        if (!empty($matches[2])) {
            $wrappedTable .= ' AS ' . $this->wrapSingle($matches[2]);
        }

        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("NEXUS SQL DEFENSE: Invalid join operator [{$operator}].");
        }

        $firstWrapped = $this->validateAndWrapColumn($first);
        $secondWrapped = $this->validateAndWrapColumn($second);

        $type = strtoupper(trim($type));
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            $type = 'INNER';
        }

        $this->joins[] = "{$type} JOIN {$wrappedTable} ON {$firstWrapped} {$operator} {$secondWrapped}";
        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $wrapped = $this->validateAndWrapColumn($column);
        $direction = strtoupper(trim($direction));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = 'ASC';
        }

        $this->orders[] = "{$wrapped} {$direction}";
        return $this;
    }

    /**
     * Add a GROUP BY clause.
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->validateAndWrapColumn($column);
        }
        return $this;
    }

    /**
     * Set LIMIT.
     */
    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    /**
     * Set OFFSET.
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Attach a model hydrator callback.
     */
    public function setModelHydrator(Closure $hydrator): self
    {
        $this->modelHydrator = $hydrator;
        return $this;
    }

    /**
     * Execute SELECT query and retrieve all rows.
     */
    public function get(): array
    {
        $sql = $this->toSql();
        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($this->modelHydrator !== null) {
            return array_map($this->modelHydrator, $rows);
        }

        return $rows;
    }

    /**
     * Retrieve the first matching row.
     */
    public function first(): mixed
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    /**
     * Find a record by primary key value.
     */
    public function find(int|string $id, string $primaryKey = 'id'): mixed
    {
        return $this->where($primaryKey, '=', $id)->first();
    }

    /**
     * Retrieve a single column value from the first row.
     */
    public function value(string $column): mixed
    {
        $row = (array)($this->first() ?? []);
        return $row[$column] ?? null;
    }

    /**
     * Retrieve a single column array from all matching rows.
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->get();
        $results = [];
        foreach ($rows as $row) {
            $arr = is_array($row) ? $row : (array)$row;
            if ($key !== null && isset($arr[$key])) {
                $results[$arr[$key]] = $arr[$column] ?? null;
            } else {
                $results[] = $arr[$column] ?? null;
            }
        }
        return $results;
    }

    /**
     * Return row count matching the conditions.
     */
    public function count(): int
    {
        $clone = clone $this;
        $clone->columns = ['COUNT(*) as aggregate'];
        $sql = $clone->toSql();

        $stmt = $clone->pdo->prepare($sql);
        $clone->bindValues($stmt);
        $stmt->execute();

        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($res['aggregate'] ?? 0);
    }

    /**
     * Determine if any matching rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Execute an INSERT statement.
     */
    public function insert(array $values): int
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Cannot execute empty insert.");
        }

        // Support single associative row or multiple rows
        $isMultiple = is_array(reset($values));
        $rows = $isMultiple ? $values : [$values];

        $firstRow = $rows[0];
        $columnNames = array_keys($firstRow);

        $wrappedColumns = [];
        foreach ($columnNames as $col) {
            $wrappedColumns[] = $this->validateAndWrapColumn((string)$col);
        }

        $allPlaceholders = [];
        $bindings = [];
        $counter = 0;

        foreach ($rows as $row) {
            $rowPlaceholders = [];
            foreach ($columnNames as $col) {
                $ph = ':ins_' . $counter++;
                $rowPlaceholders[] = $ph;
                $bindings[$ph] = $row[$col] ?? null;
            }
            $allPlaceholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $table = $this->wrapSingle($this->table);
        $colsSql = implode(', ', $wrappedColumns);
        $valsSql = implode(', ', $allPlaceholders);

        $sql = "INSERT INTO {$table} ({$colsSql}) VALUES {$valsSql}";
        $stmt = $this->pdo->prepare($sql);

        foreach ($bindings as $ph => $val) {
            $stmt->bindValue($ph, $val, $this->getPdoParamType($val));
        }

        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Insert and return the auto-increment ID.
     */
    public function insertGetId(array $values): int
    {
        return $this->insert($values);
    }

    /**
     * Execute an UPDATE statement.
     */
    public function update(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        $setParts = [];
        $updateBindings = [];
        $counter = 0;

        foreach ($values as $col => $val) {
            $wrappedCol = $this->validateAndWrapColumn((string)$col);
            $ph = ':upd_' . $counter++;
            $setParts[] = "{$wrappedCol} = {$ph}";
            $updateBindings[$ph] = $val;
        }

        $setSql = implode(', ', $setParts);
        $table = $this->wrapSingle($this->table);

        $whereSql = $this->compileWheres();
        $sql = "UPDATE {$table} SET {$setSql}" . ($whereSql !== '' ? " WHERE {$whereSql}" : '');

        $stmt = $this->pdo->prepare($sql);

        // Bind update values
        foreach ($updateBindings as $ph => $val) {
            $stmt->bindValue($ph, $val, $this->getPdoParamType($val));
        }

        // Bind where values
        $this->bindValues($stmt);

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Execute a DELETE statement.
     */
    public function delete(): int
    {
        $table = $this->wrapSingle($this->table);
        $whereSql = $this->compileWheres();

        $sql = "DELETE FROM {$table}" . ($whereSql !== '' ? " WHERE {$whereSql}" : '');
        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt);

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Compile builder state into SQL string.
     */
    public function toSql(): string
    {
        $cols = implode(', ', $this->columns);
        $table = $this->wrapSingle($this->table);
        if ($this->tableAlias !== '') {
            $table .= ' AS ' . $this->wrapSingle($this->tableAlias);
        }

        $sql = "SELECT {$cols} FROM {$table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $whereSql = $this->compileWheres();
        if ($whereSql !== '') {
            $sql .= " WHERE {$whereSql}";
        }

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if (!empty($this->orders)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Compile WHERE conditions.
     */
    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $sql = '';
        foreach ($this->wheres as $i => $where) {
            $bool = ($i === 0) ? '' : ' ' . $where['boolean'] . ' ';

            if ($where['type'] === 'basic') {
                $sql .= $bool . "{$where['column']} {$where['operator']} {$where['placeholder']}";
            } elseif ($where['type'] === 'raw') {
                $sql .= $bool . $where['sql'];
            }
        }

        return $sql;
    }

    /**
     * Bind all recorded parameter values into prepared statement.
     */
    private function bindValues(\PDOStatement $stmt): void
    {
        foreach ($this->bindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, $this->getPdoParamType($value));
        }
    }

    /**
     * Determine PDO parameter type.
     */
    private function getPdoParamType(mixed $val): int
    {
        if (is_int($val)) return PDO::PARAM_INT;
        if (is_bool($val)) return PDO::PARAM_BOOL;
        if ($val === null) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    /**
     * Strict identifier validator and quote wrapper.
     */
    public function validateAndWrapColumn(string $column): string
    {
        $column = trim($column);

        // Allow wildcards
        if ($column === '*') {
            return '*';
        }

        // Allow aggregate count(*)
        if (preg_match('/^count\(\*\)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i', $column, $m)) {
            $as = isset($m[1]) ? ' AS ' . $this->wrapSingle($m[1]) : '';
            return 'COUNT(*)' . $as;
        }

        // Match table.column or column, optionally with alias
        $pattern = '/^(?:([a-zA-Z_][a-zA-Z0-9_]*)\.)?([a-zA-Z_][a-zA-Z0-9_]*|\*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i';
        if (preg_match($pattern, $column, $matches)) {
            $table = $matches[1] ?? '';
            $col = $matches[2];
            $alias = $matches[3] ?? '';

            $wrapped = '';
            if ($table !== '') {
                $wrapped .= $this->wrapSingle($table) . '.';
            }
            $wrapped .= ($col === '*') ? '*' : $this->wrapSingle($col);

            if ($alias !== '') {
                $wrapped .= ' AS ' . $this->wrapSingle($alias);
            }

            return $wrapped;
        }

        throw new InvalidArgumentException("NEXUS SQL DEFENSE: Disallowed column identifier format [{$column}]. Identifier injection blocked.");
    }

    /**
     * Wrap identifier using backticks (MySQL) or double quotes (SQLite/Standard).
     */
    private function wrapSingle(string $value): string
    {
        if ($value === '*') {
            return '*';
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $char = ($driver === 'mysql') ? '`' : '"';
        return $char . str_replace($char, '', $value) . $char;
    }

    /**
     * Retrieve current bindings array.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
