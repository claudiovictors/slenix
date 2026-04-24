<?php

/*
|--------------------------------------------------------------------------
| Collection Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class provides a fluent, object-oriented wrapper for working with 
| arrays. It includes methods for mapping, filtering, reducing, sorting, 
| and paginating data sets efficiently.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

class Collection
{
    /** @var array The underlying array of items. */
    private array $items;

    /**
     * Collection constructor.
     * @param array $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Create a new collection instance.
     * @param array $items
     * @return self
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    // --- Access Methods ---

    /** @return array All items in the collection. */
    public function all(): array
    {
        return $this->items;
    }

    /** @return int The number of items in the collection. */
    public function count(): int
    {
        return count($this->items);
    }

    /** @return bool True if collection is empty. */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /** @return bool True if collection is not empty. */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * Get the first item from the collection.
     * @param mixed $default
     * @return mixed
     */
    public function first(mixed $default = null): mixed
    {
        return $this->items[array_key_first($this->items) ?? 0] ?? $default;
    }

    /**
     * Get the last item from the collection.
     * @param mixed $default
     * @return mixed
     */
    public function last(mixed $default = null): mixed
    {
        return !empty($this->items) ? end($this->items) : $default;
    }

    /**
     * Get an item by its key.
     * @param int|string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Check if a key exists in the collection.
     * @param int|string $key
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /** @return array */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Convert the collection to JSON.
     * @param int $flags
     * @return string
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->items, $flags);
    }

    // --- Transformation Methods ---

    /**
     * Run a map over each of the items.
     * @param callable $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Run an associative map over each of the items.
     */
    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);
            if (is_array($pair)) {
                foreach ($pair as $k => $v) {
                    $result[$k] = $v;
                }
            }
        }
        return new static($result);
    }

    /**
     * Filter the collection using a callback.
     * @param callable|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        return new static(array_values(
            $callback ? array_filter($this->items, $callback) : array_filter($this->items)
        ));
    }

    /**
     * Filter items that do not pass the truth test.
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    /**
     * Execute a callback over each item.
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) break;
        }
        return $this;
    }

    /**
     * Extract a single column of values from the items.
     */
    public function pluck(string $key, ?string $indexBy = null): static
    {
        return new static(array_pluck($this->items, $key, $indexBy));
    }

    /**
     * Group items by a specific key.
     */
    public function groupBy(string $key): static
    {
        return new static(array_group_by($this->items, $key));
    }

    /**
     * Remove duplicate items.
     */
    public function unique(?string $key = null): static
    {
        return $key
            ? new static(array_unique_by($this->items, $key))
            : new static(array_values(array_unique($this->items)));
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     */
    public function flatten(): static
    {
        return new static(array_flatten($this->items));
    }

    /**
     * Chunk the collection into multiple small collections.
     */
    public function chunk(int $size): static
    {
        return new static(array_chunk($this->items, $size));
    }

    /**
     * Take a specific number of items.
     */
    public function take(int $limit): static
    {
        return $limit >= 0
            ? new static(array_slice($this->items, 0, $limit))
            : new static(array_slice($this->items, $limit));
    }

    /**
     * Skip a specific number of items.
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Slice the underlying array.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    // --- Search Methods ---

    /**
     * Filter items by a given key/value pair.
     */
    public function where(string $key, mixed $value, string $operator = '='): static
    {
        return $this->filter(function ($item) use ($key, $value, $operator) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return match ($operator) {
                '=', '==' => $v == $value,
                '==='    => $v === $value,
                '!='     => $v != $value,
                '!=='    => $v !== $value,
                '>'      => $v > $value,
                '>='     => $v >= $value,
                '<'      => $v < $value,
                '<='     => $v <= $value,
                default  => $v == $value,
            };
        });
    }

    /**
     * Filter items where a key is within a set of values.
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

    /**
     * Filter items where a key is NOT within a set of values.
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

    /**
     * Determine if an item exists in the collection.
     */
    public function contains(mixed $value, ?string $key = null): bool
    {
        if ($key) {
            foreach ($this->items as $item) {
                if ((is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null)) === $value) {
                    return true;
                }
            }
            return false;
        }
        return in_array($value, $this->items, true);
    }

    /**
     * Search the collection for a given value.
     */
    public function search(mixed $value): int|string|false
    {
        return array_search($value, $this->items, true);
    }

    // --- Sorting Methods ---

    /**
     * Sort items by a key.
     */
    public function sortBy(string $key, string $direction = 'asc'): static
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($key, $direction) {
            $va = is_array($a) ? ($a[$key] ?? null) : ($a->$key ?? null);
            $vb = is_array($b) ? ($b[$key] ?? null) : ($b->$key ?? null);
            return $direction === 'asc' ? $va <=> $vb : $vb <=> $va;
        });
        return new static($items);
    }

    /**
     * Sort items by a key in descending order.
     */
    public function sortByDesc(string $key): static
    {
        return $this->sortBy($key, 'desc');
    }

    /**
     * Sort the underlying array.
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? usort($items, $callback) : sort($items);
        return new static($items);
    }

    /**
     * Reverse the items.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Shuffle the items randomly.
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // --- Aggregation Methods ---

    /**
     * Get the sum of all items or a specific key.
     */
    public function sum(?string $key = null): int|float
    {
        return $key ? array_sum(array_column($this->items, $key)) : array_sum($this->items);
    }

    /**
     * Get the average value.
     */
    public function avg(?string $key = null): float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0.0;
    }

    /**
     * Get the minimum value.
     */
    public function min(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? min($values) : null;
        }
        return $this->items ? min($this->items) : null;
    }

    /**
     * Get the maximum value.
     */
    public function max(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? max($values) : null;
        }
        return $this->items ? max($this->items) : null;
    }

    /**
     * Reduce the collection to a single value.
     */
    public function reduce(callable $callback, mixed $carry = null): mixed
    {
        return array_reduce($this->items, $callback, $carry);
    }

    // --- Modification Methods ---

    /**
     * Push an item onto the end of the collection.
     */
    public function push(mixed $item): static
    {
        $clone = clone $this;
        $clone->items[] = $item;
        return $clone;
    }

    /**
     * Prepend an item to the beginning.
     */
    public function prepend(mixed $item): static
    {
        return new static(array_merge([$item], $this->items));
    }

    /**
     * Add an item with a specific key.
     */
    public function put(int|string $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;
        return new static($items);
    }

    /**
     * Remove an item from the collection by its key.
     */
    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);
        return new static(array_values($items));
    }

    /**
     * Merge the collection with another array or collection.
     */
    public function merge(array|self $items): static
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new static(array_merge($this->items, $other));
    }

    /**
     * Zip the collection together with one or more arrays.
     */
    public function zip(array $other): static
    {
        return new static(array_map(null, $this->items, $other));
    }

    // --- Key/Value Methods ---

    /** @return static All keys from the collection. */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /** @return static All values from the collection. */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Keys the collection by a given column.
     */
    public function keyBy(string $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            $result[$k] = $item;
        }
        return new static($result);
    }

    // --- Pagination ---

    /**
     * Slice the collection for pagination.
     */
    public function paginate(int $perPage, ?int $page = null): array
    {
        $page = $page ?? max(1, (int) ($_GET['page'] ?? 1));
        return array_paginate($this->items, $perPage, $page);
    }

    // --- Utility Methods ---

    /**
     * Pass the collection to a callback and return it.
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * Pass the collection to a callback and return the result.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Debugging: dump and die.
     */
    public function dd(): never
    {
        dd($this->items);
    }

    /**
     * Debugging: dump without dying.
     */
    public function dump(): static
    {
        dump($this->items);
        return $this;
    }

    /** @return string JSON representation of the collection. */
    public function __toString(): string
    {
        return $this->toJson();
    }
}