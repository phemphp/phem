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

namespace Phem\Attribute;

/**
 * Runs the slow part of an event handler in a Messenger worker, after the response.
 *
 * The method returns a Phem\Async\Promise with the slow work in a closure:
 *
 *     #[Async]
 *     public function export(Event $e): Promise
 *     {
 *         $this->status = 'pending';        // in the request, and again in the worker
 *         $id = $this->id;
 *
 *         return new Promise(function () use ($id): void {   // only in the worker
 *             $file = $this->exports->run($id);
 *             $this->streams->to(ExportPage::class)->update('export', <a href="{{ file }}">Download</a>);
 *         });
 *     }
 *
 * In the request, the method runs as a normal handler, and the event renders as usual. Phem
 * does not run the closure: it dispatches a Phem\Async\AsyncHandlerMessage. In the worker, Phem
 * renders the page again to the handler (with the state of the event), calls the method again,
 * and runs the closure. The state changes and the pushes of this second call are dropped. The
 * worker has the user of the request.
 *
 * - The method body before `return` runs two times: keep it fast, with no side effects. The
 *   request of the worker has the route values and the form fields, but no session, no cookies
 *   and no files.
 * - The closure can run more than one time (a retry of Messenger): make it idempotent.
 * - The worker has no response: the closure pushes its result with Phem\Stream\Streams.
 * - With #[Transactional], the closure runs in a transaction in the worker.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Async
{
}
