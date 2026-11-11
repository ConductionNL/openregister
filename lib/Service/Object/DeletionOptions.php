<?php

/**
 * OpenRegister DeletionOptions
 *
 * The optional parts of one object deletion: cascade origin, scope guarantee, pre-resolved entity and permanence.
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * How one deletion runs. The defaults are a root soft delete with the legacy cross-table lookup.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DeletionOptions {

	/**
	 * Constructor.
	 *
	 * @param string|null       $originalObjectId The root object's id when this is a cascade sub-deletion, null for a root deletion.
	 * @param bool              $scoped           The register and schema resolve to one magic table and the lookup targets only
	 *                                            that table (#1638); false uses the legacy cross-table lookup.
	 * @param ObjectEntity|null $preResolved      An already-fetched entity for the uuid; used only when its uuid matches and
	 *                                            both scope values are concrete entities.
	 * @param bool              $permanent        Destroy the row instead of tombstoning it (see DeleteObject::deleteObject()).
	 * @param string|null       $actingUserId     The user the delete acts for; null resolves the session user.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Named option fields with defaults.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?string $originalObjectId=null,
		public readonly bool $scoped=false,
		public readonly ?ObjectEntity $preResolved=null,
		public readonly bool $permanent=false,
		public readonly ?string $actingUserId=null,
	) {
	}//end __construct()

	/**
	 * Whether this is a root deletion rather than a cascade sub-deletion.
	 *
	 * @return bool True for a root deletion.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isRoot(): bool {
		return $this->originalObjectId === null;
	}//end isRoot()
}//end class
