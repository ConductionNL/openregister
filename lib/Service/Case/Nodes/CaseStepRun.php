<?php

/**
 * OpenRegister CaseStepRun
 *
 * One execution of a case flow step: the items it received, its configuration and the run context.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case\Nodes
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case\Nodes;

/**
 * The items, configuration and run context of one case step's execute(); it never leaves the step.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
final class CaseStepRun {

	/**
	 * Constructor.
	 *
	 * @param array<int|string, mixed> $items   The items the step received.
	 * @param array<string, mixed>     $config  The step's configuration.
	 * @param array<string, mixed>     $context The run context.
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
