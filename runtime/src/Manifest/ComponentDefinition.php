<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Manifest;

/**
 * The definition of a component in the mamuc manifest.
 */
final class ComponentDefinition
{
    /**
     * @param string|null  $template the template name of a template component, without the Twig namespace
     * @param list<string> $slots    the slot names (`children` and the named slots)
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly ComponentKind $kind,
        public readonly ?string $template = null,
        public readonly array $slots = [],
    ) {
    }

    /** @param array<string, mixed> $data an entry of the `components` list of the manifest */
    public static function fromArray(string $fqcn, array $data): self
    {
        $kind = (string) ($data['kind'] ?? '');

        return new self(
            $fqcn,
            ComponentKind::tryFrom($kind) ?? throw new \UnexpectedValueException(\sprintf('The component "%s" has the unknown kind "%s". Use the same version of mamuc and mamuphp/runtime.', $fqcn, $kind)),
            isset($data['template']) ? (string) $data['template'] : null,
            array_values($data['slots'] ?? []),
        );
    }
}
