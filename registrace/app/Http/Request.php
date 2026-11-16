<?php
declare(strict_types=1);

namespace Bkl\Http;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $server
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $server = [],
        public string $body = '',
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }
        $path = '/' . trim(rawurldecode($uri), '/');
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            array_map('strval', array_filter($_SERVER, 'is_scalar')),
            (string) file_get_contents('php://input')
        );
    }

    public function q(string $k, string $d = ''): string
    {
        $v = $this->query[$k] ?? $d;
        return is_scalar($v) ? (string) $v : $d;
    }

    public function p(string $k, string $d = ''): string
    {
        $v = $this->post[$k] ?? $d;
        return is_scalar($v) ? (string) $v : $d;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : [];
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
