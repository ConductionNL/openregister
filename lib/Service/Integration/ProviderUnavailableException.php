<?php

/**
 * OpenRegister ProviderUnavailableException
 *
 * Thrown when an integration provider or its backing system is unavailable.
 *
 * @category Integration
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use RuntimeException;

/**
 * Exception signalling that an integration provider is unavailable.
 *
 * The 'reason' field maps to the umbrella's Error-Handling Contract so the
 * controller can translate it to an appropriate HTTP 503 response body.
 */
class ProviderUnavailableException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string          $message  Human-readable message
     * @param string          $reason   Machine-readable reason code
     * @param int             $code     Exception code
     * @param \Throwable|null $previous Previous exception
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function __construct(
        string $message,
        private readonly string $reason='unavailable',
        int $code=503,
        ?\Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()

    /**
     * Machine-readable reason code.
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function getReason(): string
    {
        return $this->reason;
    }//end getReason()
}//end class
