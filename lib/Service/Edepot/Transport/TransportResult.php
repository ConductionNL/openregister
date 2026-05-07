<?php

/**
 * OpenRegister e-Depot Transport Result
 *
 * Value object representing the result of a SIP transport operation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

/**
 * Result of a SIP transport operation.
 *
 * Contains the overall success/failure status, per-object acceptance results,
 * and any error details from the e-Depot system.
 *
 * @psalm-suppress UnusedClass
 */
class TransportResult
{

    /**
     * Whether the transport succeeded overall.
     *
     * @var boolean
     */
    private bool $success;

    /**
     * Per-object results mapping UUID to acceptance status.
     *
     * @var array<string, array{accepted: bool, reference: string|null, error: string|null}>
     */
    private array $objectResults;

    /**
     * Error message if the transport failed entirely.
     *
     * @var string|null
     */
    private ?string $errorMessage;

    /**
     * The e-Depot's reference identifier for the transfer.
     *
     * @var string|null
     */
    private ?string $transferReference;

    /**
     * Constructor.
     *
     * @param bool                                                                             $success           Overall success.
     * @param array<string, array{accepted: bool, reference: string|null, error: string|null}> $objectResults     Per-object results.
     * @param string|null                                                                      $errorMessage      Error message.
     * @param string|null                                                                      $transferReference Transfer reference.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __construct(
        bool $success=false,
        array $objectResults=[],
        ?string $errorMessage=null,
        ?string $transferReference=null
    ) {
        $this->success           = $success;
        $this->objectResults     = $objectResults;
        $this->errorMessage      = $errorMessage;
        $this->transferReference = $transferReference;
    }//end __construct()

    /**
     * Check if the transport succeeded.
     *
     * @return bool True if successful.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }//end isSuccess()

    /**
     * Check if the result is a partial success (some objects accepted, some rejected).
     *
     * @return bool True if partially successful.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function isPartialSuccess(): bool
    {
        if ($this->success === true) {
            return false;
        }

        $accepted = 0;
        $rejected = 0;
        foreach ($this->objectResults as $result) {
            if ($result['accepted'] === true) {
                $accepted++;
                continue;
            }

            $rejected++;
        }

        return ($accepted > 0 && $rejected > 0);
    }//end isPartialSuccess()

    /**
     * Get per-object results.
     *
     * @return array<string, array{accepted: bool, reference: string|null, error: string|null}> Object results.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function getObjectResults(): array
    {
        return $this->objectResults;
    }//end getObjectResults()

    /**
     * Get the error message.
     *
     * @return string|null The error message.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }//end getErrorMessage()

    /**
     * Get the transfer reference.
     *
     * @return string|null The e-Depot transfer reference.
     */
    public function getTransferReference(): ?string
    {
        return $this->transferReference;
    }//end getTransferReference()

    /**
     * Get UUIDs of accepted objects.
     *
     * @return array<int, string> UUIDs of accepted objects.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function getAcceptedUuids(): array
    {
        $uuids = [];
        foreach ($this->objectResults as $uuid => $result) {
            if ($result['accepted'] === true) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }//end getAcceptedUuids()

    /**
     * Get UUIDs of rejected objects.
     *
     * @return array<int, string> UUIDs of rejected objects.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function getRejectedUuids(): array
    {
        $uuids = [];
        foreach ($this->objectResults as $uuid => $result) {
            if ($result['accepted'] === false) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }//end getRejectedUuids()

    /**
     * Serialize to array.
     *
     * @return array<string,mixed> Serialized result.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function toArray(): array
    {
        return [
            'success'           => $this->success,
            'objectResults'     => $this->objectResults,
            'errorMessage'      => $this->errorMessage,
            'transferReference' => $this->transferReference,
        ];
    }//end toArray()
}//end class
