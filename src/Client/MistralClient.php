<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Client;

use SoftCreatR\MistralAI\MistralAI;
use Survos\MistralBundle\Dto\{BatchJob, BatchRequest, BatchResult, FileObject, FileReference, OcrResult, SignedUrl};
use Symfony\Contracts\Cache\{CacheInterface, ItemInterface};
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\{DataPart, Multipart\FormDataPart};

/** Typed document surface; the upstream SDK remains an implementation detail. */
final readonly class MistralClient
{
    public function __construct(
        public string $name,
        private MistralAI $sdk,
        private HttpClientInterface $http,
        private string $baseUri,
        #[\SensitiveParameter] private string $apiKey,
        private ?CacheInterface $cache = null,
        private int $metadataTtl = 60,
    ) {}

    public function file(FileReference $file): FileObject
    {
        $this->assertOwner($file);
        $fetch = fn (): array => $this->call('retrieveFile', ['file_id' => $file->id]);
        $data = $this->cache !== null && $this->metadataTtl > 0
            ? $this->cache->get($this->cacheKey($file), function (ItemInterface $item) use ($fetch): array {
                $item->expiresAfter($this->metadataTtl);
                return $fetch();
            }) : $fetch();
        return FileObject::fromArray($this->name, $data);
    }

    /** @return list<FileObject> One page; pass page/page_size and filters explicitly. */
    public function files(array $query = []): array
    {
        return array_map(fn (array $data) => FileObject::fromArray($this->name, $data), $this->call('listFiles', [], $query)['data']);
    }

    public function deleteFile(FileReference $file): void
    {
        $this->assertOwner($file);
        $this->call('deleteFile', ['file_id' => $file->id]);
        $this->cache?->delete($this->cacheKey($file));
    }

    public function signedUrl(FileReference $file, int $expiryHours = 1): SignedUrl
    {
        $this->assertOwner($file);
        if ($expiryHours < 1) {
            throw new \InvalidArgumentException('Expiry must be a positive number of hours.');
        }
        $data = $this->call('retrieveFileSignedUrl', ['file_id' => $file->id], ['expiry' => $expiryHours]);
        return new SignedUrl($file, $data['url']);
    }

    /** Streaming multipart avoids the upstream SDK's in-memory multipart builder. */
    public function upload(string $path, string $purpose = 'ocr', ?string $filename = null): FileObject
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Upload path must be a readable file.');
        }
        if (!in_array($purpose, ['ocr', 'batch', 'fine-tune'], true)) {
            throw new \InvalidArgumentException('Unsupported file purpose.');
        }
        $form = new FormDataPart(['purpose' => $purpose, 'file' => DataPart::fromPath($path, $filename)]);
        $response = $this->http->request('POST', $this->url('/files'), [
            'auth_bearer' => $this->apiKey,
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'max_redirects' => 0,
        ]);
        return FileObject::fromArray($this->name, $response->toArray());
    }

    /** Yields bounded byte chunks, without storing a complete document in PHP memory. */
    public function download(FileReference $file): \Generator
    {
        $this->assertOwner($file);
        $response = $this->http->request('GET', $this->url('/files/'.rawurlencode($file->id).'/content'), [
            'auth_bearer' => $this->apiKey, 'buffer' => false, 'max_redirects' => 0,
        ]);
        try {
            $response->getHeaders(); // Raises HTTP errors before yielding any bytes.
            foreach ($this->http->stream($response) as $chunk) {
                yield $chunk->getContent();
            }
        } finally {
            $response->cancel();
        }
    }

    /** The document shape follows Mistral: document_url, image_url or file. Pages are zero-based. */
    public function ocr(array $document, string $model = 'mistral-ocr-latest', array $options = []): OcrResult
    {
        return OcrResult::fromArray($this->call('createOcr', [], array_merge($options, ['document' => $document, 'model' => $model])));
    }

    public function ocrFile(FileReference $file, string $model = 'mistral-ocr-latest', array $options = []): OcrResult
    {
        $this->assertOwner($file);
        return $this->ocr(['type' => 'file', 'file_id' => $file->id], $model, $options);
    }

    /** @param list<FileReference> $inputFiles */
    public function createBatch(array $inputFiles, string $model, string $endpoint = '/v1/ocr', int $timeoutHours = 24, array $metadata = []): BatchJob
    {
        if ($inputFiles === [] || $timeoutHours < 1) {
            throw new \InvalidArgumentException('Batch input files and a positive timeout are required.');
        }
        foreach ($inputFiles as $file) {
            $this->assertOwner($file);
        }
        return BatchJob::fromArray($this->name, $this->call('createBatchJob', [], [
            'input_files' => array_map(fn (FileReference $file) => $file->id, $inputFiles),
            'model' => $model, 'endpoint' => $endpoint, 'timeout_hours' => $timeoutHours,
            'metadata' => (object) $metadata,
        ]));
    }

    /** @param iterable<BatchRequest> $requests Upload separately so the file ID can be persisted before submission. */
    public function uploadBatchRequests(iterable $requests): FileObject
    {
        $path = tempnam(sys_get_temp_dir(), 'mistral-batch-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate batch temporary file.');
        }
        $handle = fopen($path, 'wb');
        try {
            foreach ($requests as $request) {
                if (!$request instanceof BatchRequest) {
                    throw new \InvalidArgumentException('Expected BatchRequest objects.');
                }
                $line = json_encode($request, JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new \RuntimeException('Unable to write batch request.');
                }
            }
            fclose($handle);
            $handle = null;
            return $this->upload($path, 'batch', 'requests.jsonl');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            unlink($path);
        }
    }

    public function batch(string $id): BatchJob
    {
        return BatchJob::fromArray($this->name, $this->call('retrieveBatchJob', ['job_id' => $id]));
    }

    /** @return list<BatchJob> */
    public function batches(array $query = []): array
    {
        return array_map(fn (array $data) => BatchJob::fromArray($this->name, $data), $this->call('listBatchJobs', [], $query)['data']);
    }

    public function cancelBatch(string $id): BatchJob
    {
        return BatchJob::fromArray($this->name, $this->call('cancelBatchJob', ['job_id' => $id]));
    }

    /** @return \Generator<BatchResult> Read output_file or error_file, preserving custom_id. */
    public function batchResults(FileReference $file, int $maxLineBytes = 16777216): \Generator
    {
        if ($maxLineBytes < 1) {
            throw new \InvalidArgumentException('Maximum line size must be positive.');
        }
        $buffer = '';
        foreach ($this->download($file) as $chunk) {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                if ($end > $maxLineBytes) {
                    throw new \LengthException('Batch result line exceeds configured limit.');
                }
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                if (trim($line) !== '') {
                    yield BatchResult::fromArray(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
                }
            }
            if (strlen($buffer) > $maxLineBytes) {
                throw new \LengthException('Batch result line exceeds configured limit.');
            }
        }
        if (trim($buffer) !== '') {
            yield BatchResult::fromArray(json_decode($buffer, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    private function call(string $method, array $parameters = [], array $options = []): array
    {
        $parameters = array_map(rawurlencode(...), $parameters);
        $response = $this->sdk->$method($parameters, $options);
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Expected a JSON object from Mistral.');
        }
        return $data;
    }

    private function assertOwner(FileReference $file): void
    {
        if ($file->client !== $this->name) {
            throw new \InvalidArgumentException('File belongs to a different client; transfer it explicitly.');
        }
    }

    private function cacheKey(FileReference $file): string
    {
        return 'mistral_file_'.hash('sha256', json_encode([$this->name, $this->baseUri, hash('sha256', $this->apiKey), $file->id], JSON_THROW_ON_ERROR));
    }

    private function url(string $path): string { return rtrim($this->baseUri, '/').$path; }
}
