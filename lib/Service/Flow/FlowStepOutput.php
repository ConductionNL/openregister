<?php

/**
 * FlowStepOutput: what a fired step produced, with the run metadata its exit conditions read.
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
 * What a fired step produced, as {@see FlowTokenRouter::takenExits()} chooses an exit on it.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowStepOutput {
	/**
	 * Constructor.
	 *
	 * @param array<int, mixed>    $items   What the step produced.
	 * @param array<string, mixed> $context Run-level metadata.
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
