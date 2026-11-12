<?php

/**
 * OpenRegister EntityLink
 *
 * One linked entity: its type (the metadata column) and its id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One linked entity: the type and the id that every link operation of
 * {@see LinkedEntityService} adds to or removes from an entity's column.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class EntityLink {

	/**
	 * Name the link.
	 *
	 * @param string $type     The linked entity type (e.g. 'mail', 'notes'), the column it lives in.
	 * @param string $entityId The linked entity id.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
