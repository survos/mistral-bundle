# SDK direction and upstream work

## Findings (2026-09-11)

Mistral's official Python SDK is generated with Speakeasy. Its checked-in workflow consumes Mistral's OpenAPI registry source. Speakeasy supports generating PHP with classes, enums, union types and serialization. Generating PHP from that specification is preferable to translating Python/Pydantic implementation code. Generator access, schema availability, reproducibility, runtime dependencies and handling of unions/streaming still need a concrete generation trial.

Sources:
- https://github.com/mistralai/client-python/blob/main/.speakeasy/workflow.yaml
- https://www.speakeasy.com/docs/sdks/languages/php/methodology-php
- https://openapi-generator.tech/docs/generators/php/
- https://docs.mistral.ai/api/endpoint/batch

SoftCreatR 3.0.1 instead uses magic methods, an endpoint map and PSR responses. No equivalent schema/model generation configuration was found in the inspected checkout (1f9bb47). Its light PSR dependencies make it a useful initial transport, but wrapping it does not automatically produce complete typing. Keep provider transport replaceable. The current bundle's DTOs are a useful subset; nested arbitrary payloads remain arrays.

The next evaluation should generate Files/OCR/Batch PHP models and inspect request unions, nullable versus missing fields, OCR images/tables/blocks, batch output/error unions, enums and unknown-field retention. Compare against this bundle's fixtures and actual provider responses. A generated package can sit under the same Symfony configuration layer. Generated DTOs should be separate from Survos-specific FileReference provenance.

## Prepared upstream proposals (not submitted)

### Full base URI support

Problem: `origin` is passed to withHost(), HTTPS is forced, and there is no configurable port. A local compatible service at http://127.0.0.1:5001/v1 cannot be configured.

Proposed change: add an optional trailing baseUri constructor argument, preserve existing origin/apiVersion behavior when omitted, parse the full URI with the supplied PSR factory, append endpoint paths, reject embedded credentials/query/fragment, and test HTTP, ports, prefixes and existing defaults. Remove our EndpointClient adapter after an upstream release provides this.

### Streamed multipart uploads

Problem: createMultipartStream() reads whole files into a string and concatenates the multipart body. Large archive uploads scale PHP memory with file size.

Proposed change: produce a PSR stream from a file-backed multipart implementation or bounded-memory temporary stream; preserve filename and purpose handling. Test 64+ MiB input under a small memory limit and verify byte-for-byte content. Evaluate an additional multipart dependency with the maintainer. Our Symfony Mime upload path is the temporary integration implementation, not a fork of SDK source.

### Typed schema-derived models

Discuss whether the maintainer wants an additive typed resource API or generation from Mistral OpenAPI. Keep existing raw response calls for compatibility. Avoid hand-maintaining every nested schema twice. Include a small Files/OCR proof before proposing a complete SDK rewrite.

The maintainer has not been contacted and responsiveness is unknown. These proposals are ready for discussion; no external message, issue or PR has been sent.
