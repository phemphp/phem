<?php

declare(strict_types=1);

namespace Rephp;

/**
 * Helpers that the compiled code calls.
 */
final class Rt
{
    /**
     * Renders the template of a markup literal. rhpc compiles `<div>{{ x }}</div>` to
     * `Rt::render('file.0.twig', ['x' => $x])`.
     *
     * @param array<string, mixed> $vars
     */
    public static function render(string $template, array $vars): Html
    {
        foreach ($vars as $name => $value) {
            if (HtmlList::accepts($value)) {
                $vars[$name] = new HtmlList($value);
            }
        }

        return new Html(Runtime::get()->twig()->render($template, $vars));
    }

    /**
     * The contents of a source file under `src/` (for example to show the code of an example).
     */
    public static function source(string $relativePath): string
    {
        $root = realpath(Runtime::get()->projectDir.'/src');
        $file = realpath($root.'/'.$relativePath);
        if (false === $root || false === $file || !str_starts_with($file, $root.'/')) {
            throw new \InvalidArgumentException(\sprintf('No source file "%s".', $relativePath));
        }

        return (string) file_get_contents($file);
    }
}
