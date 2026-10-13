<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class PageDimensions
{
    public function __construct(public int $width, public int $height, public int $dpi)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['width'], $data['height'], $data['dpi']);
    }
}
