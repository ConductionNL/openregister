<?php

/**
 * Maps the slugs an import refers to onto ids.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The slug-to-id map of one import, consulted before the already known entities.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportReferenceMap {

	/**
	 * Constructor.
	 *
	 * @param array<int|string, mixed> $slugsAndIds Slugs with their IDs from this import.
	 */
	public function __construct(
		public readonly array $slugsAndIds,
	) {
	}//end __construct()

	/**
	 * The id a slug reference maps to: this import's map first, then the known entities.
	 *
	 * @param mixed                $reference The slug, or an id already.
	 * @param array<string, mixed> $entities  Known registers or schemas by slug.
	 *
	 * @return mixed The id, or the reference unchanged when nothing maps it.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function resolve(mixed $reference, array $entities): mixed {
		if (($this->slugsAndIds[$reference] ?? null) !== null) {
			return $this->slugsAndIds[$reference];
		}

		if (($entities[$reference] ?? null) !== null) {
			return $entities[$reference]->getId();
		}

		return $reference;
	}//end resolve()
}//end class
