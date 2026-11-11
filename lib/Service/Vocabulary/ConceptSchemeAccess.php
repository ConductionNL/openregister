<?php

/**
 * OpenRegister ConceptSchemeAccess
 *
 * The three readers every consumer of a concept scheme reaches for together:
 * the repository that reads the scheme's concepts, the hierarchy that walks
 * broader and narrower, and the lifecycle that answers validity windows,
 * groups and weights. The option builder, the coded-value guard and the
 * branch-filter expander each read a scheme through them, so they are
 * injected as one aggregate (ADR-011 Rule 4).
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
 * Reading a concept scheme: its concepts, its hierarchy and its lifecycle rules.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class ConceptSchemeAccess {

	/**
	 * Constructor.
	 *
	 * @param ConceptRepository $concepts  Reads the scheme's concepts.
	 * @param ConceptHierarchy  $hierarchy Walks broader and narrower.
	 * @param ConceptLifecycle  $lifecycle Answers window, group and weight.
	 */
	public function __construct(
		public readonly ConceptRepository $concepts,
		public readonly ConceptHierarchy $hierarchy,
		public readonly ConceptLifecycle $lifecycle,
	) {

	}//end __construct()
}//end class
