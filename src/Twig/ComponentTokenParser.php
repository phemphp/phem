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

namespace Phem\Twig;

use Phem\Manifest\ComponentKind;
use Phem\Manifest\Manifest;
use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * `{% component %}`: renders a component. phemc emits it for each component tag.
 *
 * Include form (a self-closing tag, `<MyButton count={{ n }} />`), built on `include`:
 *
 *     {% component 'App\\MyButton' with {count: n} key id only %}
 *
 * Embed form (a tag with children or slots), built on `embed`; the blocks are the slots:
 *
 *     {% component embed 'App\\Card' with {title: 'Hi'} only %}
 *         {% block children %}{% with _phem_caller only %}<p>Body</p>{% endwith %}{% endblock %}
 *     {% endcomponent %}
 *
 * The component is resolved from the manifest when Twig compiles the template. In a template of
 * the app, a component with a replacement (`#[Component(replaces: ...)]`) renders the replacement.
 * phemc already resolves the tags of its templates, and a tag of the original in the replacement
 * itself stays the original.
 */
final class ComponentTokenParser extends AbstractTokenParser
{
    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $line = $token->getLine();
        $embed = null !== $stream->nextIf(Token::NAME_TYPE, 'embed');
        $fqcn = ltrim((string) $stream->expect(Token::STRING_TYPE)->getValue(), '\\');
        if (!str_starts_with($stream->getSourceContext()->getName(), '@'.Manifest::TEMPLATE_NAMESPACE.'/')) {
            $fqcn = $this->manifest->replacement($fqcn) ?? $fqcn;
        }

        $def = $this->manifest->component($fqcn)
            ?? throw new SyntaxError(\sprintf('Unknown component "%s". Run `phemc build` again.', $fqcn), $line, $stream->getSourceContext());

        $variables = null !== $stream->nextIf(Token::NAME_TYPE, 'with') ? $this->parser->parseExpression() : null;
        $key = null !== $stream->nextIf(Token::NAME_TYPE, 'key') ? $this->parser->parseExpression() : null;
        $stream->nextIf(Token::NAME_TYPE, 'only');
        $stream->expect(Token::BLOCK_END_TYPE);

        $kind = $def->kind->value;
        $template = ComponentKind::TemplateComponent === $def->kind ? Manifest::templateName((string) $def->template) : null;
        if (!$embed) {
            return new ComponentIncludeNode($fqcn, $kind, $template, $variables, $key, $line);
        }

        // Like `embed`: for a template component, the body is a child template of the component
        // template. For a function or class component, it has no parent: the Renderer renders
        // only its blocks, as slots.
        if (null !== $template) {
            $stream->injectTokens([
                new Token(Token::BLOCK_START_TYPE, '', $line),
                new Token(Token::NAME_TYPE, 'extends', $line),
                new Token(Token::STRING_TYPE, $template, $line),
                new Token(Token::BLOCK_END_TYPE, '', $line),
            ]);
        }

        $module = $this->parser->parse($stream, static fn (Token $t): bool => $t->test('endcomponent'), true);
        $this->parser->embedTemplate($module);
        $stream->expect(Token::BLOCK_END_TYPE);

        // The blocks of the body are the slots that the caller gives.
        $slots = array_map(strval(...), array_keys(iterator_to_array($module->getNode('blocks'))));

        return new ComponentEmbedNode($fqcn, $kind, $stream->getSourceContext()->getName(), $module->getAttribute('index'), $slots, $variables, $key, $line);
    }

    public function getTag(): string
    {
        return 'component';
    }
}
