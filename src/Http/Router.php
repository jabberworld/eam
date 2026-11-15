<?php
declare(strict_types=1);

namespace Eam\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    /** @var null|callable */
    private $notFound = null;

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function any(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
        $this->add('POST', $path, $handler);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $this->normalize($request->path());
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            if ($this->notFound !== null) {
                return ($this->notFound)($request);
            }
            return Response::text('Not Found', 404);
        }
        return $handler($request);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
