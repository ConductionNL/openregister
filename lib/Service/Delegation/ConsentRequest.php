<?php

/**
 * A request for consent to act as another user.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

/**
 * Who asks to act as whom, for what, and why, as {@see DelegationConsentService} records it.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class ConsentRequest {
	/**
	 * Constructor.
	 *
	 * @param string $principal The uid that would act.
	 * @param string $actingAs  The uid being asked.
	 * @param array  $scope     What is being asked for.
	 * @param string $reason    Why, in the requester's words.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function __construct(
		public readonly string $principal,
		public readonly string $actingAs,
		public readonly array $scope,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
