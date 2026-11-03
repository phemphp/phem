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

namespace Phem\Http;

use Phem\Form\TouchedFields;
use Symfony\Component\HttpFoundation\Request;

/**
 * The request that a handler gets (`Request $request`): a copy of the event request with the
 * form fields as its POST data, so a Symfony form reads them as a normal form submission
 * (`$form->handleRequest($request)`). The browser sends the event as JSON.
 *
 * An event of a form also gives the fields that the user touched (see Phem\Form\TouchedFields):
 * all the fields for a submit, and the fields that the browser sends for another event.
 */
final class EventRequest
{
    /**
     * @param array<string, mixed> $event the data of the event (see Phem\Event::fromArray())
     */
    public static function of(Request $request, array $event): Request
    {
        // FormData gives flat keys ("scientist[name]", "tags[]"). PHP makes nested arrays of
        // them, as it does for the body of a normal POST.
        parse_str(implode('&', array_map(static fn (array $f): string => rawurlencode($f[0]).'='.rawurlencode($f[1]), FormFields::of($event['form'] ?? null))), $post);

        $copy = $request->duplicate(request: $post);
        if (\is_array($event['form'] ?? null)) {
            $copy->attributes->set(TouchedFields::REQUEST_ATTRIBUTE, 'submit' === ($event['type'] ?? null) ? TouchedFields::all() : TouchedFields::of($event['touched'] ?? null));
        }

        return $copy;
    }
}
