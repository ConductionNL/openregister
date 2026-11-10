<?php

/**
 * What a run parked for consent is waiting on.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

/**
 * The principal whose grant is missing, the identity it would act as, and why the run stopped.
 *
 * Read by {@see FlowConsentParking::parkFor()}.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class ConsentAsk {
	/**
	 * Constructor.
	 *
	 * @param string $declaredBy The principal whose grant is missing.
	 * @param string $runAs      The identity it would act as.
	 * @param string $reason     The verdict reason that caused the park.
	 */
	public function __construct(
		public readonly string $declaredBy,
		public readonly string $runAs,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
