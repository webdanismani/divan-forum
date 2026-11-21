<?php

namespace App\Forum\Addons;

use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Named places where add-ons put their own output or change a value.
 *
 * Templates print a place with @hook('layout.footer'); add-ons attach to it
 * with $this->hook('layout.footer', fn (array $context) => view('my-addon::line')).
 * What a listener returns is printed as HTML, so add-ons escape their own data.
 */
class Hooks
{
    /** @var array<string, callable[]> */
    protected array $listeners = [];

    public function listen(string $name, callable $listener): void
    {
        $this->listeners[$name][] = $listener;
    }

    public function has(string $name): bool
    {
        return ! empty($this->listeners[$name]);
    }

    public function render(string $name, array $context = []): string
    {
        $out = '';
        foreach ($this->listeners[$name] ?? [] as $listener) {
            try {
                $result = $listener($context);
                $out .= $result instanceof Htmlable ? $result->toHtml() : (string) $result;
            } catch (Throwable $e) {
                report($e); // a broken add-on must not take the page down
            }
        }

        return $out;
    }

    /** Pass a value through every listener of a filter and return the result. */
    public function filter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->listeners[$name] ?? [] as $listener) {
            try {
                $value = $listener($value, ...$arguments);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $value;
    }
}
