<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class FileObject
{
    public function __construct(
        public FileReference $reference,
        public string $filename,
        public int $bytes,
        public ?string $mimeType,
        public ?string $purpose,
        public ?int $createdAt,
        public array $raw,
    ) {}

    public static function fromArray(string $client, array $data): self
    {
        return new self(new FileReference($client, $data['id']), $data['filename'], $data['bytes'],
            $data['mimetype'] ?? null, $data['purpose'] ?? null, $data['created_at'] ?? null, $data);
    }
}
