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

/**
 * Class Collection
 * 
 * A fluent wrapper for array manipulation.
 * 
 * @package Slenix\Supports\Libraries
 */
class Collection
{
    /**
     * The underlying items contained in the collection.
     * 
     * @var array<int|string, mixed>
     */
    private array $items;

    /**
     * Create a new collection instance.
     *
     * @param array $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Create a new collection instance statically.
     *
     * @param array $items
     * @return self
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    // --- Access Methods ---

    /**
     * Get all of the items in the collection.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Determine if the collection is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * Get the first item from the collection.
     *
     * @param mixed $d Default value if collection is empty
     * @return mixed
     */
    public function first(mixed $d = null): mixed
    {
        return $this->items[array_key_first($this->items) ?? 0] ?? $d;
    }

    /**
     * Get the last item from the collection.
     *
     * @param mixed $d Default value if collection is empty
     * @return mixed
     */
    public function last(mixed $d = null): mixed
    {
        return !empty($this->items) ? end($this->items) : $d;
    }

    /**
     * Get an item from the collection by key.
     *
     * @param int|string $key
     * @param mixed $d Default value
     * @return mixed
     */
    public function get(int|string $key, mixed $d = null): mixed
    {
        return $this->items[$key] ?? $d;
    }

    /**
     * Determine if an item exists in the collection by key.
     *
     * @param int|string $key
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Get the collection of items as a plain array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Get the collection of items as JSON.
     *
     * @param int $flags
     * @return string
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->items, $flags);
    }

    /**
     * Convert the collection to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    // --- Transformation Methods ---

    /**
     * Run a map over each of the items.
     *
     * @param callable $cb
     * @return static
     */
    public function map(callable $cb): static
    {
        return new static(array_map($cb, $this->items));
    }

    /**
     * Run a filter over each of the items.
     *
     * @param callable|null $cb
     * @return static
     */
    public function filter(?callable $cb = null): static
    {
        return new static(array_values($cb ? array_filter($this->items, $cb) : array_filter($this->items)));
    }

    /**
     * Filter items by the given callback, removing items that pass the truth test.
     *
     * @param callable $cb
     * @return static
     */
    public function reject(callable $cb): static
    {
        return $this->filter(fn($i) => !$cb($i));
    }

    /**
     * Execute a callback over each item.
     *
     * @param callable $cb
     * @return static
     */
    public function each(callable $cb): static
    {
        foreach ($this->items as $k => $i) {
            if ($cb($i, $k) === false)
                break;
        }
        return $this;
    }

    /**
     * Get the values of a given key.
     *
     * @param string $key
     * @param string|null $indexBy
     * @return static
     */
    public function pluck(string $key, ?string $indexBy = null): static
    {
        return new static(array_pluck($this->items, $key, $indexBy));
    }

    /**
     * Group an associative array by a given key.
     *
     * @param string $key
     * @return static
     */
    public function groupBy(string $key): static
    {
        return new static(array_group_by($this->items, $key));
    }

    /**
     * Return only unique items from the collection array.
     *
     * @param string|null $key
     * @return static
     */
    public function unique(?string $key = null): static
    {
        return $key ? new static(array_unique_by($this->items, $key)) : new static(array_values(array_unique($this->items)));
    }

    /**
     * Flatten a multi-dimensional collection into a single dimension.
     *
     * @return static
     */
    public function flatten(): static
    {
        return new static(array_flatten($this->items));
    }

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @param int $size
     * @return static
     */
    public function chunk(int $size): static
    {
        return new static(array_chunk($this->items, $size));
    }

    /**
     * Take the first or last {$n} items.
     *
     * @param int $n
     * @return static
     */
    public function take(int $n): static
    {
        return $n >= 0 ? new static(array_slice($this->items, 0, $n)) : new static(array_slice($this->items, $n));
    }

    /**
     * Skip the first {$n} items.
     *
     * @param int $n
     * @return static
     */
    public function skip(int $n): static
    {
        return new static(array_slice($this->items, $n));
    }

    /**
     * Slice the underlying collection array.
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Run an associative map over each of the items.
     *
     * @param callable $cb
     * @return static
     */
    public function mapWithKeys(callable $cb): static
    {
        $result = [];
        foreach ($this->items as $k => $item) {
            $pair = $cb($item, $k);
            if (is_array($pair))
                foreach ($pair as $pk => $pv)
                    $result[$pk] = $pv;
        }
        return new static($result);
    }

    // --- Search Methods ---

