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

namespace Phem\Tests\Runtime;

use Phem\DependencyInjection\DirectUploaderPass;
use Phem\Handler\HandlerFingerprint;
use Phem\Http\EventBody;
use Phem\State\StateCodec;
use Phem\Tests\Fixtures\FakeDirectUploader;
use Phem\Upload\ChunkedUploadMode;
use Phem\Upload\ChunkStore;
use Phem\Upload\DirectUploader;
use Phem\Upload\UploadResolver;
use Phem\Upload\UploadToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The files of an input with `data-phem-upload` go up before their event (see
 * Phem\Upload\UploadController), and the event sends their tokens (see Phem\Upload\UploadResolver).
 * The handler of tests/Fixtures/app/src/Ui/Upload/UploadsPage.phem tells what it got.
 */
final class UploadTest extends TestCase
{
    use FixtureApp;

    private const PAGE = 'App\\Ui\\Upload\\UploadsPage';

    protected function setUp(): void
    {
        self::received([]);
    }

    public function testChunkedUploadGivesAFileToTheHandler(): void
    {
        $start = $this->start(['mode' => 'chunked', 'name' => 'v.mp4', 'type' => 'video/mp4', 'size' => 10]);
        self::assertSame(200, $start->getStatusCode());
        self::assertSame('no-store, private', $start->headers->get('Cache-Control'));
        $data = self::json($start);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['id']);
        self::assertSame(['offset' => 0, 'chunkSize' => 2097152], array_diff_key($data, ['id' => true]));

        self::assertSame(['offset' => 4], self::json($this->chunk($data['id'], 0, 'abcd')));
        $last = self::json($this->chunk($data['id'], 4, 'efghij'));
        self::assertSame(10, $last['offset']);

