<?php

/**
 * ProviderUnavailableException — external provider can't be reached.
 *
 * Thrown by ExternalIntegrationRouter when the upstream service or
 * the OpenConnector source itself is unavailable. The `cause` field
 * distinguishes connector-down ("Reconfigure connector") from
 * upstream-down ("Service offline") so the UI renders the right
 * actionable message (AD-23).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * External-integration call failed because the upstream or the
 * connector itself is unavailable.
 */
class ProviderUnavailableException extends \RuntimeException
{

    /**
     * Permitted cause values.
     */
    public const CAUSE_OPENCONNECTOR_DOWN           = 'openconnector-down';
    public const CAUSE_OPENCONNECTOR_SOURCE_MISSING = 'openconnector-source-missing';
    public const CAUSE_UPSTREAM_SERVICE_DOWN        = 'upstream-service-down';

    /**
     * The cause classification.
     *
     * @var string
     */
    private string $cause;

    /**
     * Constructor.
     *
     * @param string          $message  Human-readable explanation.
     * @param string          $cause    One of the CAUSE_* constants.
     * @param \Throwable|null $previous Optional wrapped throwable.
     *
     * @return void
     */
    public function __construct(string $message, string $cause, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->cause = $cause;
    }//end __construct()

    /**
     * Return the cause classification.
     *
     * @return string One of openconnector-down |
     *                openconnector-source-missing | upstream-service-down.
     */
    public function getCause(): string
    {
        return $this->cause;
    }//end getCause()

    /**
     * Convenience getter producing the structured details payload that
     * the UI renders (per AD-23: `details.cause`).
     *
     * @return array{cause: string}
     */
    public function getDetails(): array
    {
        return ['cause' => $this->cause];
    }//end getDetails()

}//end class