    /**
     * Filter the collection by a given key/value pair.
     *
     * @param string $key
     * @param mixed $value
     * @param string $op Operator (=, ==, ===, !=, !==, >, >=, <, <=)
     * @return static
     */
    public function where(string $key, mixed $value, string $op = '='): static
    {
        return $this->filter(function ($item) use ($key, $value, $op) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return match ($op) { 
                '=', '==' => $v == $value, 
                '==='    => $v === $value, 
                '!='     => $v != $value, 
                '!=='    => $v !== $value, 
                '>'      => $v > $value, 
                '>='     => $v >= $value, 
                '<'      => $v < $value, 
                '<='     => $v <= $value, 
                default  => $v == $value
            };
        });
    }

    /**
     * Filter items by a given array of values.
     *
     * @param string $key
     * @param array $values
     * @return static
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($i) => in_array(is_array($i) ? ($i[$key] ?? null) : ($i->$key ?? null), $values, true));
    }

    /**
     * Filter items not in a given array of values.
     *
     * @param string $key
     * @param array $values
     * @return static
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($i) => !in_array(is_array($i) ? ($i[$key] ?? null) : ($i->$key ?? null), $values, true));
    }

    /**
     * Determine if an item exists in the collection.
     *
     * @param mixed $value
     * @param string|null $key
     * @return bool
     */
    public function contains(mixed $value, ?string $key = null): bool
    {
        if ($key) {
            foreach ($this->items as $i) {
                if ((is_array($i) ? ($i[$key] ?? null) : ($i->$key ?? null)) === $value)
                    return true;
            }
            return false;
        }
        return in_array($value, $this->items, true);
    }

    /**
     * Search the collection for a given value and return the corresponding key if successful.
     *
     * @param mixed $value
     * @return int|string|false
     */
    public function search(mixed $value): int|string|false
    {
        return array_search($value, $this->items, true);
    }

    // --- Sort Methods ---

    /**
     * Sort the collection by the given key.
     *
     * @param string $key
     * @param string $dir 'asc' or 'desc'
     * @return static
     */
    public function sortBy(string $key, string $dir = 'asc'): static
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($key, $dir) {
            $va = is_array($a) ? ($a[$key] ?? null) : ($a->$key ?? null);
            $vb = is_array($b) ? ($b[$key] ?? null) : ($b->$key ?? null);
            return $dir === 'asc' ? $va <=> $vb : $vb <=> $va;
        });
        return new static($items);
    }

    /**
     * Sort the collection in descending order by the given key.
     *
     * @param string $key
     * @return static
     */
    public function sortByDesc(string $key): static
    {
        return $this->sortBy($key, 'desc');
    }

    /**
     * Sort the collection using a callback or default sort.
     *
     * @param callable|null $cb
     * @return static
     */
    public function sort(?callable $cb = null): static
    {
        $items = $this->items;
        $cb ? usort($items, $cb) : sort($items);
        return new static($items);
    }

    /**
     * Reverse the items order.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Shuffle the items in the collection.
     *
     * @return static
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // --- Aggregate Methods ---

    /**
     * Get the sum of the given values.
     *
     * @param string|null $key
     * @return int|float
     */
    public function sum(?string $key = null): int|float
    {
        return $key ? array_sum(array_column($this->items, $key)) : array_sum($this->items);
    }

    /**
     * Get the average value of a given key.
     *
     * @param string|null $key
     * @return float
     */
    public function avg(?string $key = null): float
    {
        $c = $this->count();
        return $c > 0 ? $this->sum($key) / $c : 0.0;
    }

    /**
     * Get the minimum value of a given key.
     *
     * @param string|null $key
     * @return mixed
     */
    public function min(?string $key = null): mixed
    {
        if ($key) {
            $v = array_column($this->items, $key);
            return $v ? min($v) : null;
        }
        return $this->items ? min($this->items) : null;
    }

    /**
     * Get the maximum value of a given key.
     *
     * @param string|null $key
     * @return mixed
     */
    public function max(?string $key = null): mixed
    {
        if ($key) {
            $v = array_column($this->items, $key);
            return $v ? max($v) : null;
        }
        return $this->items ? max($this->items) : null;
    }

    /**
     * Reduce the collection to a single value.
     *
     * @param callable $cb
     * @param mixed $carry
     * @return mixed
     */
    public function reduce(callable $cb, mixed $carry = null): mixed
    {
        return array_reduce($this->items, $cb, $carry);
    }

    // --- Modify Methods ---

    /**
     * Push an item onto the end of the collection.
     *
     * @param mixed $item
     * @return static
     */
    public function push(mixed $item): static
    {
        $c = clone $this;
        $c->items[] = $item;
        return $c;
    }

    /**
     * Push an item onto the beginning of the collection.
     *
     * @param mixed $item
     * @return static
     */
    public function prepend(mixed $item): static
    {
        return new static(array_merge([$item], $this->items));
    }

    /**
     * Put an item in the collection by key.
     *
     * @param int|string $key
     * @param mixed $v
     * @return static
     */
    public function put(int|string $key, mixed $v): static
    {
        $items = $this->items;
        $items[$key] = $v;
        return new static($items);
    }

    /**
     * Remove an item from the collection by key.
     *
     * @param int|string $key
     * @return static
     */
    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);
        return new static(array_values($items));
    }

    /**
     * Merge the collection with the given items.
     *
     * @param array|self $items
     * @return static
     */
    public function merge(array|self $items): static
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new static(array_merge($this->items, $other));
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * @param array $other
     * @return static
     */
    public function zip(array $other): static
    {
        return new static(array_map(null, $this->items, $other));
    }

    // --- Keys Methods ---

    /**
     * Get the keys of the collection items.
     *
     * @return static
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Get the values of the collection items.
     *
     * @return static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Key an associative array by a given key.
     *
     * @param string $key
     * @return static
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

    // --- Pagination Methods ---

    /**
     * Paginate the collection.
     *
     * @param int $perPage
     * @param int|null $page
     * @return array
     */
    public function paginate(int $perPage, ?int $page = null): array
    {
        $page = $page ?? max(1, (int) ($_GET['page'] ?? 1));
        return array_paginate($this->items, $perPage, $page);
    }

    // --- Utility Methods ---

    /**
     * Pass the collection to the given callback and then return it.
     *
     * @param callable $cb
     * @return static
     */
    public function tap(callable $cb): static
    {
        $cb($this);
        return $this;
    }

    /**
     * Pass the collection to the given callback and return the result.
     *
     * @param callable $cb
     * @return mixed
     */
    public function pipe(callable $cb): mixed
    {
        return $cb($this);
    }

    /**
     * Dump the items and end the script.
     *
     * @return never
     */
    public function dd(): never
    {
        dd($this->items);
    }

    /**
     * Dump the items.
     *
     * @return static
     */
    public function dump(): static
    {
        dump($this->items);
        return $this;
    }
}