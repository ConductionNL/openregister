<?php

/**
 * OpenRegister DelegationRequest
 *
 * One request to do work as another user, on behalf of a principal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

use DateTime;

/**
 * Who asks to act as whom, for what, judged at which moment.
 *
 * Passed between the steps of {@see DelegationService::runAsDelegated()}.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class DelegationRequest {

	/**
	 * Name the request.
	 *
	 * @param string   $principal The uid the work is being done by.
	 * @param string   $actingAs  The uid whose rights the work should use.
	 * @param array    $scope     What the work needs, for scope matching.
	 * @param DateTime $now       The moment to judge liveness at.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function __construct(
		public readonly string $principal,
		public readonly string $actingAs,
		public readonly array $scope,
		public readonly DateTime $now,
	) {
	}//end __construct()
}//end class
