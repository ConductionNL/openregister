<?php

/**
 * Where an item stands when an expression is evaluated against it.
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
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The optional parts of an expression's data document: position, list size and run metadata.
 *
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */
final class FlowExpressionDataOptions {
	/**
	 * Constructor.
	 *
	 * @param integer              $itemIndex The item's position in the list.
	 * @param integer              $itemCount How many items the step received.
	 * @param array<string, mixed> $context   Run-level metadata.
	 */
	public function __construct(
		public readonly int $itemIndex = 0,
		public readonly int $itemCount = 1,
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
