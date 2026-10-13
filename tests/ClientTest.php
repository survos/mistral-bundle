<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\MistralBundle\Client\ClientFactory;
use Survos\MistralBundle\Dto\BatchRequest;
use Survos\MistralBundle\Dto\FileReference;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    private static function fileData(): array
    {
        return ['id' => 'f1', 'filename' => 'archive.pdf', 'bytes' => 123, 'mimetype' => 'application/pdf', 'purpose' => 'ocr'];
    }

    private static function jobData(): array
    {
        return ['id' => 'j1', 'status' => 'SUCCESS', 'output_file' => 'out', 'total_requests' => 2, 'completed_requests' => 2];
    }

    public function testCustomEndpointTypedNestedOcrAndCurrentBatchContract(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            if (str_ends_with($url, '/ocr')) {
                self::assertSame('http://127.0.0.1:5001/v1/ocr', $url);
                self::assertSame(['type' => 'file', 'file_id' => 'f1'], $body['document']);
                self::assertSame([0, 2], $body['pages']);

                return new MockResponse(json_encode(['model' => 'local', 'pages' => [['index' => 0, 'markdown' => 'hello',
                    'dimensions' => ['width' => 100, 'height' => 200, 'dpi' => 300],
                    'images' => [['id' => 'img', 'top_left_x' => 1, 'top_left_y' => 2, 'bottom_right_x' => 3, 'bottom_right_y' => 4]],
                    'future_field' => 'preserved']]]));
            }
            self::assertSame('http://127.0.0.1:5001/v1/batch/jobs', $url);
            self::assertSame(['f1'], $body['input_files']);
            self::assertSame('/v1/ocr', $body['endpoint']);
            self::assertSame(24, $body['timeout_hours']);
            self::assertArrayNotHasKey('input_file_id', $body);

            return new MockResponse(json_encode(self::jobData()));
        });
        $client = (new ClientFactory($http))->create('local', 'local-token', 'http://127.0.0.1:5001/v1');
        $file = new FileReference('local', 'f1');
        $ocr = $client->ocrFile($file, 'local', ['pages' => [0, 2]]);
        self::assertSame(300, $ocr->pages[0]->dimensions->dpi);
        self::assertSame(3, $ocr->pages[0]->images[0]->bottomRightX);
        self::assertSame('preserved', $ocr->pages[0]->raw['future_field']);
        self::assertTrue($client->createBatch([$file], 'local')->isTerminal());
    }

    public function testCacheIsolationAndDeleteInvalidation(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function ($method) use (&$calls) {
            ++$calls;

            return new MockResponse(json_encode('DELETE' === $method ? ['id' => 'f1', 'deleted' => true] : self::fileData()));
        });
        $factory = new ClientFactory($http);
        $cache = new ArrayAdapter();
        $a = $factory->create('cloud', 'key-a', cache: $cache);
        $b = $factory->create('cloud', 'key-b', cache: $cache);
        $file = new FileReference('cloud', 'f1');
        $a->file($file);
        $a->file($file);
        self::assertSame(1, $calls);
        $b->file($file);
        self::assertSame(2, $calls);
        $a->deleteFile($file);
        $a->file($file);
        self::assertSame(4, $calls);
    }

    public function testRejectsCrossClientFile(): void
    {
        $client = (new ClientFactory(new MockHttpClient()))->create('local', '');
        $this->expectException(\InvalidArgumentException::class);
        $client->ocrFile(new FileReference('cloud', 'f1'));
    }

    public function testBatchResultsSplitAcrossChunksAndWithoutFinalNewline(): void
    {
        $body = (function () {
            yield '{"custom_id":"a","response":';
            yield '{"status_code":200}}'."\n";
            yield '{"custom_id":"b","error":{"message":"bad"}}';
        })();
        $client = (new ClientFactory(new MockHttpClient(new MockResponse($body))))->create('cloud', 'test');
        $results = iterator_to_array($client->batchResults(new FileReference('cloud', 'out')));
        self::assertSame('a', $results[0]->customId);
        self::assertSame('bad', $results[1]->error['message']);
    }

    public function testBatchInputUsesMistralJsonl(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            $body = '';
            while (($chunk = $options['body'](8192)) !== '') {
                $body .= $chunk;
            }
            self::assertStringContainsString('{"custom_id":"item-1","body":{"document":{"type":"document_url","document_url":"https:\/\/example.com\/a.pdf"}}}', $body);
            self::assertStringContainsString('batch', $body);

            return new MockResponse(json_encode(self::fileData()));
        });
        $client = (new ClientFactory($http))->create('cloud', 'test');
        $file = $client->uploadBatchRequests([new BatchRequest('item-1', ['document' => ['type' => 'document_url', 'document_url' => 'https://example.com/a.pdf']])]);
        self::assertSame('f1', $file->reference->id);
    }

    public function testResultLineLimit(): void
    {
        $client = (new ClientFactory(new MockHttpClient(new MockResponse(str_repeat('x', 20)))))->create('cloud', 'test');
        $this->expectException(\LengthException::class);
        iterator_to_array($client->batchResults(new FileReference('cloud', 'out'), 10));
    }

    public function testLargeUploadIsConsumedInBoundedChunks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mistral-test-');
        $file = fopen($path, 'wb');
        ftruncate($file, 64 * 1024 * 1024);
        fclose($file);
        try {
            $http = new MockHttpClient(function ($method, $url, $options) {
                $start = memory_get_usage(true);
                $max = $start;
                $bytes = 0;
                while (($chunk = $options['body'](8192)) !== '') {
                    $bytes += strlen($chunk);
                    $max = max($max, memory_get_usage(true));
                }
                self::assertGreaterThan(64 * 1024 * 1024, $bytes);
                self::assertLessThan(8 * 1024 * 1024, $max - $start);

                return new MockResponse(json_encode(self::fileData()));
            });
            (new ClientFactory($http))->create('cloud', 'test')->upload($path);
        } finally {
            unlink($path);
        }
    }
}
