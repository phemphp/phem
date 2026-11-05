<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Manifest;

use Symfony\Component\Filesystem\Path;

/**
 * Maps the lines of the compiled templates to the lines of the `.phpm` sources.
 */
final class SourceMap
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly string $projectDir,
    ) {
    }

    /**
     * The `.phpm` source and line of a line in a compiled template. The file is the path from the
     * project root (`src/Ui/HomePage.phpm`), and the path is the absolute path.
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
            'file' => $tpl['source'],
            // A source outside the project (a package of a custom installer) has an absolute path.
            'path' => Path::isAbsolute($tpl['source']) ? $tpl['source'] : $this->projectDir.'/'.$tpl['source'],
            'line' => $tpl['line'] + max(0, $templateLine - 1),
        ];
    }
}
