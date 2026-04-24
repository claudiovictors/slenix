<?php

/*
 |--------------------------------------------------------------------------
 | SLENIX COLLECTION - Laravel-style model collection
 |--------------------------------------------------------------------------
 |
 | Enables method chaining after queries, providing data manipulation
 | functionality similar to Laravel Collection.
 |
 */

declare(strict_types=1);

namespace Slenix\Database;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array Collection items */
    protected array $items = [];

    /**
     * @param array $items Initial items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /**
     * Filters items by column and value (equivalent to Eloquent Collection's where)
     */
    public function where(string $column, $operatorOrValue = null, $value = null): self
    {
        // Supports 2 or 3 arguments
        if ($value === null) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        return $this->filter(function ($item) use ($column, $operator, $value) {
            $itemValue = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);

            return match ($operator) {
                '='  , '=='  => $itemValue == $value,
                '!=' , '<>'  => $itemValue != $value,
                '>'          => $itemValue > $value,
                '>='         => $itemValue >= $value,
                '<'          => $itemValue < $value,
                '<='         => $itemValue <= $value,
                'like'       => str_contains((string) $itemValue, str_replace('%', '', (string) $value)),
                default      => $itemValue == $value,
            };
        });
    }

    /**
     * Filters items where the column value is in the array
     *
     * @example $collection->whereIn('status', ['active', 'pending'])
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->filter(function ($item) use ($column, $values) {
            $itemValue = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return in_array($itemValue, $values);
        });
    }

    /**
     * Filters items where the column value is NOT in the array
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->filter(function ($item) use ($column, $values) {
            $itemValue = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return !in_array($itemValue, $values);
        });
    }

    /**
     * Filters items where the column is null
     */
    public function whereNull(string $column): self
    {
        return $this->filter(function ($item) use ($column) {
            $val = is_array($item) ? ($item[$column] ?? 'NOT_SET') : ($item->$column ?? 'NOT_SET');
            return $val === null;
        });
    }

    /**
     * Filters items where the column is not null
     */
    public function whereNotNull(string $column): self
    {
        return $this->filter(function ($item) use ($column) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return $val !== null;
        });
    }

    /**
     * Filters items where the column value is between two values
     */
    public function whereBetween(string $column, $min, $max): self
    {
        return $this->filter(function ($item) use ($column, $min, $max) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return $val >= $min && $val <= $max;
        });
    }

    /**
     * Returns the first item matching an optional condition
     *
     * @example $collection->first()
     * @example $collection->first(fn($u) => $u->active)
     */
    public function first(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return $this->items[0] ?? $default;
        }

        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * Returns the last item
     */
    public function last(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return !empty($this->items) ? end($this->items) : $default;
        }

        $filtered = array_filter($this->items, $callback);
        return !empty($filtered) ? end($filtered) : $default;
    }

    /**
     * Returns the item at the specified index
     */
    public function nth(int $index, $default = null)
    {
        return $this->items[$index] ?? $default;
    }

    /**
     * Checks whether the collection contains an item matching the criteria
     *
     * @example $collection->contains('active', true)
     * @example $collection->contains(fn($u) => $u->admin)
     */
    public function contains($keyOrCallback, $value = null): bool
    {
        if (is_callable($keyOrCallback)) {
            foreach ($this->items as $item) {
                if ($keyOrCallback($item)) return true;
            }
            return false;
        }

        return $this->where($keyOrCallback, $value)->isNotEmpty();
    }

    /**
     * Alias for !contains
     */
    public function doesntContain($keyOrCallback, $value = null): bool
    {
        return !$this->contains($keyOrCallback, $value);
    }

    /**
     * Finds an item by primary key
     */
    public function find($id, string $keyColumn = 'id')
    {
        return $this->first(fn($item) => ($item->$keyColumn ?? null) == $id);
    }

    /**
     * Applies a callback to each item and returns a new collection
     *
     * @example $collection->map(fn($u) => $u->name)
     */
    public function map(callable $callback): self
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Applies a callback to each item (without returning a new collection)
     *
     * @example $collection->each(fn($u) => $u->save())
     */
    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    /**
     * Filters items using a callback
     *
     * @example $collection->filter(fn($u) => $u->active)
     */
    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }

        return new static(array_values(array_filter($this->items, $callback)));
    }

    /**
     * Rejects items that satisfy the criteria (inverse of filter)
     */
    public function reject(callable $callback): self
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    /**
     * Returns only the values of a column
     *
     * @example $collection->pluck('name')
     * @example $collection->pluck('name', 'id')
     */
    public function pluck(string $column, ?string $keyBy = null): self
    {
        $result = [];
        foreach ($this->items as $item) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            if ($keyBy !== null) {
                $key = is_array($item) ? ($item[$keyBy] ?? null) : ($item->$keyBy ?? null);
                $result[$key] = $val;
            } else {
                $result[] = $val;
            }
        }
        return new static($result);
    }

    /**
     * Sorts the collection by column
     *
     * @example $collection->sortBy('name')
     * @example $collection->sortBy('created_at', 'desc')
     */
    public function sortBy(string $column, string $direction = 'asc'): self
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($column, $direction) {
            $aVal = is_array($a) ? ($a[$column] ?? null) : ($a->$column ?? null);
            $bVal = is_array($b) ? ($b[$column] ?? null) : ($b->$column ?? null);

            $result = $aVal <=> $bVal;
            return strtolower($direction) === 'desc' ? -$result : $result;
        });

        return new static($items);
    }

    /**
     * Sorts in descending order
     */
    public function sortByDesc(string $column): self
    {
        return $this->sortBy($column, 'desc');
    }

    /**
     * Sorts using a custom callback
     */
    public function sortUsing(callable $callback): self
    {
        $items = $this->items;
        usort($items, $callback);
        return new static($items);
    }

    /**
     * Groups items by column
     *
     * @example $collection->groupBy('status') // returns ['active' => [...], 'inactive' => [...]]
     */
    public function groupBy(string $column): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $key = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            $groups[$key][] = $item;
        }

        // Convert each group to a Collection
        return array_map(fn($group) => new static($group), $groups);
    }

    /**
     * Returns unique items by column or identity
     *
     * @example $collection->unique('email')
     */
    public function unique(?string $column = null): self
    {
        if ($column === null) {
            return new static(array_unique($this->items));
        }

        $seen = [];
        return $this->filter(function ($item) use ($column, &$seen) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            if (in_array($val, $seen, true)) {
                return false;
            }
            $seen[] = $val;
            return true;
        });
    }

    /**
     * Performs a flat map (map + flatten)
     */
    public function flatMap(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $item) {
            $mapped = $callback($item);
            if (is_array($mapped)) {
                array_push($result, ...$mapped);
            } elseif ($mapped instanceof self) {
                array_push($result, ...$mapped->all());
            } else {
                $result[] = $mapped;
            }
        }
        return new static($result);
    }

    /**
     * Applies a callback without modifying the collection (for debug/side-effects)
     */
    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    /**
     * Applies a callback that may transform the entire collection
     */
    public function pipe(callable $callback)
    {
        return $callback($this);
    }

    /**
     * Passes each item through a callback and returns the original collection (unmodified)
     */
    public function tapEach(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }
        return $this;
    }

    /**
     * Returns the first N items
     */
    public function take(int $limit): self
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Skips the first N items
     */
    public function skip(int $offset): self
    {
        return new static(array_slice($this->items, $offset));
    }

    /**
     * Returns a slice of the collection
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Splits the collection into chunks
     *
     * @example $collection->chunk(3) // returns [Collection, Collection, ...]
     */
    public function chunk(int $size): array
    {
        return array_map(
            fn($chunk) => new static($chunk),
            array_chunk($this->items, $size)
        );
    }

    /**
     * Processes the collection in chunks (without holding all in memory)
     *
     * @example $collection->chunkEach(100, fn($chunk) => ...)
     */
    public function chunkEach(int $size, callable $callback): void
    {
        foreach (array_chunk($this->items, $size) as $chunk) {
            if ($callback(new static($chunk)) === false) {
                break;
            }
        }
    }

    /**
     * Shuffles the items
     */
    public function shuffle(): self
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    /**
     * Reverses the order of items
     */
    public function reverse(): self
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Returns random items
     */
    public function random(int $count = 1): self
    {
        $shuffled = $this->shuffle();
        return $count === 1 ? $shuffled->take(1) : $shuffled->take($count);
    }

    /**
     * Counts the items
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Sums the values of a column
     * @param mixed $column
     * @return float
     */
    public function sum(?string $column = null): float
    {
        if ($column === null) {
            return (float) array_sum($this->items);
        }

        return (float) array_sum(array_map(function ($item) use ($column) {
            return is_array($item) ? ($item[$column] ?? 0) : ($item->$column ?? 0);
        }, $this->items));
    }

    /**
     * Calculates the average of a column's values
     * @param mixed $column
     * @return float|int
     */
    public function avg(?string $column = null): float
    {
        if ($this->isEmpty()) return 0.0;
        return $this->sum($column) / $this->count();
    }

    /**
     * Returns the minimum value of a column
     * @param mixed $column
     */
    public function min(?string $column = null)
    {
        if ($column === null) {
            return min($this->items);
        }

        return min(array_map(fn($item) => is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null), $this->items));
    }

    /**
     * Returns the maximum value of a column
     * @param mixed $column
     */
    public function max(?string $column = null)
    {
        if ($column === null) {
            return max($this->items);
        }

        return max(array_map(fn($item) => is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null), $this->items));
    }

    /**
     * Reduces the collection to a single value
     * @param callable $callback
     * @param mixed $carry
     */
    public function reduce(callable $callback, $carry = null)
    {
        return array_reduce($this->items, $callback, $carry);
    }

    /**
     * Checks whether the collection is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Checks whether the collection is NOT empty
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Checks whether all items satisfy the criteria
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if (!$callback($item)) return false;
        }
        return true;
    }

    /**
     * Checks whether any item satisfies the criteria
     */
    public function some(callable $callback): bool
    {
        return $this->contains($callback);
    }

    /**
     * Returns all items as an array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Converts to an array of arrays (using toArray() on each model)
     */
    public function toArray(): array
    {
        return array_map(function ($item) {
            if (method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return (array) $item;
        }, $this->items);
    }

    /**
     * Converts to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * For json_encode()
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Converts to a flat array of column values
     */
    public function toFlatArray(string $column): array
    {
        return $this->pluck($column)->all();
    }

    /**
     * Converts the collection to a keyed array
     */
    public function keyBy(string $column): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $key = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            $result[$key] = $item;
        }
        return $result;
    }

    /**
     * Merges collections
     */
    public function merge(self|array $other): self
    {
        $items = $other instanceof self ? $other->all() : $other;
        return new static(array_merge($this->items, $items));
    }

    /**
     * Combines with another collection removing duplicates by column
     */
    public function union(self|array $other, ?string $uniqueBy = null): self
    {
        return $this->merge($other)->unique($uniqueBy);
    }

    /**
     * Returns only items that exist in both collections
     */
    public function intersect(self|array $other): self
    {
        $otherArray = $other instanceof self ? $other->all() : $other;
        return new static(array_values(array_intersect($this->items, $otherArray)));
    }

    /**
     * Returns items that do not exist in the other collection
     */
    public function diff(self|array $other): self
    {
        $otherArray = $other instanceof self ? $other->all() : $other;
        return new static(array_values(array_diff($this->items, $otherArray)));
    }

    /**
     * Determine if an item exists at a given offset.
     * * Implementation of the ArrayAccess interface.
     *
     * @param mixed $offset The key to check.
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Get the item at a given offset.
     * * Implementation of the ArrayAccess interface.
     *
     * @param mixed $offset The key to retrieve.
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * Set the item at a given offset.
     * * Implementation of the ArrayAccess interface.
     *
     * @param mixed $offset The key to set or null to append to the array.
     * @param mixed $value The value to store.
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     * * Implementation of the ArrayAccess interface.
     *
     * @param mixed $offset The key to remove.
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Get an iterator for the items.
     * * Implementation of the IteratorAggregate interface.
     *
     * @return ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Debug helper
     */
    public function dd(): void
    {
        var_dump($this->toArray());
        exit;
    }

    /**
     * Debug helper (without exit)
     */
    public function dump(): self
    {
        var_dump($this->toArray());
        return $this;
    }
}