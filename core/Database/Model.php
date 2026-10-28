<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Security\Crypto;
use JsonSerializable;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class Model
 *
 * Lightweight Active Record ORM for Nexus with Transparent At-Rest Encryption.
 *
 * Capabilities:
 * - Transparent 256-bit Libsodium/AES encryption on fields declared in $encrypt
 * - Mass-assignment protection via $fillable and $guarded
 * - Hidden field masking via $hidden (for JSON/Array serialization)
 * - Fluent delegation to QueryBuilder
 */
abstract class Model implements JsonSerializable
{
    /**
     * Database table name. Inferred automatically if empty.
     */
    protected string $table = '';

    /**
     * Primary key column.
     */
    protected string $primaryKey = 'id';

    /**
     * Mass assignable attributes.
     *
     * @var array<string>
     */
    protected array $fillable = [];

    /**
     * Guarded attributes.
     *
     * @var array<string>
     */
    protected array $guarded = ['id'];

    /**
     * Attributes automatically encrypted at rest using Libsodium/AES.
     *
     * @var array<string>
     */
    protected array $encrypt = [];

    /**
     * Attributes hidden from array / JSON output.
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * Model attribute storage.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Original attributes prior to modifications.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Whether this model represents an existing database record.
     */
    protected bool $exists = false;

    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->exists = $exists;
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

    /**
     * Create a new query builder instance for this model.
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        $builder = DB::table($instance->getTable());

        $builder->setModelHydrator(function (array $row) {
            return static::newFromBuilder($row);
        });

        return $builder;
    }

    /**
     * Hydrate a new model instance from raw database attributes.
     */
    public static function newFromBuilder(array $row): static
    {
        $instance = new static([], true);
        $instance->attributes = $row;
        $instance->original = $row;
        return $instance;
    }

    /**
     * Retrieve all records from the table.
     *
     * @return array<static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Find a record by its primary key.
     */
    public static function find(int|string $id): ?static
    {
        $instance = new static();
        return static::query()->where($instance->getKeyName(), '=', $id)->first();
    }

    /**
     * Query records matching where criteria.
     */
    public static function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Create and persist a new model instance.
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Populate model with an array of attributes respecting mass assignment guards.
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Save the model to the database (INSERT or UPDATE).
     */
    public function save(): bool
    {
        $table = $this->getTable();
        $keyName = $this->getKeyName();

        // Prepare attributes, applying transparent at-rest encryption
        $payload = $this->prepareAttributesForStorage();

        if ($this->exists) {
            $id = $this->attributes[$keyName] ?? null;
            if ($id === null) {
                throw new RuntimeException("Cannot update model without primary key value.");
            }

            unset($payload[$keyName]); // Don't update primary key
            $affected = DB::table($table)->where($keyName, '=', $id)->update($payload);
            $this->original = $this->attributes;
            return $affected >= 0;
        }

        // Insert new record
        $id = DB::table($table)->insertGetId($payload);
        if ($id > 0 && !isset($this->attributes[$keyName])) {
            $this->attributes[$keyName] = $id;
        }

        $this->exists = true;
        $this->original = $this->attributes;
        return true;
    }

    /**
     * Update model attributes and persist.
     */
    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    /**
     * Delete model from database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $keyName = $this->getKeyName();
        $id = $this->attributes[$keyName] ?? null;
        if ($id === null) {
            return false;
        }

        $deleted = DB::table($this->getTable())->where($keyName, '=', $id)->delete();
        $this->exists = false;
        return $deleted > 0;
    }

    /**
     * Transform attributes for database writing, encrypting fields declared in $encrypt.
     */
    protected function prepareAttributesForStorage(): array
    {
        $payload = $this->attributes;

        foreach ($this->encrypt as $column) {
            if (isset($payload[$column]) && $payload[$column] !== null && $payload[$column] !== '') {
                $rawVal = (string)$payload[$column];

                // If not already encrypted, encrypt with authenticated Libsodium/AES cipher
                if (!str_starts_with($rawVal, 'v1:sodium:') && !str_starts_with($rawVal, 'v1:gcm:')) {
                    $payload[$column] = Crypto::encrypt($rawVal);
                }
            }
        }

        return $payload;
    }

    /**
     * Get an attribute value, automatically decrypting fields in $encrypt.
     */
    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        $val = $this->attributes[$key];

        // Transparent Decryption for sensitive fields
        if (in_array($key, $this->encrypt, true) && is_string($val) && $val !== '') {
            if (str_starts_with($val, 'v1:sodium:') || str_starts_with($val, 'v1:gcm:')) {
                try {
                    return Crypto::decrypt($val);
                } catch (\Throwable) {
                    return $val;
                }
            }
        }

        return $val;
    }

    /**
     * Set an attribute value.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Magic getter for attributes.
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Magic setter for attributes.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Check if an attribute is mass assignable.
     */
    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded, true)) {
            return false;
        }

        if (empty($this->fillable)) {
            return true;
        }

        return in_array($key, $this->fillable, true);
    }

    /**
     * Get table name (infer from class name if not explicitly set).
     */
    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        $shortClass = basename(str_replace('\\', '/', static::class));
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortClass));
        return $this->table = $snake . 's';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Convert model attributes to an array, decrypting sensitive fields
     * and omitting hidden columns.
     */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->attributes as $key => $val) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }
            $data[$key] = $this->getAttribute($key);
        }
        return $data;
    }

    /**
     * Convert model to JSON string.
     */
    public function toJson(int $options = 0): string
    {
        return (string)json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
