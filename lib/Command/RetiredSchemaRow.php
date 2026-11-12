<?php

/**
 * OpenRegister retired schema row
 *
 * One schema row the prune-retired-schemas command found under a retired slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Schema;

/**
 * A schema row an app owns under a retired slug: the row, the slug as the
 * operator named it, and the objects the cascade would delete with it — the
 * count the guard checks and the teardown is held to.
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */
final class RetiredSchemaRow {
	/**
	 * Describe the row.
	 *
	 * @param Schema $schema      The schema row.
	 * @param string $slug        Its slug, as the operator named it.
	 * @param int    $objectCount The objects the cascade would delete.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $slug,
		public readonly int $objectCount,
	) {
	}//end __construct()
}//end class
