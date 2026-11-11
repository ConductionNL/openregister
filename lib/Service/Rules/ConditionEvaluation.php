<?php

/**
 * What one evaluation of a composed condition reads, and how deep it is.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The evaluation document, the named conditions in scope and the composition depth.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class ConditionEvaluation {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $document The evaluation document.
	 * @param array<string, mixed> $library  The named conditions in scope.
	 * @param int                  $depth    The composition depth so far.
	 */
	public function __construct(
		public readonly array $document,
		public readonly array $library,
		public readonly int $depth,
	) {
	}//end __construct()

	/**
	 * The same evaluation one reference deeper.
	 *
	 * @return self The deeper evaluation.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function deeper(): self {
		return new self(document: $this->document, library: $this->library, depth: ($this->depth + 1));
	}//end deeper()
}//end class
