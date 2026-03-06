<?php

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use JsonSerializable;

/**
 * Value object representing the result of a synchronous workflow execution.
 */
class WorkflowResult implements JsonSerializable
{

    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_ERROR    = 'error';

    private const VALID_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_MODIFIED,
        self::STATUS_ERROR,
    ];

    /**
     * @var string Outcome status
     */
    private string $status;

    /**
     * @var array<string, mixed>|null Modified object data (when status is 'modified')
     */
    private ?array $data;

    /**
     * @var array<int, array{field?: string, message: string, code?: string}> Validation errors
     */
    private array $errors;

    /**
     * @var array<string, mixed> Engine-specific metadata
     */
    private array $metadata;

    /**
     * @param string                    $status   One of: approved, rejected, modified, error
     * @param array<string, mixed>|null $data     Modified object data
     * @param array<int, array{field?: string, message: string, code?: string}> $errors   Validation errors
     * @param array<string, mixed>      $metadata Engine-specific metadata
     *
     * @throws \InvalidArgumentException If status is not valid
     */
    public function __construct(
        string $status,
        ?array $data = null,
        array $errors = [],
        array $metadata = []
    ) {
        if (in_array(needle: $status, haystack: self::VALID_STATUSES, strict: true) === false) {
            throw new \InvalidArgumentException(
                message: "Invalid workflow result status '$status'. Must be one of: "
                    . implode(separator: ', ', array: self::VALID_STATUSES)
            );
        }

        $this->status   = $status;
        $this->data     = $data;
        $this->errors   = $errors;
        $this->metadata = $metadata;
    }

    /**
     * Create an approved result.
     *
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function approved(array $metadata = []): self
    {
        return new self(status: self::STATUS_APPROVED, metadata: $metadata);
    }

    /**
     * Create a rejected result with validation errors.
     *
     * @param array<int, array{field?: string, message: string, code?: string}> $errors   Validation errors
     * @param array<string, mixed>                                              $metadata Optional metadata
     *
     * @return self
     */
    public static function rejected(array $errors, array $metadata = []): self
    {
        return new self(status: self::STATUS_REJECTED, errors: $errors, metadata: $metadata);
    }

    /**
     * Create a modified result with updated data.
     *
     * @param array<string, mixed> $data     Modified object data
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function modified(array $data, array $metadata = []): self
    {
        return new self(status: self::STATUS_MODIFIED, data: $data, metadata: $metadata);
    }

    /**
     * Create an error result.
     *
     * @param string               $message  Error message
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function error(string $message, array $metadata = []): self
    {
        return new self(
            status: self::STATUS_ERROR,
            errors: [['message' => $message]],
            metadata: $metadata
        );
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @return array<int, array{field?: string, message: string, code?: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isModified(): bool
    {
        return $this->status === self::STATUS_MODIFIED;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'   => $this->status,
            'data'     => $this->data,
            'errors'   => $this->errors,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
