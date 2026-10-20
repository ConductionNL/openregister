<?php

/**
 * The outcome of importing a migration pack document.
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
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use OCA\OpenRegister\Db\MigrationPack;

/**
 * The stored pack and whether the import created it or replaced an existing one.
 *
 * Reported by the write itself, so the audit log line says what the service
 * did rather than what a lookup before the write expected it to do.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */
final class MigrationPackImportResult {
	/**
	 * Constructor.
	 *
	 * @param MigrationPack $pack    The stored pack.
	 * @param bool          $created True when the import inserted a new row, false when it replaced one.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function __construct(
		public readonly MigrationPack $pack,
		public readonly bool $created,
	) {
	}//end __construct()
}//end class
