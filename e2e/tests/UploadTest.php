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

namespace App\Tests;

use Playwright\Page\PageInterface;

use function Playwright\Testing\expect;

/**
 * The upload work in the browser: the files of an input with `data-phem-upload` go in chunks or
 * to a storage before their event, and phem-image-input shows and resizes the images
 * (e2e/src/Ui/Upload/UploadPage.phem). The chunks have 1024 bytes (config/phem.yaml).
 */
final class UploadTest extends E2eTestCase
{
    /**
     * Selects files in an input, as the user does. A file is {name, size, seed, type}: its bytes
     * are the same as bytes() gives.
     */
    private const PICK = <<<'JS'
        ([selector, specs]) => {
            const files = new DataTransfer()
            for (const { name, size, seed, type } of specs) {
                const bytes = new Uint8Array(size).map((_, i) => (i * 7 + seed) % 256)
                files.items.add(new File([bytes], name, { type, lastModified: 1700000000000 }))
            }
            const input = document.querySelector(selector)
            input.files = files.files
            input.dispatchEvent(new Event('change', { bubbles: true }))
        }
        JS;

    /**
     * Counts the tries of the chunk requests in `window.chunks` (their offsets), and makes the
     * chunks at the offsets of `fail` fail with a network error (a count of failures for each one).
     */
    private const WATCH_CHUNKS = <<<'JS'
        (fail) => {
            window.chunks = []
            const send = window.fetch
            window.fetch = (url, init = {}) => {
                const step = init.headers?.['X-Phem-Upload']
                if (step && step !== 'start') {
                    const offset = init.headers['X-Phem-Upload-Offset']
                    window.chunks.push(Number(offset))
                    if (fail[offset] > 0) {
                        fail[offset]--
                        return Promise.reject(new TypeError('Failed to fetch'))
                    }
                }
                return send(url, init)
            }
        }
        JS;

    /** Records the `phem:done` events in `window.done`, and the upload progress in `window.progress`. */
    private const WATCH_EVENTS = <<<'JS'
        () => {
            window.done = []
            window.progress = []
            window.addEventListener('phem:done', (e) => window.done.push(e.detail.status))
            window.addEventListener('phem:upload-progress', (e) => window.progress.push(e.detail.progress))
        }
        JS;

    /** Selects a red PNG of `width`×`height` in the photo input, and submits its form when `submit`. */
    private const PICK_PHOTO = <<<'JS'
        async ([width, height, submit]) => {
            const canvas = document.createElement('canvas')
            canvas.width = width
            canvas.height = height
            const context = canvas.getContext('2d')
            context.fillStyle = '#c00'
            context.fillRect(0, 0, width, height)
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
            const files = new DataTransfer()
            files.items.add(new File([blob], 'banner.png', { type: 'image/png' }))
            const input = document.querySelector('input[name=photo]')
            input.files = files.files
            input.dispatchEvent(new Event('change', { bubbles: true }))
            // The submit comes while the browser resizes the image.
            if (submit) input.form.requestSubmit()
        }
        JS;

