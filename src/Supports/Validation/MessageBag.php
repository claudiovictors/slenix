<?php

/*
|--------------------------------------------------------------------------
| MessageBag Class
|--------------------------------------------------------------------------
|
| Fluent container for validation error messages, mirroring Laravel's
| Illuminate\Support\MessageBag interface so views can call:
|
|   $errors->has('email')
|   $errors->get('email')
|   $errors->first('email')
|   $errors->first('email', '<p>:message</p>')
|   $errors->all()
|   $errors->any()
|   $errors->count()
|   $errors->isEmpty() / $errors->isNotEmpty()
|   $errors->keys()
|   $errors->merge($anotherBag)
|   $errors->forget('email')
|   $errors->add('email', 'Custom error.')
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

class MessageBag implements \Countable, \JsonSerializable
{
    /** @var array<string, string[]> Field → list of messages */
    protected array $messages = [];

    /**
     * @param array<string, string|string[]> $messages Pre-populate the bag.
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $field => $msgs) {
            $this->messages[$field] = (array) $msgs;
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add a message for a field.
     * Duplicate messages for the same field are silently ignored.
     */
    public function add(string $field, string $message): static
    {
        if (!in_array($message, $this->messages[$field] ?? [], true)) {
            $this->messages[$field][] = $message;
        }
        return $this;
    }

    /**
     * Merge another MessageBag (or plain array) into this one.
     *
     * @param  MessageBag|array<string, string|string[]> $bag
     */
    public function merge(MessageBag|array $bag): static
    {
        $messages = $bag instanceof MessageBag ? $bag->toArray() : $bag;

        foreach ($messages as $field => $msgs) {
            foreach ((array) $msgs as $message) {
                $this->add($field, $message);
            }
        }

        return $this;
    }

    /**
     * Remove all messages for the given field.
     */
    public function forget(string $field): static
    {
        unset($this->messages[$field]);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Determine if messages exist for a given field.
     * Supports wildcard: has('address.*') matches 'address.city', 'address.zip', etc.
     */
    public function has(string $field): bool
    {
        if (str_contains($field, '*')) {
            $pattern = '/^' . str_replace('\*', '.+', preg_quote($field, '/')) . '$/';
            foreach (array_keys($this->messages) as $key) {
                if (preg_match($pattern, $key)) {
                    return true;
                }
            }
            return false;
        }

        return !empty($this->messages[$field]);
    }

    /**
     * Determine if messages exist for ANY of the given fields.
     */
    public function hasAny(string ...$fields): bool
    {
        foreach ($fields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all messages for a given field.
     *
     * @param  string $field
     * @param  string $format Optional sprintf-like wrapper, use :message as placeholder.
     * @return string[]
     */
    public function get(string $field, string $format = ':message'): array
    {
        $messages = $this->messages[$field] ?? [];
        return $this->format($messages, $format, $field);
    }

    /**
     * Get the first message for a given field (or globally if $field is null).
     *
     * @param  string|null $field
     * @param  string      $format  Wraps the message; use :message and :field as placeholders.
     * @return string
     */
    public function first(?string $field = null, string $format = ':message'): string
    {
        if ($field !== null) {
            $messages = $this->get($field, $format);
            return $messages[0] ?? '';
        }

        foreach ($this->messages as $msgs) {
            return $this->format($msgs, $format, '')[0] ?? '';
        }

        return '';
    }

    /**
     * Get all messages for all fields.
     *
     * @param  string $format Optional wrapper; use :message and :field as placeholders.
     * @return string[]
     */
    public function all(string $format = ':message'): array
    {
        $result = [];
        foreach ($this->messages as $field => $msgs) {
            foreach ($this->format($msgs, $format, $field) as $msg) {
                $result[] = $msg;
            }
        }
        return $result;
    }

    /**
     * Determine if the bag has any messages.
     */
    public function any(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Determine if the bag has no messages.
     */
    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /**
     * Determine if the bag is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Return all field keys that have errors.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->messages);
    }

    /**
     * Return the raw messages array (field → string[]).
     *
     * @return array<string, string[]>
     */
    public function toArray(): array
    {
        return $this->messages;
    }

    // -------------------------------------------------------------------------
    // Interfaces
    // -------------------------------------------------------------------------

    public function count(): int
    {
        return array_sum(array_map('count', $this->messages));
    }

    public function jsonSerialize(): array
    {
        return $this->messages;
    }

    public function __toString(): string
    {
        return implode(PHP_EOL, $this->all());
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Apply a format string to a list of messages.
     *
     * @param  string[] $messages
     * @return string[]
     */
    protected function format(array $messages, string $format, string $field): array
    {
        if ($format === ':message') {
            return $messages;
        }

        return array_map(
            fn(string $msg) => str_replace([':message', ':field'], [$msg, $field], $format),
            $messages
        );
    }
}