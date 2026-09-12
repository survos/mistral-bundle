<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class SignedUrl
{
    public function __construct(public FileReference $file, public string $url) {}
}
