<?php

/**
 * QueryTimeContract — codifies the read-only / live-query storage
 * strategy for IntegrationProvider implementations (AD-22).
 *
 * `query-time` providers persist nothing — `list()` queries the
 * upstream NC service live on every call. Mutation methods MUST
 * throw `NotImplementedException`. The sub-resource controller
 * catches the exception and translates it to HTTP 501 Not Implemented.
 *
 * This class is a one-stop reference + helper:
 *   - `RENDER_TIMEOUT_SECONDS` — the contract timeout per AD-22
 *     (2 seconds; surfaces render "degraded" state when exceeded).
 *   - `httpStatusForNotImplemented()` — the HTTP code translation
 *     used by the controller layer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Exception\NotImplementedException;

/**
 * Helper + constants for the query-time storage-strategy contract.
 */
final class QueryTimeContract
{

    /**
     * Maximum render-time the registry allows a query-time provider
     * to spend on `list()` before the surface SHALL render the
     * documented degraded-surface signal (AD-22).
     *
     * @var float
     */
    public const RENDER_TIMEOUT_SECONDS = 2.0;

    /**
     * HTTP status code returned by `ObjectsController` when a query-time
     * provider throws `NotImplementedException` for create/update/delete.
     *
     * @var int
     */
    public const HTTP_NOT_IMPLEMENTED = 501;

    /**
     * Translate a NotImplementedException into the standard HTTP body
     * shape used across OpenRegister's API.
     *
     * Shape mirrors the error envelope used elsewhere by the
     * controller layer (`{message, code, details}`) so consuming apps
     * have a single error-decoding path regardless of which provider
     * surfaced the failure.
     *
     * @param NotImplementedException $exception The exception thrown
     *                                           by the provider.
     * @param string                  $integrationId The provider id
     *                                           (for the details payload).
     *
     * @return array{message: string, code: int, details: array<string,string>}
     */
    public static function buildHttpBody(NotImplementedException $exception, string $integrationId): array
    {
        return [
            'message' => $exception->getMessage(),
            'code'    => self::HTTP_NOT_IMPLEMENTED,
            'details' => [
                'integration' => $integrationId,
                'reason'      => 'query-time-storage-no-mutation',
            ],
        ];
    }//end buildHttpBody()

}//end class
