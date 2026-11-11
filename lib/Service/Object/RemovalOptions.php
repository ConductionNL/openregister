<?php

/**
 * OpenRegister RemovalOptions
 *
 * How DeleteObject::delete() removes one object row.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * The cascade tagging, the permanence and the pre-resolved table of one row removal.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RemovalOptions {

	/**
	 * Constructor.
	 *
	 * @param array|null          $cascadeContext Cascade metadata for audit trail tagging ('triggerObject', 'triggerSchema',
	 *                                            'action_type', ...); non-null when referential integrity triggered the delete.
	 * @param bool                $permanent      Physically remove the row instead of soft-deleting it.
	 * @param RegisterSchema|null $table          The object's already-resolved register and schema; with an ObjectEntity
	 *                                            it skips the cross-table re-find of the object's context.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Named option fields with defaults.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ?array $cascadeContext=null,
		public readonly bool $permanent=false,
		public readonly ?RegisterSchema $table=null,
	) {
	}//end __construct()
}//end class
