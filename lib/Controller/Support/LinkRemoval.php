<?php

/**
 * OpenRegister link removal
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
 * The removal of one linked entity from an object: the object, the linked
 * entity type and the entity.
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
 */
final class LinkRemoval {
	/**
	 * Describe the removal.
	 *
	 * @param string $objectUuid The object the link is removed from.
	 * @param string $type       The linked entity type.
	 * @param string $entityId   The entity to unlink.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $type,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
