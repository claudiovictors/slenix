<?php

declare(strict_types = 1);

namespace Slenix\Supports\Libraries;

class Collection
{
    private array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function make(array $items = []): self
    {
        return new self($items);
    }

    // --- Acesso ---

    public function all(): array
    {
        return $this->items;
    }
    public function count(): int
    {
        return count($this->items);
    }
    public function isEmpty(): bool
    {
        return empty($this->items);
    }
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }
    public function first(mixed $default = null): mixed
    {
        return $this->items[array_key_first($this->items) ?? 0] ?? $default;
    }
    public function last(mixed $default = null): mixed
    {
        return !empty($this->items) ? end($this->items) : $default;
    }
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
    public function toArray(): array
    {
        return $this->items;
    }
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->items, $flags);
    }

    // --- Transformação ---

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);
            if (is_array($pair)) {
                foreach ($pair as $k => $v)
                    $result[$k] = $v;
            }
        }
        return new static($result);
    }

    public function filter(?callable $callback = null): static
    {
        return new static(array_values(
            $callback ? array_filter($this->items, $callback) : array_filter($this->items)
        ));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false)
                break;
        }
        return $this;
    }

    public function pluck(string $key, ?string $indexBy = null): static
    {
        return new static(array_pluck($this->items, $key, $indexBy));
    }

    public function groupBy(string $key): static
    {
        return new static(array_group_by($this->items, $key));
    }

    public function unique(?string $key = null): static
    {
        return $key
            ? new static(array_unique_by($this->items, $key))
            : new static(array_values(array_unique($this->items)));
    }

    public function flatten(): static
    {
        return new static(array_flatten($this->items));
    }

    public function chunk(int $size): static
    {
        return new static(array_chunk($this->items, $size));
    }

    public function take(int $limit): static
    {
        return $limit >= 0
            ? new static(array_slice($this->items, 0, $limit))
            : new static(array_slice($this->items, $limit));
    }

    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    // --- Busca ---

    public function where(string $key, mixed $value, string $operator = '='): static
    {
        return $this->filter(function ($item) use ($key, $value, $operator) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return match ($operator) {
                '=', '==' => $v == $value,
                '===' => $v === $value,
                '!=' => $v != $value,
                '!==' => $v !== $value,
                '>' => $v > $value,
                '>=' => $v >= $value,
                '<' => $v < $value,
                '<=' => $v <= $value,
                default => $v == $value,
            };
        });
    }

    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

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

    public function search(mixed $value): int|string|false
    {
        return array_search($value, $this->items, true);
    }

    // --- Ordenação ---

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

    public function sortByDesc(string $key): static
    {
        return $this->sortBy($key, 'desc');
    }

    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? usort($items, $callback) : sort($items);
        return new static($items);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // --- Agregação ---

    public function sum(?string $key = null): int|float
    {
        return $key ? array_sum(array_column($this->items, $key)) : array_sum($this->items);
    }

    public function avg(?string $key = null): float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0.0;
    }

    public function min(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? min($values) : null;
        }
        return $this->items ? min($this->items) : null;
    }

    public function max(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? max($values) : null;
        }
        return $this->items ? max($this->items) : null;
    }

    public function reduce(callable $callback, mixed $carry = null): mixed
    {
        return array_reduce($this->items, $callback, $carry);
    }

    // --- Modificação ---

    public function push(mixed $item): static
    {
        $clone = clone $this;
        $clone->items[] = $item;
        return $clone;
    }

    public function prepend(mixed $item): static
    {
        return new static(array_merge([$item], $this->items));
    }

    public function put(int|string $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;
        return new static($items);
    }

    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);
        return new static(array_values($items));
    }

    public function merge(array|self $items): static
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new static(array_merge($this->items, $other));
    }

    public function zip(array $other): static
    {
        return new static(array_map(null, $this->items, $other));
    }

    // --- Chaves ---

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keyBy(string $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            $result[$k] = $item;
        }
        return new static($result);
    }

    // --- Paginação ---

    public function paginate(int $perPage, ?int $page = null): array
    {
        $page = $page ?? max(1, (int) ($_GET['page'] ?? 1));
        return array_paginate($this->items, $perPage, $page);
    }

    // --- Utilidade ---

    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    public function dd(): never
    {
        dd($this->items);
    }

    public function dump(): static
    {
        dump($this->items);
        return $this;
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}