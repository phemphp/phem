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

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Button;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * The view of a form that an event submitted (see TouchedFieldsRequestHandler): it shows the
 * errors of the touched fields only, and it marks each touched widget with `data-phem-touched`.
 *
 * An error shows when the user touched the field where it comes from (its origin), also when it
 * bubbles to a parent. An error of the form itself (the CSRF token, the extra fields, a
 * constraint on the class) shows when the user touched all the fields: after a submit. The form
 * keeps all its errors: isValid() still checks all the fields.
 *
 * The browser sends the names of the marked fields with the next event of the form, so the
 * errors of these fields stay. A new form has no mark: the touched fields start again.
 */
final class TouchedFieldsExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly TouchedForms $forms,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $touched = $this->forms->of($form);
        if (null === $touched) {
            return;
        }

        // The widgets only: a compound form renders no field of its own.
        if (!$options['compound'] && $touched->touches(self::name($form))) {
            $view->vars['attr'][TouchedFields::MARK] = '';
        }

        if ($touched->isAll()) {
            return;
        }

        // The view of a child is finished before the view of its parent.
        $all = null;
        $errors = array_values(array_filter(
            iterator_to_array($view->vars['errors'], false),
            static function (FormError $error) use ($form, $touched, &$all): bool {
                $origin = $error->getOrigin() ?? $form;
                if ($origin->isRoot() && $origin->getConfig()->getCompound()) {
                    return $all ??= self::touchesAll($origin, $touched);
                }

                return $touched->touches(self::name($origin));
            },
        ));
        $view->vars['errors'] = new FormErrorIterator($form, $errors);
        $view->vars['valid'] = [] === $errors && !array_any($view->children, static fn (FormView $child): bool => false === $child->vars['valid']);
    }

    /** Whether the user touched each field of a form. A button is not a field. */
    private static function touchesAll(FormInterface $form, TouchedFields $touched): bool
    {
        foreach ($form as $child) {
            if ($child instanceof Button) {
                continue;
            }

            if ($child->getConfig()->getCompound() ? !self::touchesAll($child, $touched) : !$touched->touches(self::name($child))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The full name of a form: `signup[address][city]`. The view has it too (`full_name`), but
     * the origin of an error is a form, not a view.
     */
    private static function name(FormInterface $form): string
    {
        $parent = $form->getParent();
        if (null === $parent) {
            return $form->getName();
        }

        $name = self::name($parent);

        return '' === $name ? $form->getName() : $name.'['.$form->getName().']';
    }
}
