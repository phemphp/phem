<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Manifest\ComponentKind;
use Rephp\Manifest\Manifest;
use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * `{% component %}`: renders a component. rhpc emits it for each component tag.
 *
 * Include form (a self-closing tag, `<MyButton count={{ n }} />`), built on `include`:
 *
 *     {% component 'App\\MyButton' with {count: n} key id only %}
 *
 * Embed form (a tag with children or slots), built on `embed`; the blocks are the slots:
 *
 *     {% component embed 'App\\Card' with {title: 'Hi'} only %}
 *         {% block children %}{% with _rhp_caller only %}<p>Body</p>{% endwith %}{% endblock %}
 *     {% endcomponent %}
 *
 * The component is resolved from the manifest when Twig compiles the template.
 */
final class ComponentTokenParser extends AbstractTokenParser
{
    public const SLOT_HOST = '@Rephp/slot_host.twig';

    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $line = $token->getLine();
        $embed = null !== $stream->nextIf(Token::NAME_TYPE, 'embed');
        $fqcn = ltrim((string) $stream->expect(Token::STRING_TYPE)->getValue(), '\\');
        $def = $this->manifest->component($fqcn)
            ?? throw new SyntaxError(\sprintf('Unknown component "%s". Run `rhpc build` again.', $fqcn), $line, $stream->getSourceContext());

        $variables = null !== $stream->nextIf(Token::NAME_TYPE, 'with') ? $this->parser->parseExpression() : null;
        $key = null !== $stream->nextIf(Token::NAME_TYPE, 'key') ? $this->parser->parseExpression() : null;
        $stream->nextIf(Token::NAME_TYPE, 'only');
        $stream->expect(Token::BLOCK_END_TYPE);

        $kind = $def->kind->value;
        $template = ComponentKind::TemplateComponent === $def->kind ? Manifest::templateName((string) $def->template) : null;
        if (!$embed) {
            return new ComponentIncludeNode($fqcn, $kind, $template, $variables, $key, $line);
        }

        // Like `embed`: the body is a child template of the component template (or of an
        // empty host template, for function and class components, which render the blocks as slots).
        $stream->injectTokens([
            new Token(Token::BLOCK_START_TYPE, '', $line),
            new Token(Token::NAME_TYPE, 'extends', $line),
            new Token(Token::STRING_TYPE, $template ?? self::SLOT_HOST, $line),
            new Token(Token::BLOCK_END_TYPE, '', $line),
        ]);
        $module = $this->parser->parse($stream, static fn (Token $t): bool => $t->test('endcomponent'), true);
        $this->parser->embedTemplate($module);
        $stream->expect(Token::BLOCK_END_TYPE);

        return new ComponentEmbedNode($fqcn, $kind, $module->getTemplateName(), $module->getAttribute('index'), $variables, $key, $line);
    }

    public function getTag(): string
    {
        return 'component';
    }
}
