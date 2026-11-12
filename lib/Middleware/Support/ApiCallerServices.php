<?php

/**
 * OpenRegister API caller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Support
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

namespace OCA\OpenRegister\Middleware\Support;

use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorderInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiatorInterface;

/**
 * The recorder of each API call and the negotiator of the API version it is answered in.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final readonly class ApiCallerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ApiCallRecorderInterface      $recorder   Records each call.
	 * @param ApiVersionNegotiatorInterface $negotiator Negotiates the API version.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function __construct(
		public ApiCallRecorderInterface $recorder,
		public ApiVersionNegotiatorInterface $negotiator,
	) {
	}//end __construct()
}//end class
