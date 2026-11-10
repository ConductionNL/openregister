<?php

/**
 * OpenRegister ConceptBranch
 *
 * One branch of a concept scheme: the root it hangs from, the scheme's
 * concepts it is read out of, and how many levels below the root it reaches.
 * The flat branch walk and the option tree both start from exactly these
 * three, so they take them as one value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * A branch of a concept scheme, bounded by depth.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class ConceptBranch {

	/**
	 * Constructor.
	 *
	 * @param string                            $rootUri       The branch root's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 * @param integer|null                      $maxDepth      How many levels below the root to walk; the default bound when null.
	 */
	public function __construct(
		public readonly string $rootUri,
		public readonly array $conceptsByUri,
		public readonly ?int $maxDepth = null,
	) {

	}//end __construct()

	/**
	 * How many levels below the root the walk goes.
	 *
	 * @return integer The declared bound, or ConceptHierarchy::DEFAULT_MAX_DEPTH.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function depthLimit(): int {
		return ($this->maxDepth ?? ConceptHierarchy::DEFAULT_MAX_DEPTH);
	}//end depthLimit()

	/**
	 * The broader/narrower relations of the scheme the branch is read out of.
	 *
	 * @return ConceptRelations The relations.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function relations(): ConceptRelations {
		return new ConceptRelations(conceptsByUri: $this->conceptsByUri);
	}//end relations()
}//end class
