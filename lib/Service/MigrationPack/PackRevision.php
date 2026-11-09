<?php

/**
 * A revision of a migration pack: its replacement definition and the save it builds on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

/**
 * The replacement definition of a pack and, optionally, the `updatedAt` the
 * editor read it at, so a save made in between refuses the revision.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
 */
final class PackRevision {

	/**
	 * Name the revision.
	 *
	 * @param array<string, mixed> $definition        The replacement pack definition document.
	 * @param int|null             $expectedUpdatedAt The `updatedAt` the caller read, as a Unix time, or null for no check.
	 */
	public function __construct(
		public readonly array $definition,
		public readonly ?int $expectedUpdatedAt = null,
	) {
	}//end __construct()
}//end class
