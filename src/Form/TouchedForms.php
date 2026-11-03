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

namespace Phem\Form;

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The touched fields of each root form that an event submitted (see
 * TouchedFieldsRequestHandler), for the view of the form (see TouchedFieldsExtension). A form
 * that goes away takes its entry with it.
 */
final class TouchedForms implements ResetInterface
{
    /** @var \WeakMap<FormInterface, TouchedFields> */
    private \WeakMap $forms;

    public function __construct()
    {
        $this->forms = new \WeakMap();
    }

    public function set(FormInterface $root, TouchedFields $touched): void
    {
        $this->forms[$root] = $touched;
    }

    /** The touched fields of the root of a form, or null when no event submitted it. */
    public function of(FormInterface $form): ?TouchedFields
    {
        return $this->forms[$form->getRoot()] ?? null;
    }

    public function reset(): void
    {
        $this->forms = new \WeakMap();
    }
}
