<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthorizationService;
use App\Middleware\ModuleAccessService;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, string $capability): void
    {
        if (!in_array(strtoupper($method), ['GET', 'POST', 'DELETE'], true)) {
            throw new \InvalidArgumentException('La API solo admite rutas GET, POST y DELETE.');
        }
        $this->routes[strtoupper($method)][$path] = [
            'handler' => $handler,
            'capability' => $capability,
        ];
    }

    public function get(string $path, callable|array $handler, string $capability): void
    {
        $this->add('GET', $path, $handler, $capability);
    }

    public function post(string $path, callable|array $handler, string $capability): void
    {
        $this->add('POST', $path, $handler, $capability);
    }

    public function delete(string $path, callable|array $handler, string $capability): void
    {
        $this->add('DELETE', $path, $handler, $capability);
    }

    public function dispatch(Request $request): void
    {
        $path = rtrim($request->path, '/');
        $path = $path === '' ? '/' : $path;

        $routes = $this->routes[$request->method] ?? [];
        foreach ($routes as $routePattern => $route) {
            $params = $this->match($routePattern, $path);
            if ($params !== null) {
                if ($route['capability'] !== 'public') {
                    $user = AuthorizationService::requireCapability(
                        $request->headers,
                        $route['capability'],
                    );
                    if ($user['must_change_password']
                        && !in_array($route['capability'], ['users.password.self', 'users.session.read'], true)) {
                        throw new HttpException(403, 'Debes cambiar tu contraseña antes de continuar.');
                    }
                    $module = ModuleAccessService::moduleForPath($path);
                    if ($module !== null && !ModuleAccessService::canAccess($user, $module)) {
                        throw new HttpException(403, 'No tienes acceso a este módulo.');
                    }
                    if (!ModuleAccessService::canAccessResource($user, $path)) {
                        throw new HttpException(403, 'No tienes acceso a recursos de este torneo.');
                    }
                }

                $handler = $route['handler'];
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
        foreach ($this->routes as $routes) {
            foreach ($routes as $routePattern => $_) {
                if ($this->match($routePattern, $path) !== null) {
                    header('Allow: GET, POST, DELETE, OPTIONS');
                    Response::json(405, false, null, 'El método HTTP no está permitido para esta ruta.', ['method' => $request->method]);
                    return;
                }
            }
        }

        Response::json(404, false, null, 'Ruta no encontrada.', ['path' => $path]);
    }
}
