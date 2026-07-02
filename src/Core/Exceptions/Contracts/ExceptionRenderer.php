<?php

/*
|--------------------------------------------------------------------------
| ExceptionRenderer Contract — Slenix Framework
|--------------------------------------------------------------------------
|
| Defines the interface every renderer must implement.
| Renderers are responsible for converting a Throwable into an HTTP response.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Contracts;

interface ExceptionRenderer
{
    /**
     * Render the given exception into an HTTP response string.
     *
     * @param  \Throwable $exception
     * @return string
     */
    public function render(\Throwable $exception): string;

    /**
     * Whether this renderer can handle the given exception.
     *
     * @param  \Throwable $exception
     * @return bool
     */
    public function canRender(\Throwable $exception): bool;
}