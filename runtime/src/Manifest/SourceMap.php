<?php

declare(strict_types=1);

namespace Rephp\Manifest;

/**
 * Maps the lines of the compiled templates to the lines of the `.rhp` sources.
 */
final class SourceMap
{
    public function __construct(private readonly Manifest $manifest)
    {
    }

    /**
     * The `.rhp` source and line of a line in a compiled template.
     *
     * @return array{file: string, path: string, line: int}|null
     */
    public function location(string $twigName, int $templateLine): ?array
    {
        $prefix = '@'.Manifest::TEMPLATE_NAMESPACE.'/';
        if (!str_starts_with($twigName, $prefix) || !$this->manifest->exists()) {
            return null;
        }
        $tpl = $this->manifest->template(substr($twigName, \strlen($prefix)));
        if (null === $tpl) {
            return null;
        }

        return [
            'file' => 'src/'.$tpl['source'],
            'path' => $this->manifest->sourceDir().'/'.$tpl['source'],
            'line' => $tpl['line'] + max(0, $templateLine - 1),
        ];
    }
}
