<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class FileReference
{
    public function __construct(public string $client, public string $id)
    {
        if ('' === $client || '' === $id) {
            throw new \InvalidArgumentException('A file reference requires a client name and file ID.');
        }
    }
}
