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
                '=', '==' => $itemValue == $value,
                '!=', '<>' => $itemValue != $value,
                '>' => $itemValue > $value,
                '>=' => $itemValue >= $value,
                '<' => $itemValue < $value,
                '<=' => $itemValue <= $value,
                'like' => str_contains((string) $itemValue, str_replace('%', '', (string) $value)),
                default => $itemValue == $value,
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
                if ($keyOrCallback($item))
                    return true;
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
        if ($this->isEmpty())
            return 0.0;
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
            if (!$callback($item))
                return false;
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
     * Flattens a multi-dimensional collection into a single level.
     * @param int $depth
     * @return Collection
     * @example $collection->flatten()
     */
    public function flatten(int $depth): self
    {
        $result = [];

        $flatten = function (array $items, int $currentDepth) use (&$flatten, &$result, $depth) {
            foreach ($items as $item) {
                if (is_array($item) && $currentDepth < $depth) {
                    $flatten($item, $currentDepth + 1);
                } elseif ($item instanceof self && $currentDepth < $depth) {
                    $flatten($item->all(), $currentDepth + 1);
                } else {
                    $result[] = $item;
                }
            }
        };

        $flatten($this->items, 0);

        return new static($result);
    }

    /**
     * Maps the collection and flattens the result by one level.
     * Alias for flatMap with auto-flatten.
     *
     * @example $collection->mapFlat(fn($u) => [$u->first_name, $u->last_name])
     */
    public function mapFlat(callable $callback): self
    {
        return $this->flatMap($callback)->flatten(1);
    }

    /**
     * Maps over items and replaces them with key => value pairs.
     * The callback must return an associative array with one key-value pair.
     *
     * @example $collection->mapWithKeys(fn($u) => [$u->id => $u->name])
     */
    public function mapWithKeys(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $item) {
            $assoc = $callback($item);
            foreach ($assoc as $key => $value) {
                $result[$key] = $value;
            }
        }
        return new static($result);
    }

    /**
     * Applies a callback to each item and returns a new collection,
     * filtering out null results automatically.
     *
     * @example $collection->mapFilter(fn($u) => $u->active ? $u->name : null)
     */
    public function mapFilter(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $item) {
            $mapped = $callback($item);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }
        return new static($result);
    }

    /**
     * Zips the collection with one or more arrays/collections.
     * Returns a collection of arrays, each containing items at the same position.
     *
     * @example $collection->zip([1, 2, 3]) // [[item1, 1], [item2, 2], ...]
     */
    public function zip(array|self ...$others): self
    {
        $arrays = array_map(fn($o) => $o instanceof self ? $o->all() : $o, $others);
        array_unshift($arrays, $this->items);

        return new static(array_map(null, ...$arrays));
    }

    /**
     * Combines the collection's values as keys with the given array as values.
     *
     * @param array|self $values Values to combine with
     *
     * @example $collection->combine(['a', 'b']) // ['key1' => 'a', 'key2' => 'b']
     */
    public function combine(array|self $values): self
    {
        $values = $values instanceof self ? $values->all() : $values;
        return new static(array_combine($this->items, $values));
    }

    /**
     * Collapses a collection of arrays into a single flat collection.
     *
     * @example $collection->collapse() // [[1,2],[3,4]] → [1,2,3,4]
     */
    public function collapse(): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                array_push($result, ...$item);
            } elseif ($item instanceof self) {
                array_push($result, ...$item->all());
            } else {
                $result[] = $item;
            }
        }
        return new static($result);
    }

    /**
     * Transposes a multi-dimensional collection (rows become columns).
     *
     * @example $collection->transpose() // [[1,2],[3,4]] → [[1,3],[2,4]]
     */
    public function transpose(): self
    {
        if ($this->isEmpty()) {
            return new static([]);
        }

        $first = $this->items[0];
        $keys = is_array($first) ? array_keys($first) : range(0, count((array) $first) - 1);

        $transposed = [];
        foreach ($keys as $key) {
            $transposed[] = array_map(function ($item) use ($key) {
                return is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            }, $this->items);
        }

        return new static($transposed);
    }

    /**
     * Pads the collection to the given length with a value.
     * Positive length pads at the end, negative at the beginning.
     *
     * @example $collection->pad(5, null)
     */
    public function pad(int $size, mixed $value = null): self
    {
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * Returns a new collection with the item prepended.
     *
     * @example $collection->prepend($user)
     */
    public function prepend(mixed $value, mixed $key = null): self
    {
        $items = $this->items;

        if ($key !== null) {
            $items = [$key => $value] + $items;
        } else {
            array_unshift($items, $value);
        }

        return new static($items);
    }

    /**
     * Returns a new collection with the item appended.
     *
     * @example $collection->append($user)
     */
    public function append(mixed $value): self
    {
        $items = $this->items;
        $items[] = $value;
        return new static($items);
    }

    /**
     * Replaces items in the collection with the given items.
     *
     * @example $collection->replace([0 => $newUser])
     */
    public function replace(array|self $items): self
    {
        $replacements = $items instanceof self ? $items->all() : $items;
        return new static(array_replace($this->items, $replacements));
    }

    /**
     * Filters items where the column value matches a regex pattern.
     *
     * @example $collection->whereMatch('email', '/@gmail\.com$/')
     */
    public function whereMatch(string $column, string $pattern): self
    {
        return $this->filter(function ($item) use ($column, $pattern) {
            $val = is_array($item) ? ($item[$column] ?? '') : ($item->$column ?? '');
            return (bool) preg_match($pattern, (string) $val);
        });
    }

    /**
     * Filters items where the column starts with the given string.
     *
     * @example $collection->whereStartsWith('name', 'Jo')
     */
    public function whereStartsWith(string $column, string $prefix): self
    {
        return $this->filter(function ($item) use ($column, $prefix) {
            $val = is_array($item) ? ($item[$column] ?? '') : ($item->$column ?? '');
            return str_starts_with((string) $val, $prefix);
        });
    }

    /**
     * Filters items where the column ends with the given string.
     *
     * @example $collection->whereEndsWith('email', '.com')
     */
    public function whereEndsWith(string $column, string $suffix): self
    {
        return $this->filter(function ($item) use ($column, $suffix) {
            $val = is_array($item) ? ($item[$column] ?? '') : ($item->$column ?? '');
            return str_ends_with((string) $val, $suffix);
        });
    }

    /**
     * Returns items that pass the truth test based on multiple conditions (AND logic).
     * Each condition is [column, operator, value] or [column, value].
     *
     * @example $collection->whereAll([['active', true], ['age', '>=', 18]])
     */
    public function whereAll(array $conditions): self
    {
        return $this->filter(function ($item) use ($conditions) {
            foreach ($conditions as $condition) {
                [$column, $operatorOrValue, $value] = array_pad($condition, 3, null);
                $subset = (new static([$item]))->where($column, $operatorOrValue, $value);
                if ($subset->isEmpty()) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Returns items that pass at least one condition (OR logic).
     *
     * @example $collection->whereAny([['role', 'admin'], ['role', 'moderator']])
     */
    public function whereAny(array $conditions): self
    {
        return $this->filter(function ($item) use ($conditions) {
            foreach ($conditions as $condition) {
                [$column, $operatorOrValue, $value] = array_pad($condition, 3, null);
                $subset = (new static([$item]))->where($column, $operatorOrValue, $value);
                if ($subset->isNotEmpty()) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Searches for an item and returns its index, or -1 if not found.
     *
     * @example $collection->search(fn($u) => $u->id === 5)
     * @example $collection->search('email', 'john@example.com')
     */
    public function search(callable|string $callbackOrColumn, mixed $value = null): int
    {
        if (is_callable($callbackOrColumn)) {
            foreach ($this->items as $index => $item) {
                if ($callbackOrColumn($item)) {
                    return $index;
                }
            }
            return -1;
        }

        foreach ($this->items as $index => $item) {
            $val = is_array($item) ? ($item[$callbackOrColumn] ?? null) : ($item->$callbackOrColumn ?? null);
            if ($val == $value) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Returns the index of the first matching item (alias for search with callback).
     *
     * @example $collection->indexOf(fn($u) => $u->admin)
     */
    public function indexOf(callable $callback): int
    {
        return $this->search($callback);
    }

    /**
     * Returns the median value of a column.
     *
     * @example $collection->median('age')
     */
    public function median(?string $column = null): float|int|null
    {
        if ($this->isEmpty()) {
            return null;
        }

        $values = $column
            ? array_map(fn($i) => is_array($i) ? ($i[$column] ?? 0) : ($i->$column ?? 0), $this->items)
            : $this->items;

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }

    /**
     * Returns the mode (most frequent value) of a column.
     * Returns null if the collection is empty.
     *
     * @example $collection->mode('status')
     */
    public function mode(?string $column = null): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        $values = $column
            ? array_map(fn($i) => is_array($i) ? ($i[$column] ?? null) : ($i->$column ?? null), $this->items)
            : $this->items;

        $counts = array_count_values(array_map('strval', $values));
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * Returns the standard deviation of a column's values.
     *
     * @example $collection->stdDev('score')
     * @param bool $sample true = sample std dev (n-1), false = population (n)
     */
    public function stdDev(?string $column = null, bool $sample = false): float
    {
        if ($this->count() < 2) {
            return 0.0;
        }

        $avg = $this->avg($column);
        $values = $column
            ? array_map(fn($i) => is_array($i) ? ($i[$column] ?? 0) : ($i->$column ?? 0), $this->items)
            : $this->items;

        $variance = array_sum(array_map(fn($v) => ($v - $avg) ** 2, $values));
        $divisor = $sample ? $this->count() - 1 : $this->count();

        return sqrt($variance / $divisor);
    }

    /**
     * Returns the variance of a column's values.
     *
     * @example $collection->variance('score')
     */
    public function variance(?string $column = null, bool $sample = false): float
    {
        return $this->stdDev($column, $sample) ** 2;
    }

    /**
     * Counts items grouped by column value.
     * Returns an associative array: ['value' => count, ...]
     *
     * @example $collection->countBy('status') // ['active' => 3, 'inactive' => 1]
     */
    public function countBy(string $column): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $key = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            $key = (string) $key;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Sums values grouped by column.
     * Returns an associative array: ['group' => sum, ...]
     *
     * @example $collection->sumBy('category', 'price')
     */
    public function sumBy(string $groupColumn, string $sumColumn): array
    {
        $sums = [];
        foreach ($this->items as $item) {
            $group = (string) (is_array($item) ? ($item[$groupColumn] ?? null) : ($item->$groupColumn ?? null));
            $value = (float) (is_array($item) ? ($item[$sumColumn] ?? 0) : ($item->$sumColumn ?? 0));
            $sums[$group] = ($sums[$group] ?? 0.0) + $value;
        }
        return $sums;
    }

    /**
     * Returns the percentage each item's column value represents of the total sum.
     * Returns a new collection with the same items plus a '__pct' key/property.
     *
     * @example $collection->percentage('sales')
     */
    public function percentage(string $column): self
    {
        $total = $this->sum($column);

        if ($total == 0) {
            return $this;
        }

        return $this->map(function ($item) use ($column, $total) {
            $val = is_array($item) ? ($item[$column] ?? 0) : ($item->$column ?? 0);
            $pct = round(($val / $total) * 100, 2);

            if (is_array($item)) {
                $item['__pct'] = $pct;
            } else {
                $item->__pct = $pct;
            }
            return $item;
        });
    }

    /**
     * Splits the collection into two: items that pass and items that fail.
     * Returns an array of two Collection instances.
     *
     * @example [$active, $inactive] = $collection->partition(fn($u) => $u->active)
     */
    public function partition(callable $callback): array
    {
        $pass = [];
        $fail = [];

        foreach ($this->items as $item) {
            if ($callback($item)) {
                $pass[] = $item;
            } else {
                $fail[] = $item;
            }
        }

        return [new static($pass), new static($fail)];
    }

    /**
     * Splits the collection into N groups as evenly as possible.
     *
     * @example $collection->splitInto(3) // returns [Collection, Collection, Collection]
     */
    public function splitInto(int $numberOfGroups): array
    {
        $count = $this->count();
        $size = (int) ceil($count / max(1, $numberOfGroups));
        return $this->chunk($size);
    }

    /**
     * Splits the collection at the given index into two parts.
     *
     * @example [$head, $tail] = $collection->splitAt(3)
     */
    public function splitAt(int $index): array
    {
        return [
            new static(array_slice($this->items, 0, $index)),
            new static(array_slice($this->items, $index)),
        ];
    }

    /**
     * Returns items until the callback returns false.
     *
     * @example $collection->takeUntil(fn($u) => $u->age > 30)
     */
    public function takeUntil(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($callback($item)) {
                break;
            }
            $result[] = $item;
        }
        return new static($result);
    }

    /**
     * Skips items until the callback returns true, then returns the rest.
     *
     * @example $collection->skipUntil(fn($u) => $u->active)
     */
    public function skipUntil(callable $callback): self
    {
        $found = false;
        $result = [];

        foreach ($this->items as $item) {
            if (!$found && $callback($item)) {
                $found = true;
            }
            if ($found) {
                $result[] = $item;
            }
        }

        return new static($result);
    }

    /**
     * Returns items while the callback returns true.
     *
     * @example $collection->takeWhile(fn($u) => $u->age < 30)
     */
    public function takeWhile(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if (!$callback($item)) {
                break;
            }
            $result[] = $item;
        }
        return new static($result);
    }

    /**
     * Skips items while the callback returns true, then returns the rest.
     *
     * @example $collection->skipWhile(fn($u) => $u->age < 18)
     */
    public function skipWhile(callable $callback): self
    {
        $skipping = true;
        $result = [];

        foreach ($this->items as $item) {
            if ($skipping && $callback($item)) {
                continue;
            }
            $skipping = false;
            $result[] = $item;
        }

        return new static($result);
    }

    /**
     * Paginates the collection and returns a structured result.
     *
     * @param int $page    Current page (1-indexed)
     * @param int $perPage Items per page
     *
     * @return array{data: self, total: int, per_page: int, current_page: int, last_page: int, from: int, to: int}
     *
     * @example $collection->paginate(page: 1, perPage: 15)
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $total = $this->count();
        $last = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $items = new static(array_slice($this->items, $offset, $perPage));

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, $last),
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * Returns the items for a specific page without the metadata.
     *
     * @example $collection->forPage(2, 10)
     */
    public function forPage(int $page, int $perPage): self
    {
        return $this->slice(($page - 1) * $perPage, $perPage);
    }

    /**
     * Returns the underlying items as-is without any transformation.
     * Useful for quick inspection in conditions.
     *
     * @example if ($collection->isNotEmpty()) { ... }
     */
    public function value(string $column, mixed $default = null): mixed
    {
        $item = $this->first();
        if ($item === null) {
            return $default;
        }
        return is_array($item) ? ($item[$column] ?? $default) : ($item->$column ?? $default);
    }

    /**
     * Returns a summary of the collection for debugging purposes.
     *
     * @example $collection->summary('price')
     * // ['count' => 10, 'sum' => 540.0, 'avg' => 54.0, 'min' => 9.0, 'max' => 120.0]
     */
    public function summary(?string $column = null): array
    {
        if ($this->isEmpty()) {
            return ['count' => 0, 'sum' => 0, 'avg' => 0, 'min' => null, 'max' => null, 'median' => null];
        }

        return [
            'count' => $this->count(),
            'sum' => $column ? $this->sum($column) : null,
            'avg' => $column ? $this->avg($column) : null,
            'min' => $column ? $this->min($column) : null,
            'max' => $column ? $this->max($column) : null,
            'median' => $column ? $this->median($column) : null,
        ];
    }

    /**
     * Dumps the collection and continues execution (pretty print).
     *
     * @example $collection->inspect()
     */
    public function inspect(string $label = ''): self
    {
        if ($label !== '') {
            echo "\n=== {$label} ===\n";
        }
        echo json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        return $this;
    }

    /**
     * Asserts a condition against the collection during chaining.
     * Throws a RuntimeException if the assertion fails.
     * Useful for inline sanity checks.
     *
     * @example $collection->assert(fn($c) => $c->isNotEmpty(), 'Collection must not be empty')
     */
    public function assert(callable $callback, string $message = 'Collection assertion failed.'): self
    {
        if (!$callback($this)) {
            throw new \RuntimeException($message);
        }
        return $this;
    }

    /**
     * Returns a Generator that yields items one by one.
     * Useful for memory-efficient iteration over large collections.
     *
     * @example foreach ($collection->cursor() as $item) { ... }
     */
    public function cursor(): \Generator
    {
        foreach ($this->items as $key => $item) {
            yield $key => $item;
        }
    }

    /**
     * Applies a callback lazily using a Generator (no intermediate array).
     * The result is NOT a Collection — iterate it directly.
     *
     * @example foreach ($collection->lazy(fn($u) => $u->name) as $name) { ... }
     */
    public function lazy(callable $callback): \Generator
    {
        foreach ($this->items as $key => $item) {
            yield $key => $callback($item);
        }
    }

    /**
     * Converts the collection to a plain PHP array of primitives,
     * using a column as the value (equivalent to array_column).
     *
     * @example $collection->column('name', 'id') // [1 => 'Alice', 2 => 'Bob']
     */
    public function column(string $valueColumn, ?string $indexColumn = null): array
    {
        return array_column(
            array_map(fn($i) => (array) $i, $this->items),
            $valueColumn,
            $indexColumn
        );
    }

    /**
     * Converts to a CSV string.
     *
     * @param array|null $columns Columns to include (null = all keys from first item)
     * @param bool       $header  Include header row
     *
     * @example $collection->toCsv(['name', 'email'])
     */
    public function toCsv(?array $columns = null, bool $header = true): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $first = (array) $this->items[0];

        if ($columns === null) {
            $columns = array_keys($first);
        }

        $lines = [];

        if ($header) {
            $lines[] = implode(',', array_map(fn($c) => '"' . addslashes($c) . '"', $columns));
        }

        foreach ($this->items as $item) {
            $row = (array) $item;
            $lines[] = implode(',', array_map(function ($col) use ($row) {
                $val = $row[$col] ?? '';
                return '"' . addslashes((string) $val) . '"';
            }, $columns));
        }

        return implode("\n", $lines);
    }

    /**
     * Converts the collection to a lookup map (column => true).
     * Useful for O(1) existence checks.
     *
     * @example $ids = $collection->toLookup('id') // [1 => true, 2 => true]
     */
    public function toLookup(string $column): array
    {
        $map = [];
        foreach ($this->items as $item) {
            $key = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            if ($key !== null) {
                $map[$key] = true;
            }
        }
        return $map;
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