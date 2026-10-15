<?php

declare(strict_types=1);

namespace Rephp;

use Twig\Error\Error as TwigError;

/**
 * The debug error page. Twig errors point to the .rhp source line.
 */
final class ErrorPage
{
    public static function render(\Throwable $e, Runtime $runtime): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $where = self::location($e, $runtime) ?? $e->getFile().':'.$e->getLine();
        $inner = $e;
        while ($inner instanceof TwigError && null !== $inner->getPrevious()) {
            $inner = $inner->getPrevious();
        }
        $message = $inner instanceof TwigError ? $inner->getRawMessage() : $inner->getMessage();

        return '<!DOCTYPE html><meta charset="utf-8"><title>Error</title>'
            .'<style>body{font:15px/1.5 system-ui;margin:2rem;color:#23272f}pre{background:#f6f7f9;padding:1rem;overflow:auto;font-size:12px}h1{font-size:1.3rem;color:#c1272d}</style>'
            .'<h1>'.$h(get_debug_type($inner)).': '.$h($message).'</h1><p><code>'.$h($where).'</code></p><pre>'.$h($inner->getTraceAsString()).'</pre>';
    }

    /** `src/pages/x.rhp:12` for an error in a compiled template. */
    public static function location(\Throwable $e, Runtime $runtime): ?string
    {
        for (; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof TwigError && null !== $e->getSourceContext()) {
                $tpl = $runtime->manifest['templates'][$e->getSourceContext()->getName()] ?? null;
                if (null !== $tpl) {
                    return 'src/'.$tpl['source'].':'.($tpl['line'] + max(0, $e->getTemplateLine() - 1));
                }
            }
        }

        return null;
    }
}
