<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class BatchRequest implements \JsonSerializable
{
    public function __construct(public string $customId, public array $body)
    {
        if ($customId === '') {
            throw new \InvalidArgumentException('A batch request requires a custom ID.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['custom_id' => $this->customId, 'body' => $this->body];
    }
}
