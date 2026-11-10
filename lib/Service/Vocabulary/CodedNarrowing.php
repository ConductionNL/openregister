<?php

/**
 * OpenRegister CodedNarrowing
 *
 * The part of a {@see CodedPropertyDeclaration} that narrows a scheme's flat
 * list to the options a property offers: a branch and how deep it is walked,
 * and the context property or key that selects a subset.
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
 * The branch and context a coded property's option set is narrowed by.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final readonly class CodedNarrowing {

	/**
	 * Constructor.
	 *
	 * @param string|null  $branch          The branch root's uri, when the property is bound to one.
	 * @param integer|null $maxDepth        How deep the branch walk goes.
	 * @param string|null  $contextProperty The property whose value narrows the option set.
	 * @param string|null  $contextKey      The declared context key that narrows the option set.
	 */
	public function __construct(
		public ?string $branch = null,
		public ?int $maxDepth = null,
		public ?string $contextProperty = null,
		public ?string $contextKey = null,
	) {

	}//end __construct()
}//end class
