<?php

/**
 * OpenRegister WorkflowResult Value Object
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use InvalidArgumentException;
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
     * Outcome status.
     *
     * @var string
     */
    private string $status;

    /**
     * Modified object data (when status is 'modified').
     *
     * @var array<string, mixed>|null
     */
    private ?array $data;

    /**
     * Validation errors from workflow execution.
     *
     * @var array<int, array{field?: string, message: string, code?: string}>
     */
    private array $errors;

    /**
     * Engine-specific metadata.
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * Constructor for WorkflowResult.
     *
     * @param string                                                      $status   One of: approved, rejected,
     *                                                                              modified, error
     * @param array<string,mixed>|null                                    $data     Modified object data
     * @param array<int,array{field?:string,message:string,code?:string}> $errors   Validation errors
     * @param array<string,mixed>                                         $metadata Engine-specific metadata
     *
     * @throws InvalidArgumentException If status is not valid
     */
    public function __construct(
        string $status,
        ?array $data=null,
        array $errors=[],
        array $metadata=[]
    ) {
        if (in_array(needle: $status, haystack: self::VALID_STATUSES, strict: true) === false) {
            $validList = implode(separator: ', ', array: self::VALID_STATUSES);
            throw new InvalidArgumentException(
                message: "Invalid workflow result status '$status'. Must be one of: $validList"
            );
        }

        $this->status   = $status;
        $this->data     = $data;
        $this->errors   = $errors;
        $this->metadata = $metadata;
    }//end __construct()

    /**
     * Create an approved result.
     *
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function approved(array $metadata=[]): self
    {
        return new self(status: self::STATUS_APPROVED, metadata: $metadata);
    }//end approved()

    /**
     * Create a rejected result with validation errors.
     *
     * @param array<int, array{field?: string, message: string, code?: string}> $errors   Validation errors
     * @param array<string, mixed>                                              $metadata Optional metadata
     *
     * @return self
     */
    public static function rejected(array $errors, array $metadata=[]): self
    {
        return new self(status: self::STATUS_REJECTED, errors: $errors, metadata: $metadata);
    }//end rejected()

    /**
     * Create a modified result with updated data.
     *
     * @param array<string, mixed> $data     Modified object data
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function modified(array $data, array $metadata=[]): self
    {
        return new self(status: self::STATUS_MODIFIED, data: $data, metadata: $metadata);
    }//end modified()

    /**
     * Create an error result.
     *
     * @param string               $message  Error message
     * @param array<string, mixed> $metadata Optional metadata
     *
     * @return self
     */
    public static function error(string $message, array $metadata=[]): self
    {
        return new self(
            status: self::STATUS_ERROR,
            errors: [['message' => $message]],
            metadata: $metadata
        );
    }//end error()

    /**
     * Get the outcome status of the workflow result.
     *
     * @return string The status value
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()

    /**
     * Get the modified object data.
     *
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }//end getData()

    /**
     * Get the validation errors.
     *
     * @return array<int, array{field?: string, message: string, code?: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }//end getErrors()

    /**
     * Get the engine-specific metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }//end getMetadata()

    /**
     * Check whether the result status is approved.
     *
     * @return bool True if approved
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }//end isApproved()

    /**
     * Check whether the result status is rejected.
     *
     * @return bool True if rejected
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }//end isRejected()

    /**
     * Check whether the result status is modified.
     *
     * @return bool True if modified
     */
    public function isModified(): bool
    {
        return $this->status === self::STATUS_MODIFIED;
    }//end isModified()

    /**
     * Check whether the result status is error.
     *
     * @return bool True if error
     */
    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }//end isError()

    /**
     * Convert the result to an array representation.
     *
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
    }//end toArray()

    /**
     * Serialize the result to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }//end jsonSerialize()
}//end class
