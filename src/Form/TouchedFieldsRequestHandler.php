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
use Symfony\Component\Form\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The live validation of a Symfony form: it keeps the touched fields of the event that submitted
 * a root form, for its view. It decorates the request handler of FrameworkBundle.
 *
 * The request of an event of a form has its touched fields (see Phem\Http\EventRequest). The
 * form keeps all its errors, so isValid() checks all the fields: the browser chooses the touched
 * fields, and it cannot skip a constraint or the CSRF token. Only the view hides the errors of
 * the other fields (see TouchedFieldsExtension). A request that is not an event of a form works
 * as before.
 */
final readonly class TouchedFieldsRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $inner,
        private TouchedForms $forms,
    ) {
    }

    public function handleRequest(FormInterface $form, mixed $request = null): void
    {
        $this->inner->handleRequest($form, $request);

        $touched = $request instanceof Request ? $request->attributes->get(TouchedFields::REQUEST_ATTRIBUTE) : null;
        if ($touched instanceof TouchedFields && $form->isRoot() && $form->isSubmitted()) {
            $this->forms->set($form, $touched);
        }
    }

    public function isFileUpload(mixed $data): bool
    {
        return $this->inner->isFileUpload($data);
    }

    /** The error of a file upload, as the inner handler gives it (see FileType). */
    public function getUploadFileError(mixed $data): ?int
    {
        return method_exists($this->inner, 'getUploadFileError') ? $this->inner->getUploadFileError($data) : null;
    }
}
