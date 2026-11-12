<?php

/**
 * EndpointServiceInterface: the endpoint test run of EndpointService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Endpoint;

/**
 * The methods of EndpointService that callers outside the Service component use.
 */
interface EndpointServiceInterface {

	/**
	 * Test an endpoint by executing it with test data
	 *
	 * Executes endpoint with optional test data to verify endpoint configuration
	 * and functionality. Checks permissions before execution and logs results.
	 *
	 * @param Endpoint $endpoint The endpoint to test
	 * @param array<string, mixed> $testData Optional test data to use in execution
	 *
	 * @return array<string, mixed> Test result with success status, status code, response, and optional error
	 *
	 * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
	 * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function testEndpoint(Endpoint $endpoint, array $testData = []): array;
}//end interface
