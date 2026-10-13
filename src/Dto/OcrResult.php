<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class OcrResult
{
    /** @param list<OcrPage> $pages */
    public function __construct(public string $model, public array $pages, public array $usageInfo, public array $raw)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['model'], array_map(OcrPage::fromArray(...), $data['pages']), $data['usage_info'] ?? [], $data);
    }
}
