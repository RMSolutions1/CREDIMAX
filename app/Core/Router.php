<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<string, callable|array>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->map('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): void
    {
        $this->map('PUT', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): void
    {
        $this->map('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->map('DELETE', $path, $handler);
    }

    private function map(string $method, string $path, callable|array $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->normalize($path);
        $method = strtoupper($method);

        // method override
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
            if (is_string($override) && $override !== '') {
                $method = strtoupper($override);
            }
        }

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir)) ?: '/';
            $path = $this->normalize($path);
        }

        $handler = $this->routes[$method][$path] ?? null;
        $params = [];

        if ($handler === null) {
            foreach ($this->routes[$method] ?? [] as $route => $h) {
                $pattern = preg_replace('#\{([a-zA-Z_]+)\}#', '([^/]+)', $route);
                $pattern = '#^' . $pattern . '$#';
                if (preg_match($pattern, $path, $matches)) {
                    array_shift($matches);
                    $handler = $h;
                    $params = $matches;
                    break;
                }
            }
        }

        if ($handler === null) {
            if (str_starts_with($path, '/api/')) {
                http_response_code(404);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'not_found', 'message' => 'Endpoint no encontrado', 'path' => $path], JSON_UNESCAPED_UNICODE);
                return;
            }
            http_response_code(404);
            View::render('errors/404', ['title' => 'No encontrado']);
            return;
        }

        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->$action(...$params);
            return;
        }

        $handler(...$params);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
