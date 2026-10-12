<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(Request $request): void
    {
        $path = rtrim($request->path, '/');
        $path = $path === '' ? '/' : $path;

        $routes = $this->routes[$request->method] ?? [];
        foreach ($routes as $routePattern => $handler) {
            $params = $this->match($routePattern, $path);
            if ($params !== null) {
                if (is_array($handler)) {
                    [$controllerName, $methodName] = $handler;
                    $controller = new $controllerName();
                    $controller->$methodName($request, $params);
                    return;
                }

                $handler($request, $params);
                return;
            }
        }

        $this->methodNotAllowed($request, $path);
    }

    private function match(string $routePattern, string $path): ?array
    {
        $routePattern = rtrim($routePattern, '/');
        $routePattern = $routePattern === '' ? '/' : $routePattern;

        if ($routePattern === $path) {
            return [];
        }

        $routeParts = explode('/', trim($routePattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($routeParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($routeParts as $index => $routePart) {
            if (str_starts_with($routePart, '{') && str_ends_with($routePart, '}')) {
                $key = trim(substr($routePart, 1, -1));
                $params[$key] = $pathParts[$index];
                continue;
            }

            if ($routePart !== $pathParts[$index]) {
                return null;
            }
        }

        return $params;
    }

    private function methodNotAllowed(Request $request, string $path): void
    {
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $routePattern => $_) {
                if ($this->match($routePattern, $path) !== null) {
                    if ($method !== $request->method) {
                        Response::json(405, false, null, 'El método HTTP no está permitido para esta ruta.', ['method' => $request->method]);
                        return;
                    }
                }
            }
        }

        Response::json(404, false, null, 'Ruta no encontrada.', ['path' => $path]);
    }
}
