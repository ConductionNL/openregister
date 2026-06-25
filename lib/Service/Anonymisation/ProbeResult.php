<?php

/**
 * OpenRegister Anonymisation Probe Result
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  ValueObject
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * Immutable result of a single backend reachability probe.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\Anonymisation
 */
final class ProbeResult implements JsonSerializable
{
    /**
     * Probe could not be issued because AppAPI is not present on the instance.
     */
    public const ERROR_APPAPI_MISSING = 'appapi_missing';

    /**
     * Target ExApp is not installed.
     */
    public const ERROR_EXAPP_NOT_INSTALLED = 'exapp_not_installed';

    /**
     * Target ExApp is installed but not enabled.
     */
    public const ERROR_EXAPP_DISABLED = 'exapp_disabled';

    /**
     * Backend has no usable configuration (e.g. no endpoint set).
     */
    public const ERROR_NOT_CONFIGURED = 'not_configured';

    /**
     * HTTP probe timed out.
     */
    public const ERROR_TIMEOUT = 'timeout';

    /**
     * DNS resolution failed for the configured endpoint.
     */
    public const ERROR_DNS_ERROR = 'dns_error';

    /**
     * TCP connection was refused.
     */
    public const ERROR_CONNECT_REFUSED = 'connect_refused';

    /**
     * Endpoint responded with a 4xx status.
     */
    public const ERROR_HTTP_4XX = 'http_4xx';

    /**
     * Endpoint responded with a 5xx status.
     */
    public const ERROR_HTTP_5XX = 'http_5xx';

    /**
     * The full set of valid error codes.
     *
     * @var string[]
     */
    public const ERRORS = [
        self::ERROR_TIMEOUT,
        self::ERROR_DNS_ERROR,
        self::ERROR_HTTP_4XX,
        self::ERROR_HTTP_5XX,
        self::ERROR_CONNECT_REFUSED,
        self::ERROR_EXAPP_NOT_INSTALLED,
        self::ERROR_EXAPP_DISABLED,
        self::ERROR_APPAPI_MISSING,
        self::ERROR_NOT_CONFIGURED,
    ];

    /**
     * Constructor.
     *
     * @param bool        $reachable Whether the backend responded successfully.
     * @param int|null    $latencyMs Round-trip latency in milliseconds, or null when not measured.
     * @param string|null $error     One of the ERROR_* codes, or null on success.
     * @param string      $probedAt  ISO-8601 timestamp of when the probe ran.
     */
    public function __construct(
        public readonly bool $reachable,
        public readonly ?int $latencyMs,
        public readonly ?string $error,
        public readonly string $probedAt,
    ) {
    }//end __construct()

    /**
     * Serialise to a JSON-friendly array.
     *
     * @return array{reachable: bool, latencyMs: int|null, error: string|null, probedAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'reachable' => $this->reachable,
            'latencyMs' => $this->latencyMs,
            'error'     => $this->error,
            'probedAt'  => $this->probedAt,
        ];
    }//end jsonSerialize()
}//end class
