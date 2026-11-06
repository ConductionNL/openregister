<?php

/**
 * OpenRegister MagicMappedTable
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


/**
 * Whether a resolved register and schema are read through a magic-mapped table.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class MagicMappedTable {
	/**
	 * Hold the resolved entities.
	 *
	 * @param array<string, mixed> $resolved The resolved ids and entities.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		private readonly array $resolved,
	) {
	}//end __construct()

	/**
	 * Whether the register maps this schema to a magic table.
	 *
	 * @return bool True when mapping is on and lists the schema by id or slug.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function isMapped(): bool {
		$register = ($this->resolved['registerEntity'] ?? null);
		$schema = ($this->resolved['schemaEntity'] ?? null);
		if ($register === null || $schema === null) {
			return false;
		}

		$config = ($register->getConfiguration() ?? []);
		$mapped = ($config['magicMappingSchemas'] ?? []);

		return ($config['enableMagicMapping'] ?? false) === true
			&& (in_array((string)$schema->getId(), $mapped, true) === true
			|| in_array($schema->getSlug(), $mapped, true) === true);
	}//end isMapped()
}//end class
