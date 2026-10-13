<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class OcrPage
{
    /** @param list<OcrImage> $images */
    public function __construct(public int $index, public string $markdown, public array $images, public ?PageDimensions $dimensions, public array $raw)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['index'], $data['markdown'], array_map(OcrImage::fromArray(...), $data['images'] ?? []), isset($data['dimensions']) ? PageDimensions::fromArray($data['dimensions']) : null, $data);
    }
}
