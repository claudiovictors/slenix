<?php

/*
 |--------------------------------------------------------------------------
 | SLENIX COLLECTION - Coleção de modelos estilo Laravel
 |--------------------------------------------------------------------------
 |
 | Permite encadeamento de métodos após consultas, oferecendo funcionalidades
 | de manipulação de dados semelhantes ao Laravel Collection.
 |
 */

declare(strict_types=1);

namespace Slenix\Supports\Database;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array Items da coleção */
    protected array $items = [];

    /**
     * @param array $items Items iniciais
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    // =========================================================
    // FILTRAGEM E BUSCA
    // =========================================================

    /**
     * Filtra os items por coluna e valor (equivalente ao where do Eloquent Collection)
     *
     */
    public function where(string $column, $operatorOrValue = null, $value = null): self
    {
        // Suporte a 2 ou 3 argumentos
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
     * Filtra itens onde o valor da coluna está no array
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
     * Filtra itens onde o valor da coluna NÃO está no array
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->filter(function ($item) use ($column, $values) {
            $itemValue = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return !in_array($itemValue, $values);
        });
    }

    /**
     * Filtra itens onde a coluna é null
     */
    public function whereNull(string $column): self
    {
        return $this->filter(function ($item) use ($column) {
            $val = is_array($item) ? ($item[$column] ?? 'NOT_SET') : ($item->$column ?? 'NOT_SET');
            return $val === null;
        });
    }

    /**
     * Filtra itens onde a coluna não é null
     */
    public function whereNotNull(string $column): self
    {
        return $this->filter(function ($item) use ($column) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return $val !== null;
        });
    }

    /**
     * Filtra itens onde o valor da coluna está entre dois valores
     */
    public function whereBetween(string $column, $min, $max): self
    {
        return $this->filter(function ($item) use ($column, $min, $max) {
            $val = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            return $val >= $min && $val <= $max;
        });
    }

    /**
     * Retorna o primeiro item que corresponde a condição opcional
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
     * Retorna o último item
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
     * Retorna o item no índice especificado
     */
    public function nth(int $index, $default = null)
    {
        return $this->items[$index] ?? $default;
    }

    /**
     * Verifica se a coleção contém um item que satisfaz o critério
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
     * Alias de !contains
     */
    public function doesntContain($keyOrCallback, $value = null): bool
    {
        return !$this->contains($keyOrCallback, $value);
    }

    /**
     * Busca um item pela chave primária
     */
    public function find($id, string $keyColumn = 'id')
    {
        return $this->first(fn($item) => ($item->$keyColumn ?? null) == $id);
    }

    // =========================================================
    // TRANSFORMAÇÃO
    // =========================================================

    /**
     * Aplica callback em cada item e retorna nova coleção
     *
     * @example $collection->map(fn($u) => $u->name)
     */
    public function map(callable $callback): self
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Aplica callback em cada item (sem retornar nova coleção)
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
     * Filtra os items com um callback
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
     * Rejeita itens que satisfazem o critério (inverso do filter)
     */
    public function reject(callable $callback): self
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    /**
     * Retorna apenas os valores de uma coluna
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
     * Ordena a coleção por coluna
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
     * Ordena de forma decrescente
     */
    public function sortByDesc(string $column): self
    {
        return $this->sortBy($column, 'desc');
    }

    /**
     * Ordena usando callback customizado
     */
    public function sortUsing(callable $callback): self
    {
        $items = $this->items;
        usort($items, $callback);
        return new static($items);
    }

    /**
     * Agrupa os itens por coluna
     *
     * @example $collection->groupBy('status') // retorna ['active' => [...], 'inactive' => [...]]
     */
    public function groupBy(string $column): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $key = is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null);
            $groups[$key][] = $item;
        }

        // Converte cada grupo para Collection
        return array_map(fn($group) => new static($group), $groups);
    }

    /**
     * Retorna itens únicos por coluna ou identidade
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
     * Faz o flat map (map + flatten)
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
     * Aplica um callback sem modificar a coleção (para debug/side-effects)
     */
    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    /**
     * Aplica um callback que pode transformar toda a coleção
     */
    public function pipe(callable $callback)
    {
        return $callback($this);
    }

    /**
     * Passa cada item pelo callback e retorna a coleção original (sem modificar)
     */
    public function tapEach(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }
        return $this;
    }

    // =========================================================
    // SLICING
    // =========================================================

    /**
     * Retorna os primeiros N items
     */
    public function take(int $limit): self
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Pula os primeiros N items
     */
    public function skip(int $offset): self
    {
        return new static(array_slice($this->items, $offset));
    }

    /**
     * Retorna uma fatia da coleção
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Divide a coleção em pedaços
     *
     * @example $collection->chunk(3) // retorna [Collection, Collection, ...]
     */
    public function chunk(int $size): array
    {
        return array_map(
            fn($chunk) => new static($chunk),
            array_chunk($this->items, $size)
        );
    }

    /**
     * Processa a coleção em chunks (sem guardar em memória)
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
     * Embaralha os items
     */
    public function shuffle(): self
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    /**
     * Reverte a ordem dos items
     */
    public function reverse(): self
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Retorna items aleatórios
     */
    public function random(int $count = 1): self
    {
        $shuffled = $this->shuffle();
        return $count === 1 ? $shuffled->take(1) : $shuffled->take($count);
    }

    // =========================================================
    // AGREGAÇÃO
    // =========================================================

    /**
     * Conta os items
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Soma os valores de uma coluna
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
     * Calcula a média dos valores de uma coluna
     */
    public function avg(?string $column = null): float
    {
        if ($this->isEmpty()) return 0.0;
        return $this->sum($column) / $this->count();
    }

    /**
     * Retorna o valor mínimo de uma coluna
     */
    public function min(?string $column = null)
    {
        if ($column === null) {
            return min($this->items);
        }

        return min(array_map(fn($item) => is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null), $this->items));
    }

    /**
     * Retorna o valor máximo de uma coluna
     */
    public function max(?string $column = null)
    {
        if ($column === null) {
            return max($this->items);
        }

        return max(array_map(fn($item) => is_array($item) ? ($item[$column] ?? null) : ($item->$column ?? null), $this->items));
    }

    /**
     * Reduz a coleção a um único valor
     */
    public function reduce(callable $callback, $carry = null)
    {
        return array_reduce($this->items, $callback, $carry);
    }

    // =========================================================
    // VERIFICAÇÃO
    // =========================================================

    /**
     * Verifica se a coleção está vazia
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Verifica se a coleção NÃO está vazia
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Verifica se todos os items satisfazem o critério
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if (!$callback($item)) return false;
        }
        return true;
    }

    /**
     * Verifica se algum item satisfaz o critério
     */
    public function some(callable $callback): bool
    {
        return $this->contains($callback);
    }

    // =========================================================
    // CONVERSÃO
    // =========================================================

    /**
     * Retorna todos os items como array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Converte para array de arrays (usando toArray() em cada modelo)
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
     * Converte para JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Para json_encode()
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Converte para array plano de valores de uma coluna
     */
    public function toFlatArray(string $column): array
    {
        return $this->pluck($column)->all();
    }

    /**
     * Converte a coleção para um array com chave
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
     * Concatena coleções
     */
    public function merge(self|array $other): self
    {
        $items = $other instanceof self ? $other->all() : $other;
        return new static(array_merge($this->items, $items));
    }

    /**
     * Combina com outra coleção removendo duplicatas por coluna
     */
    public function union(self|array $other, ?string $uniqueBy = null): self
    {
        return $this->merge($other)->unique($uniqueBy);
    }

    /**
     * Retorna apenas os items que existem em ambas coleções
     */
    public function intersect(self|array $other): self
    {
        $otherArray = $other instanceof self ? $other->all() : $other;
        return new static(array_values(array_intersect($this->items, $otherArray)));
    }

    /**
     * Retorna items que não existem na outra coleção
     */
    public function diff(self|array $other): self
    {
        $otherArray = $other instanceof self ? $other->all() : $other;
        return new static(array_values(array_diff($this->items, $otherArray)));
    }

    // =========================================================
    // INTERFACES
    // =========================================================

    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

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
     * Debug helper (sem exit)
     */
    public function dump(): self
    {
        var_dump($this->toArray());
        return $this;
    }
}