        $response = $this->send([['field' => 'video', 'token' => $last['token']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['file v.mp4 video/mp4 abcdefghij', 'request video'], self::received());
        self::assertFileDoesNotExist($this->store()->path($data['id']), 'the upload goes after the event, as a PHP upload');

        self::assertSame(400, $this->send([['field' => 'video', 'token' => $last['token']]])->getStatusCode(), 'an upload is for one event');
    }

    public function testFileThatTheHandlerMovesStays(): void
    {
        @unlink(sys_get_temp_dir().'/phem-moved-upload');
        $token = $this->upload('v.mp4', 'abc');

        $this->send([['field' => 'video', 'token' => $token]], value: 'move');

        self::assertStringEqualsFile(sys_get_temp_dir().'/phem-moved-upload', 'abc');
        unlink(sys_get_temp_dir().'/phem-moved-upload');
    }

    public function testChunkStartsAtTheEndOfTheUpload(): void
    {
        $id = self::json($this->start(['mode' => 'chunked', 'name' => 'v.mp4', 'type' => '', 'size' => 6]))['id'];
        $this->chunk($id, 0, 'abc');

        $again = $this->chunk($id, 0, 'abc');
        self::assertSame(409, $again->getStatusCode(), 'a chunk that arrived two times');
        self::assertSame(['offset' => 3], self::json($again));
        self::assertSame(409, $this->chunk($id, 5, 'f')->getStatusCode(), 'a chunk after a missing chunk');

        self::assertSame(400, $this->chunk($id, 3, 'defg')->getStatusCode(), 'more bytes than the size of the file');
        self::assertSame(['offset' => 3], self::json($this->chunk($id, 0, 'x')), 'a chunk too large adds no byte');
        self::assertSame(404, $this->chunk(str_repeat('0', 32), 0, 'a')->getStatusCode());
        self::assertSame(404, $this->chunk('../../etc/passwd', 0, 'a')->getStatusCode());
        self::assertSame(400, $this->request('POST', '/uploads', 'a', ['HTTP_X_PHEM_UPLOAD' => $id, 'HTTP_SEC_FETCH_SITE' => 'same-origin'])->getStatusCode(), 'a chunk has an offset');
    }

    public function testUploadContinuesAfterAReload(): void
    {
        $info = ['mode' => 'chunked', 'name' => 'v.mp4', 'type' => 'video/mp4', 'size' => 6, 'fingerprint' => 'f1'];
        $id = self::json($this->start($info))['id'];
        $this->chunk($id, 0, 'abcd');

        self::assertSame(['offset' => 4, 'id' => $id], array_intersect_key(self::json($this->start([...$info, 'resume' => $id])), ['offset' => true, 'id' => true]));
        self::assertNotSame($id, self::json($this->start([...$info, 'size' => 7, 'resume' => $id]))['id'], 'another file starts again');
        self::assertNotSame($id, self::json($this->start([...$info, 'resume' => '../x']))['id']);
        self::assertNotSame($id, self::json($this->start([...$info, 'fingerprint' => 'f2', 'resume' => $id]))['id'], 'another file with the same name and size');

        $blind = ['mode' => 'chunked', 'name' => 'w.mp4', 'type' => '', 'size' => 6];
        $other = self::json($this->start($blind))['id'];
        self::assertNotSame($other, self::json($this->start([...$blind, 'resume' => $other]))['id'], 'no fingerprint: no resume');
    }

    public function testEmptyFileHasItsTokenAtTheStart(): void
    {
        $data = self::json($this->start(['mode' => 'chunked', 'name' => 'empty.txt', 'type' => 'text/plain', 'size' => 0]));

        self::assertSame(0, $data['offset']);
        self::assertIsString($data['token']);
    }

    public function testDirectUploadGivesTheUrlOfTheStorage(): void
    {
        $data = self::json($this->start(['mode' => 'direct', 'name' => 'a b.png', 'type' => 'image/png', 'size' => 42]));

        self::assertSame(['url' => 'https://bucket.example/uploads/42-a-b.png?signature=1', 'method' => 'PUT', 'headers' => ['Content-Type' => 'image/png']], array_diff_key($data, ['token' => true]));

        $response = $this->send([['field' => 'photos[]', 'token' => $data['token']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['direct uploads/42-a-b.png a b.png image/png 42'], self::received(), 'a direct file is not in the files of the request');
    }

    public function testUploadsOfADropAreAList(): void
    {
        $token = $this->upload('a.png', 'alpha');
        $other = $this->upload('b.png', 'beta');

        $this->send([['field' => '_phem_files[]', 'token' => $token], ['field' => '_phem_files[]', 'token' => $other]], type: 'drop');

        self::assertSame(['file a.png application/octet-stream alpha', 'file b.png application/octet-stream beta', 'request _phem_files'], self::received());
    }

    public function testUploadsGoWithTheFilesOfTheEvent(): void
    {
        $token = $this->upload('b.txt', 'beta');
        $photo = tempnam(sys_get_temp_dir(), 'phem');
        file_put_contents($photo, 'alpha');

        $this->send([['field' => 'doc[files][]', 'token' => $token]], ['doc' => ['files' => [new UploadedFile($photo, 'a.txt', 'text/plain', test: true)]]]);

        self::assertSame(['file a.txt text/plain alpha', 'file b.txt application/octet-stream beta', 'request doc'], self::received());
    }

    public function testUploadNeedsAValidToken(): void
    {
        $token = $this->upload('a.txt', 'alpha');
        [$payload, $hmac] = explode('.', $token);

        self::assertSame(400, $this->send([['field' => 'video', 'token' => $payload.'.x'.$hmac]])->getStatusCode());
        self::assertSame(400, $this->send([['field' => 'video']])->getStatusCode());
        self::assertSame(400, $this->send(['not json'])->getStatusCode());
        self::assertSame([], self::received(), 'the handler does not run');

        $other = new UploadToken('another secret')->sign(['mode' => 'direct', 'key' => 'k', 'name' => 'a', 'type' => '', 'size' => 1]);
        self::assertSame(400, $this->send([['field' => 'video', 'token' => $other]])->getStatusCode(), 'a token of another secret');
    }

    public function testStartChecksTheFile(): void
    {
        self::assertSame(413, $this->start(['mode' => 'chunked', 'name' => 'big', 'type' => '', 'size' => 8388609])->getStatusCode(), 'phem.max_upload_size');
        self::assertSame(400, $this->start(['mode' => 'chunked', 'name' => '', 'type' => '', 'size' => 1])->getStatusCode());
        self::assertSame(400, $this->start(['mode' => 'chunked', 'name' => 'a', 'type' => '', 'size' => -1])->getStatusCode());
        self::assertSame(400, $this->start(['mode' => 'other', 'name' => 'a', 'type' => '', 'size' => 1])->getStatusCode());
        self::assertSame(400, $this->start(['name' => 'a', 'type' => '', 'size' => 1])->getStatusCode());
        self::assertSame(400, $this->request('POST', '/uploads', 'no json', ['HTTP_X_PHEM_UPLOAD' => 'start', 'HTTP_SEC_FETCH_SITE' => 'same-origin'])->getStatusCode());
    }

    public function testUploadComesFromTheSameOrigin(): void
    {
        $body = json_encode(['mode' => 'chunked', 'name' => 'a', 'type' => '', 'size' => 1], \JSON_THROW_ON_ERROR);

        self::assertSame(403, $this->request('POST', '/uploads', $body, ['HTTP_X_PHEM_UPLOAD' => 'start', 'HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/uploads', $body, ['HTTP_X_PHEM_UPLOAD' => 'start', 'HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
    }

    public function testTokenExpires(): void
    {
        $tokens = new UploadToken('s3cret');
        $token = $tokens->sign(['mode' => 'direct', 'key' => 'k']);

        self::assertSame(['mode' => 'direct', 'key' => 'k'], $tokens->verify($token));

        $expired = rtrim(strtr(base64_encode(json_encode(['mode' => 'direct', 'exp' => time() - 1], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $hmac = rtrim(strtr(base64_encode(hash_hmac('sha256', "upload\n".$expired, 's3cret', true)), '+/', '-_'), '=');
        self::assertNull($tokens->verify($expired.'.'.$hmac));
        self::assertNull($tokens->verify('nope'));
    }

    public function testUploadsOfTheStoreHaveAMaximumSizeTogether(): void
    {
        $store = new ChunkStore(sys_get_temp_dir().'/phem-uploads-'.bin2hex(random_bytes(4)), 10);
        $store->start(['name' => 'a', 'type' => '', 'size' => 6, 'fingerprint' => null]);

        $this->expectException(\OverflowException::class);
        $store->start(['name' => 'b', 'type' => '', 'size' => 5, 'fingerprint' => null]);
    }

    public function testFullStoreAnswers507(): void
    {
        $mode = new ChunkedUploadMode(new ChunkStore(sys_get_temp_dir().'/phem-uploads-'.bin2hex(random_bytes(4)), 4), new UploadToken('s'));

        try {
            $mode->start('a', '', 5, []);
            self::fail('A full store takes no upload.');
        } catch (HttpException $e) {
            self::assertSame(507, $e->getStatusCode());
            self::assertSame('60', $e->getHeaders()['Retry-After']);
        }
    }

    public function testUploadsCountInTheMaximumSizeOfTheEvent(): void
    {
        $tokens = new UploadToken('s');
        $store = new ChunkStore(sys_get_temp_dir().'/phem-uploads-'.bin2hex(random_bytes(4)));
        $mode = new ChunkedUploadMode($store, $tokens);
        $parts = [];
        foreach (['a', 'b'] as $name) {
            $id = $mode->start($name, '', 6, [])['id'];
            $chunk = fopen('php://memory', 'r+');
            self::assertNotFalse($chunk);
            fwrite($chunk, 'abcdef');
            rewind($chunk);
            $store->append($id, 0, $chunk);
            $parts[] = json_encode(['field' => 'files[]', 'token' => $tokens->sign(['mode' => 'chunked', 'id' => $id, 'size' => 6])], \JSON_THROW_ON_ERROR);
        }

        $modes = new ServiceLocator(['chunked' => static fn (): ChunkedUploadMode => $mode]);
        $request = Request::create('/', 'POST', [UploadResolver::PART => $parts]);
        self::assertCount(2, new UploadResolver($tokens, $modes, 12)->resolve($request)->files['files']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('maximum of 11 bytes together');
        new UploadResolver($tokens, $modes, 11)->resolve(Request::create('/', 'POST', [UploadResolver::PART => $parts]));
    }

    public function testDirectUploaderIsTheOneServiceThatImplementsTheInterface(): void
    {
        $container = new ContainerBuilder();
        $container->register('a', FakeDirectUploader::class)->addTag(DirectUploaderPass::TAG);
        new DirectUploaderPass()->process($container);
        self::assertSame('a', (string) $container->getAlias(DirectUploaderPass::TAG));

        $container->register('b', FakeDirectUploader::class)->addTag(DirectUploaderPass::TAG);
        $container->removeAlias(DirectUploaderPass::TAG);
        try {
            new DirectUploaderPass()->process($container);
            self::fail('Two services implement the interface.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('give the alias', $e->getMessage());
        }

        // The alias of the application chooses.
        $container->setAlias(DirectUploader::class, 'b');
        new DirectUploaderPass()->process($container);
        self::assertSame(DirectUploader::class, (string) $container->getAlias(DirectUploaderPass::TAG));
    }

    /** @param array<string, mixed> $data */
    private function start(array $data): Response
    {
        return $this->request('POST', '/uploads', json_encode($data, \JSON_THROW_ON_ERROR), ['HTTP_X_PHEM_UPLOAD' => 'start', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function chunk(string $id, int $offset, string $bytes): Response
    {
        return $this->request('POST', '/uploads', $bytes, ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PHEM_UPLOAD' => $id, 'HTTP_X_PHEM_UPLOAD_OFFSET' => (string) $offset, 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /** The token of a chunked upload of the bytes, in one chunk. */
    private function upload(string $name, string $bytes): string
    {
        $id = self::json($this->start(['mode' => 'chunked', 'name' => $name, 'type' => '', 'size' => \strlen($bytes)]))['id'];

        return self::json($this->chunk($id, 0, $bytes))['token'];
    }

    /**
     * Sends an event of UploadsPage with the parts of its uploads.
     *
     * @param list<mixed>          $uploads the `_phem_uploads[]` parts, as JSON
     * @param array<string, mixed> $files   the files of the event
     */
    private function send(array $uploads, array $files = [], string $type = 'click', ?string $value = null): Response
    {
        [$state, $signature] = $this->service('phem.state_codec', StateCodec::class)->encode([], self::PAGE);
        $handler = self::handler(self::PAGE, 'receive');
        $json = json_encode(['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => $type, 'value' => $value]], \JSON_THROW_ON_ERROR);
        $parts = [EventBody::PART => $json, '_phem_uploads' => array_map(static fn (mixed $u): string => \is_string($u) ? $u : json_encode($u, \JSON_THROW_ON_ERROR), $uploads)];
        $server = ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        $kernel = $this->fixtureKernel();
        $request = Request::create('/uploads', 'POST', $parts, [], $files, $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /** The id of a handler of the <Root> of a page (a template component, with no fiber). */
    private static function handler(string $page, string $method): string
    {
        return 'phem'.substr(hash('xxh128', '/'.$page.'#0/Phem\\Root#0'), 0, 12).'.'.new HandlerFingerprint()->of(new $page()->{$method}(...));
    }

    private function store(): ChunkStore
    {
        return $this->service('phem.chunk_store', ChunkStore::class);
    }

    /**
     * The files that the handler got, or sets them.
     *
     * @param list<string>|null $set
     *
     * @return list<string>
     */
    private static function received(?array $set = null): array
    {
        $class = self::PAGE;
        if (null !== $set && class_exists($class)) {
            $class::$received = $set;
        }

        return class_exists($class) ? $class::$received : [];
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
