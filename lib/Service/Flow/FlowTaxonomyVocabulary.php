<?php

/**
 * One closed taxonomy vocabulary: how a node declares its value, what is
 * allowed, and what an undeclared or out-of-vocabulary node reports.
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
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Closure;

/**
 * The declaration reader, closed vocabulary, fallback and field name that
 * FlowNodeTaxonomyResolver checks one declared value against.
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
 */
final class FlowTaxonomyVocabulary {
	/**
	 * Bundle the vocabulary.
	 *
	 * @param Closure            $declaration Reads the value off the taxonomy.
	 * @param array<int, string> $allowed     The closed vocabulary.
	 * @param string             $fallback    What an undeclared node reports.
	 * @param string             $what        The field name, for the log.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
	 */
	public function __construct(
		public readonly Closure $declaration,
		public readonly array $allowed,
		public readonly string $fallback,
		public readonly string $what,
	) {
	}//end __construct()
}//end class
