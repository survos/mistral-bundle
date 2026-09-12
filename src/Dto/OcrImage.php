<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class OcrImage
{
    public function __construct(public string $id, public int $topLeftX, public int $topLeftY, public int $bottomRightX, public int $bottomRightY, public ?string $imageBase64, public array $raw) {}
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['top_left_x'], $data['top_left_y'], $data['bottom_right_x'], $data['bottom_right_y'], $data['image_base64'] ?? null, $data);
    }
}
