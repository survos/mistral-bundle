<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class BatchResult
{
    public function __construct(public string $customId, public ?array $response, public ?array $error, public array $raw) {}

    public static function fromArray(array $data): self
    {
        return new self($data['custom_id'], $data['response'] ?? null, $data['error'] ?? null, $data);
    }
}
