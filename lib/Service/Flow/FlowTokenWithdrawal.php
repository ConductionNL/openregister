<?php

/**
 * FlowTokenWithdrawal: the tokens a fired transition must not leave behind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The workflow, its subject and the output places whose tokens are withdrawn
 * because their exit was not taken.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowTokenWithdrawal {

	/**
	 * Constructor.
	 *
	 * @param object             $workflow The Symfony workflow.
	 * @param object             $subject  The subject whose marking changes.
	 * @param array<int, string> $places   The output places of the exits not taken.
	 */
	public function __construct(
		public readonly object $workflow,
		public readonly object $subject,
		public readonly array $places,
	) {
	}//end __construct()
}//end class
