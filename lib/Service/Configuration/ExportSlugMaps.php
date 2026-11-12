<?php

/**
 * The schema and register slugs an export writes in place of ids.
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
 * Looks up the slug of a schema or register id, so an exported schema stays
 * portable between instances.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportSlugMaps
{

	/**
	 * Constructor.
	 *
	 * @param array<int|string, string> $schemaSlugs   Schema slugs by id.
	 * @param array<int|string, string> $registerSlugs Register slugs by id.
	 */
	public function __construct(
		private readonly array $schemaSlugs,
		private readonly array $registerSlugs,
	) {
	}//end __construct()

	/**
	 * The slug of a schema id, or null when the id is unknown.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function schemaSlug(string $schemaId): ?string {
		return $this->schemaSlugs[$schemaId] ?? null;
	}//end schemaSlug()

	/**
	 * The slug of a register id, or null when the id is unknown.
	 *
	 * @param string $registerId The register id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function registerSlug(string $registerId): ?string {
		return $this->registerSlugs[$registerId] ?? null;
	}//end registerSlug()

	/**
	 * The slug of a numeric schema id; any other value comes back unchanged.
	 *
	 * @param mixed $schemaId A schema id, slug or other reference.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function schemaSlugOrValue(mixed $schemaId): mixed {
		if (is_numeric($schemaId) === false) {
			return $schemaId;
		}

		return $this->schemaSlug(schemaId: (string)$schemaId) ?? $schemaId;
	}//end schemaSlugOrValue()

	/**
	 * The slug of a numeric register id; any other value comes back unchanged.
	 *
	 * @param mixed $registerId A register id, slug or other reference.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function registerSlugOrValue(mixed $registerId): mixed {
		if (is_numeric($registerId) === false) {
			return $registerId;
		}

		return $this->registerSlug(registerId: (string)$registerId) ?? $registerId;
	}//end registerSlugOrValue()
}//end class
