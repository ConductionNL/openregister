<?php

/**
 * EntityDetectionQuery: the text, entity filter and confidence floor one detection run works on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Service\Anonymisation\TextDisclosureSource;

/**
 * What one entity-detection run looks at and what it keeps.
 *
 * Deliberately without strict_types, like the handler that builds it: the
 * entity filter comes straight from caller options.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class EntityDetectionQuery {
	/**
	 * Bundle the run inputs.
	 *
	 * @param string     $text                Text to analyse.
	 * @param array|null $entityTypes         Entity types to detect; null detects all.
	 * @param float      $confidenceThreshold Minimum confidence an entity needs to be kept.
	 * @param TextDisclosureSource|null $source The document the text belongs to; an external call records it.
	 */
	public function __construct(
		public string $text,
		public ?array $entityTypes,
		public float $confidenceThreshold,
		public ?TextDisclosureSource $source=null,
	) {
	}//end __construct()

	/**
	 * Whether the run asks for the given entity type.
	 *
	 * @param string $type Entity type.
	 *
	 * @return bool True when no filter is set or the filter lists the type.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function wants(string $type): bool {
		return $this->entityTypes === null || in_array($type, $this->entityTypes, true) === true;
	}//end wants()

	/**
	 * Whether the run carries a non-empty entity filter.
	 *
	 * @return bool True when at least one entity type was asked for.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function hasEntityFilter(): bool {
		return $this->entityTypes !== null && empty($this->entityTypes) === false;
	}//end hasEntityFilter()
}//end class
