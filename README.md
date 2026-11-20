# Mistral Bundle

Symfony integration around `softcreatr/php-mistral-ai-sdk` 5.1 (it needs PHP 8.5.12 or later on the 8.5 line), with named clients and typed Files, OCR and Batch results. This is an initial client bundle, not a server implementing Mistral's API. Mediary implements the Files and Batch contract at `/v1`, and the bundle's client has been run against it.

## Configuration

Register `Survos\MistralBundle\SurvosMistralBundle` and `Survos\Kit\SurvosKitBundle` in your application. Install the package using a Composer path repository while it is unpublished.

```yaml
survos_mistral:
    default_client: cloud
    clients:
        cloud:
            api_key: '%env(MISTRAL_API_KEY)%'
            cache_pool: cache.app
            metadata_ttl: 60
        pdf_tools:
            api_key: '%env(PDFTOOLS_TOKEN)%'
            base_uri: 'http://127.0.0.1:5001/v1'
            timeout: 120
```

Autowire `MistralClient` for the default client, or use `#[Autowire(service: 'survos_mistral.client.pdf_tools')]` for a named client. `ClientFactory` also works outside the container when supplied a Symfony HTTP client.

**PDF Tools does not yet implement this contract.** Its existing JSON source registration endpoint is not Mistral's multipart upload endpoint. The example config establishes the intended destination; a compatibility layer and actual two-backend tests remain necessary.

## Files and OCR

```php
$file = $client->upload('/data/archive.pdf');
$reference = $file->reference; // client name + opaque provider file ID
$result = $client->ocrFile($reference, options: ['pages' => [0, 2]]);
foreach ($result->pages as $page) {
    echo $page->markdown;
    echo $page->dimensions?->dpi;
    foreach ($page->images as $image) {
        echo $image->bottomRightX;
    }
}
$signedUrl = $client->signedUrl($reference, expiryHours: 1);
foreach ($client->download($reference) as $chunk) {
    // Write to your storage stream; handle partial writes in your application.
}
```

Mistral pages are zero-based. `ocr()` also accepts Mistral document_url/image_url document payloads. DTOs preserve `raw` fields for forward compatibility. Core page/image fields are typed; tables, content blocks, annotations and arbitrary model response bodies still need richer models. These are deliberately not presented as a complete typed SDK.

`files($query)` and `batches($query)` return one typed page. Pass page/page_size and filters explicitly. `file()`, `deleteFile()`, and `signedUrl()` use a FileReference. IDs from another named client are rejected; a cloud transfer must use an accessible URL or an upload.

## Batch workflow

```php
use Survos\MistralBundle\Dto\BatchRequest;

$input = $client->uploadBatchRequests((function () use ($urls) {
    foreach ($urls as $id => $url) {
        yield new BatchRequest((string) $id, [
            'document' => ['type' => 'document_url', 'document_url' => $url],
        ]);
    }
})());
// Persist $input->reference before submitting. Keep custom IDs unique within the batch.
$job = $client->createBatch([$input->reference], 'mistral-ocr-latest');
// Persist $job->client and $job->id. Schedule a later status check.
$job = $client->batch($job->id);
if ($job->isTerminal() && $job->outputFile !== null) {
    foreach ($client->batchResults($job->outputFile) as $result) {
        // Match by $result->customId; output order is not an identity.
    }
}
// Consume $job->errorFile too when present. cancelBatch($job->id) requests cancellation.
```

Batch submission uses the current `input_files`, `model`, `endpoint`, `timeout_hours` contract. It does not copy the upstream example's older input_file_id/completion_window fields. JSONL input is written incrementally to a temporary file; upload uses Symfony Mime's iterable multipart body. Result parsing is incremental with a configurable per-line byte limit (16 MiB default). Oversized or malformed records fail explicitly.

The caller owns durable batch records, polling, retry decisions and cleanup. No sleeping worker, automatic repeated paid submission, hidden upload deletion, or cloud call occurs during bundle setup. Integrating the existing ai-batch-bundle scheduler/contract is a follow-up; this bundle already exposes submission, list/status, cancellation and result retrieval.

## Caching and transport

Only file metadata is cached, opt-in through cache_pool, with a configurable TTL. Cache identity includes client name, endpoint and a hash of the credential. Successful deletion invalidates that entry. Lists, signed URLs, job status, OCR and mutations are not cached. Persistent OCR caching should key on content digest, processing options and an immutable engine version, rather than a signed URL or a mutable `latest` model alias.

The SDK handles ordinary JSON requests. A PSR-18 EndpointClient adapter maps its fixed Mistral origin to the configured full base URI. Upload/download use Symfony HTTP directly for bounded file transfer. Redirects are disabled so credentials are not forwarded to another origin. The factory does not install automatic mutation retries; applications should not wrap it in an indiscriminate retrying client.

## Mediary boundary

FileReference identifies a provider resource, not a path or a permanent URL. Mediary remains responsible for original URL, provenance, revisions, storage location, ACLs and durable derived media. PDF, image, audio and video assets can share that identity layer. This package does not decide whether Mediary exposes Mistral Files, JSON-RPC, or API Platform, and does not migrate storage-bundle. Existing-S3-object registration would be an explicit local extension.

## Validation

Run `composer install` and `composer test`. Tests cover named-client container wiring, custom HTTP URL/port, nested OCR DTOs, current batch request shape, cache isolation/invalidation, cross-client ID rejection, JSONL chunk boundaries and limits, and a 64 MiB multipart body consumed with less than 8 MiB incremental memory in the mock transport. This is not a live upload benchmark. No paid API calls or PDF Tools compatibility calls have been performed.

See [SDK direction and upstream work](docs/sdk-direction.md).
