<?php

/**
 * OpenRegister conflict run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * One refused write being explained: the schema, the stored object, what the caller sent, and what others changed since the read.
 *
 * Built by ConflictReport::build() and never leaves ConflictReport.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ConflictRun {
	/**
	 * Constructor.
	 *
	 * @param Schema               $schema          The schema, for the field filter.
	 * @param array<string, mixed> $current         The stored object's match envelope.
	 * @param array<string, mixed> $sent            What the caller is trying to write.
	 * @param array<string, mixed> $changedByOthers The value each intervening write found, per property.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $current,
		public readonly array $sent,
		public readonly array $changedByOthers,
	) {
	}//end __construct()
}//end class