    public function testChunkedUploadGivesTheFileToTheHandler(): void
    {
        $page = $this->open();
        $page->evaluate(self::WATCH_CHUNKS, (object) []);

        self::pick($page, 'input[name=video]', ['talk.mp4', 5000]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();

        expect($page->locator('#video'))->toHaveExactText('chunked talk.mp4 5000 '.md5(self::bytes(5000)));
        self::assertSame([0, 1024, 2048, 3072, 4096], $page->evaluate('() => window.chunks'), 'chunks of 1024 bytes');
        self::assertSame(0, $page->evaluate('() => document.querySelector("input[name=video]").files.length'), 'the handler took the file');
    }

    public function testChunkGoesAgainAfterANetworkError(): void
    {
        $page = $this->open();
        $page->evaluate(self::WATCH_CHUNKS, ['2048' => 1]);

        self::pick($page, 'input[name=video]', ['talk.mp4', 5000]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();

        expect($page->locator('#video'))->toHaveExactText('chunked talk.mp4 5000 '.md5(self::bytes(5000)));
        self::assertSame([0, 1024, 2048, 2048, 3072, 4096], $page->evaluate('() => window.chunks'));
    }

    public function testUploadContinuesAfterAReload(): void
    {
        $page = $this->open();
        // The network fails from the second chunk on, also for the tries: the event fails.
        $page->evaluate(self::WATCH_CHUNKS, ['1024' => 10]);
        self::pick($page, 'input[name=video]', ['talk.mp4', 5000]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();
        $page->waitForFunction('() => window.chunks.length === 5', null, ['timeout' => 10000]);
        expect($page->locator('#video'))->toHaveExactText('');

        // The user selects the same file again after a reload: the upload continues at the end
        // of the first chunk.
        $page->reload();
        $page->evaluate(self::WATCH_CHUNKS, (object) []);
        self::pick($page, 'input[name=video]', ['talk.mp4', 5000]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();

        expect($page->locator('#video'))->toHaveExactText('chunked talk.mp4 5000 '.md5(self::bytes(5000)));
        self::assertSame([1024, 2048, 3072, 4096], $page->evaluate('() => window.chunks'));
        self::assertSame(0, $page->evaluate('() => Object.keys(localStorage).filter((key) => key.startsWith("phem-upload:")).length'), 'the id of the upload goes at the end');
    }

    public function testProgressCountsTheChunks(): void
    {
        $page = $this->open();
        $page->evaluate(self::WATCH_EVENTS);

        self::pick($page, 'input[name=video]', ['talk.mp4', 5000]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();
        expect($page->locator('#video'))->toContainText('chunked talk.mp4');

        $progress = $page->evaluate('() => window.progress');
        self::assertGreaterThanOrEqual(5, \count($progress));
        $sorted = $progress;
        sort($sorted);
        self::assertSame($sorted, $progress, 'the progress goes up');
        self::assertEquals(1, end($progress));
        expect($page->locator('#video-progress'))->toBeHidden();
    }

    public function testFileOverTheMaximumSizeDoesNotGoUp(): void
    {
        $page = $this->open();
        $page->evaluate(self::WATCH_EVENTS);
        $page->evaluate(self::WATCH_CHUNKS, (object) []);

        // phem.max_upload_size is 8 MiB.
        self::pick($page, 'input[name=video]', ['big.mp4', 8388609]);
        $page->getByRole('button', ['name' => 'Upload the video'])->click();

        $page->waitForFunction('() => window.done.length === 1');
        self::assertSame([413], $page->evaluate('() => window.done'));
        self::assertSame([], $page->evaluate('() => window.chunks'));
        expect($page->locator('#video'))->toHaveExactText('');
    }

    public function testDirectUploadSendsTheFilesToTheStorage(): void
    {
        $page = $this->open();

        self::pick($page, 'input[name="docs[]"]', ['a.txt', 5, 1, 'text/plain'], ['b.pdf', 3000, 2, 'application/pdf']);
        $page->getByRole('button', ['name' => 'Upload the docs'])->click();

        expect($page->locator('#direct'))->toHaveExactText('a.txt text/plain 5 '.md5(self::bytes(5, 1)).', b.pdf application/pdf 3000 '.md5(self::bytes(3000, 2)));
        self::assertSame(0, $page->evaluate('() => document.querySelector("input[name=\'docs[]\']").files.length'));
    }

    public function testDropOnAZoneWithTheAttributeSendsTheFilesInChunks(): void
    {
        $page = $this->open();

        $page->evaluate(<<<'JS'
            () => {
                const files = new DataTransfer()
                files.items.add(new File([new Uint8Array(3000).map((_, i) => (i * 7) % 256)], 'a.bin'))
                files.items.add(new File([new Uint8Array(10).map((_, i) => (i * 7 + 3) % 256)], 'b.bin'))
                const zone = document.getElementById('zone')
                zone.dispatchEvent(new DragEvent('dragover', { dataTransfer: files, bubbles: true, cancelable: true }))
                zone.dispatchEvent(new DragEvent('drop', { dataTransfer: files, bubbles: true, cancelable: true }))
            }
            JS);

        expect($page->locator('#dropped'))->toHaveExactText('chunked a.bin 3000 '.md5(self::bytes(3000)).', chunked b.bin 10 '.md5(self::bytes(10, 3)));
    }

    public function testImageInputShowsAPreviewAndSendsASmallerImage(): void
    {
        $page = $this->open();

        $page->evaluate(self::PICK_PHOTO, [400, 200, false]);
        expect($page->locator('#preview img.thumb'))->toHaveAttribute('alt', 'banner.webp');
        self::assertStringStartsWith('blob:', (string) $page->locator('#avatar')->getAttribute('src'), 'an img target shows the first image');

        $page->getByRole('button', ['name' => 'Upload the photo'])->click();
        expect($page->locator('#photo'))->toHaveExactText('banner.webp 100x50 image/webp');
        expect($page->locator('#preview img'))->toHaveCount(0);
        expect($page->locator('#avatar'))->toHaveAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAAAAACw=');
    }

    public function testPreviewsStayAfterTheMorphOfAnEvent(): void
    {
        $page = $this->open();

        $page->evaluate(self::PICK_PHOTO, [400, 200, false]);
        expect($page->locator('#preview img'))->toHaveCount(1);
        $src = $page->locator('#avatar')->getAttribute('src');

        $page->getByRole('button', ['name' => 'Check 0'])->click();
        expect($page->getByRole('button', ['name' => 'Check 1']))->toBeVisible();

        expect($page->locator('#preview img'))->toHaveCount(1);
        expect($page->locator('#avatar'))->toHaveAttribute('src', (string) $src);
        self::assertSame(1, $page->evaluate('() => document.querySelector("input[name=photo]").files.length'));
    }

    public function testSubmitDuringTheResizeSendsTheSmallerImage(): void
    {
        $page = $this->open();

        $page->evaluate(self::PICK_PHOTO, [4000, 3000, true]);

        expect($page->locator('#photo'))->toHaveExactText('banner.webp 100x75 image/webp');
    }

    private function open(): PageInterface
    {
        $page = $this->visit('/upload');
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * Selects files in the input. A file is [name, size, seed, type].
     *
     * @param array{0: string, 1: int, 2?: int, 3?: string} ...$files
     */
    private static function pick(PageInterface $page, string $selector, array ...$files): void
    {
        $specs = array_map(static fn (array $f): array => ['name' => $f[0], 'size' => $f[1], 'seed' => $f[2] ?? 0, 'type' => $f[3] ?? 'video/mp4'], $files);
        $page->evaluate(self::PICK, [$selector, $specs]);
    }

    /** The bytes of a file of pick(). */
    private static function bytes(int $size, int $seed = 0): string
    {
        $bytes = '';
        for ($i = 0; $i < $size; ++$i) {
            $bytes .= \chr(($i * 7 + $seed) % 256);
        }

        return $bytes;
    }
}
