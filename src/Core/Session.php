<?php

namespace Lark\Core;

class Session
{
    function __construct($sessionId = null)
    {

    }

    function set(string $name, mixed $value): void
    {
        $_SESSION[$name] = $value;
    }

    function get(string $name, mixed $default = null): mixed
    {
        if (isset($_SESSION[$name])) {
            return $_SESSION[$name];
        }
        return $default;
    }

    function has(string $name): bool
    {
        return isset($_SESSION[$name]);
    }

    function delete(string $name): void
    {
        unset($_SESSION[$name]);
    }

    function all(): array
    {
        return $_SESSION;
    }

    function flush(): void
    {
        $_SESSION = [];
    }

    function destroy(): void
    {
        session_destroy();
    }
}