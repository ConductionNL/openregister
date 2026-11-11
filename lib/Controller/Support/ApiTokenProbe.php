<?php

/**
 * OpenRegister ApiTokenProbe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * One API token connection test: which service, which token, and whether it is the saved one.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class ApiTokenProbe {
	/**
	 * Hold the probe.
	 *
	 * @param string $service The connection key: 'github' or 'gitlab'.
	 * @param mixed $token The token to test, as the request or the config holds it.
	 * @param bool $testsSavedToken Whether the test says anything about the saved connection.
	 * @param mixed $apiUrl The API base URL to test against, when the service takes one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly string $service,
		public readonly mixed $token,
		public readonly bool $testsSavedToken,
		public readonly mixed $apiUrl = null,
	) {
	}//end __construct()
}//end class
