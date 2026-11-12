<?php

/**
 * UpstreamRequest — one call an external provider asks the router to make.
 *
 * The method, the path relative to the source's base URL and the call
 * options travel together from {@see ExternalIntegrationRouter::call()} to
 * the connector; this holds them as one value.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * An HTTP method, a path and the call options.
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
 */
final readonly class UpstreamRequest {

	/**
	 * Constructor.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $path    Path relative to the source base URL.
	 * @param array<string,mixed> $options Call options (query / body / headers / withholdBodies).
	 */
	public function __construct(
		public string $method,
		public string $path,
		public array $options,
	) {
	}//end __construct()

	/**
	 * The method and path, as a log line names the request.
	 *
	 * @return string E.g. `GET pages`.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
	 */
	public function describe(): string {
		return $this->method . ' ' . $this->path;
	}//end describe()
}//end class
