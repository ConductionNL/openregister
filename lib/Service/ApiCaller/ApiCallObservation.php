<?php

/**
 * OpenRegister ApiCallObservation.
 *
 * One API call as the recorder counts it: the requested path, the HTTP
 * method, the API version and the moment it was made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

use DateTime;

/**
 * One observed API call.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class ApiCallObservation {
	/**
	 * Constructor.
	 *
	 * @param string        $path       The request path, query string included or not.
	 * @param string        $method     The HTTP method.
	 * @param string        $apiVersion The API version the call addressed.
	 * @param DateTime|null $moment     When the call was made; null for now.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $method,
		public readonly string $apiVersion,
		public readonly ?DateTime $moment = null,
	) {
	}//end __construct()
}//end class
