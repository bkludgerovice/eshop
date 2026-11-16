<?php
declare(strict_types=1);

namespace Bkl;

/** Jednoduché PHP šablony. Všechny výstupy escapovat přes $e(). */
final class View
{
    public function __construct(private string $dir, private Config $config)
    {
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = null): string
    {
        $vars += [
            'e' => static fn ($s): string => Util::e($s),
            'money' => static fn (int $m, string $c = 'CZK'): string => Util::money($m, $c),
            'tr' => static fn ($j): string => Util::tr($j),
            'url' => fn (string $p = ''): string => $this->config->baseUrl() . '/' . ltrim($p, '/'),
            'asset' => fn (string $p): string => $this->config->baseUrl() . '/assets/' . $p . '?v=' . (string) @filemtime(dirname($this->dir) . '/assets/' . $p),
            'config' => $this->config,
        ];
        $content = $this->partial($template, $vars);
        if ($layout !== null) {
            return $this->partial($layout, $vars + ['content' => $content]);
        }
        return $content;
    }

    /** @param array<string,mixed> $vars */
    public function partial(string $template, array $vars): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Šablona nenalezena: ' . $template);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
