<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method'      => strtoupper($method),
            'pattern'     => $pattern,
            'regex'       => $this->convertPatternToRegex($pattern),
            'handler'     => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function get(string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->add('GET', $pattern, $handler, $middlewares);
    }

    public function post(string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->add('POST', $pattern, $handler, $middlewares);
    }

    public function put(string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $pattern, $handler, $middlewares);
    }

    public function patch(string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middlewares);
    }

    public function delete(string $pattern, callable|array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middlewares);
    }

    public function dispatch(Request $request): void
    {
        $requestMethod = $request->getMethod();
        $requestUri = rtrim($request->getUri(), '/');
        if ($requestUri === '') {
            $requestUri = '/';
        }

        // Handle CORS Pre-flight Options request
        if ($requestMethod === 'OPTIONS') {
            Response::json(['status' => 'ok']);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            if (preg_match($route['regex'], $requestUri, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                // Execute route middlewares
                foreach ($route['middlewares'] as $middleware) {
                    if (is_callable($middleware)) {
                        $middleware($request);
                    }
                }

                // Call Handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $class::$method($request, $params);
                    return;
                } elseif (is_callable($handler)) {
                    $handler($request, $params);
                    return;
                }
            }
        }

        Response::error("Endpoint '{$requestMethod} {$requestUri}' not found.", 404);
    }

    private function convertPatternToRegex(string $pattern): string
    {
        $trimmed = rtrim($pattern, '/');
        if ($trimmed === '') {
            return '#^/?$#';
        }

        // Convert {param} to (?P<param>[^/]+)
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $trimmed);
        return '#^' . $regex . '/?$#';
    }
}
