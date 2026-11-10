<?php

/**
 * One request to the active OpenAnonymiser ExApp.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The ExApp route, the request body and the HTTP method of one call.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */
final class AnonymiserCall {

	/**
	 * Constructor.
	 *
	 * @param string               $route  ExApp route (e.g. `/api/v1/analyze`).
	 * @param array<string, mixed> $params Request body parameters.
	 * @param string               $method HTTP method.
	 */
	public function __construct(
		public readonly string $route,
		public readonly array $params,
		public readonly string $method,
	) {
	}//end __construct()
}//end class
