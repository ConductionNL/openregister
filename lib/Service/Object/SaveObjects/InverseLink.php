<?php

/**
 * OpenRegister InverseLink
 *
 * One saved object's relation property whose targets get the saved object written back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * The saved object's uuid, the property on the target that lists it, and the related ids.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class InverseLink {

	/**
	 * Constructor.
	 *
	 * @param string|null $sourceUuid      The saved object's uuid
	 * @param mixed       $inverseProperty The property on the target that lists the saved object
	 * @param array       $relatedIds      The ids the saved object's property holds
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly ?string $sourceUuid,
		public readonly mixed $inverseProperty,
		public readonly array $relatedIds,
	) {
	}//end __construct()
}//end class
