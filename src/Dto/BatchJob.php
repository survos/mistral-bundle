<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Dto;

final readonly class BatchJob
{
    public function __construct(
        public string $client,
        public string $id,
        public string $status,
        public ?FileReference $outputFile,
        public ?FileReference $errorFile,
        public int $totalRequests,
        public int $completedRequests,
        public array $raw,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['SUCCESS', 'FAILED', 'TIMEOUT_EXCEEDED', 'CANCELLED'], true);
    }

    public static function fromArray(string $client, array $data): self
    {
        return new self($client, $data['id'], $data['status'],
            isset($data['output_file']) ? new FileReference($client, $data['output_file']) : null,
            isset($data['error_file']) ? new FileReference($client, $data['error_file']) : null,
            $data['total_requests'], $data['completed_requests'], $data);
    }
}
