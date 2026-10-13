<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal pattern router: routes are matched in registration order and
 * {placeholders} are captured as named arguments.
 */
final class Router
{
    /** @var array<int, array{pattern:string, methods:array<int,string>, handler:callable|array{0:class-string,1:string}}> */
    private array $routes = [];

    /**
     * @param string                                                     $methods e.g. 'GET' or 'GET|POST'
     * @param callable|array{0: class-string, 1: string}                 $handler
     */
    public function add(string $methods, string $pattern, $handler): self
    {
        $this->routes[] = [
            'pattern' => $pattern,
            'methods' => array_map('strtoupper', explode('|', strtoupper($methods))),
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Match and run. Returns false when nothing matched so the caller can
     * answer with a 404.
     *
     * @param array<string, string> $params
     */
    public function dispatch(string $method, string $path, array $extra = []): bool
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if (!in_array($method, $route['methods'], true) && !in_array('ANY', $route['methods'], true)) {
                continue;
            }

            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $path, $matches) !== 1) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $params = array_map('rawurldecode', $params) + $extra;

            $handler = $route['handler'];

            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action($params);
            } elseif (is_string($handler) && strpos($handler, '@') !== false) {
                // "App\Controllers\DashboardController@index"
                [$class, $action] = explode('@', $handler, 2);
                (new $class())->{$action}($params);
            } else {
                $handler($params);
            }

            return true;
        }

        return false;
    }
}
