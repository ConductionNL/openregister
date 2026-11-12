<?php

/**
 * OpenRegister contact placement
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Where a contact is linked: the object, its register, optionally its schema,
 * and the role the contact plays on it.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactPlacement {
	/**
	 * Hold the placement.
	 *
	 * @param string   $objectUuid The object UUID.
	 * @param int      $registerId The register id.
	 * @param int|null $schemaId   The schema id, or null to leave the link's schema as is.
	 * @param mixed    $role       The role as the caller gave it (createAndLinkContact
	 *                             passes the payload value unchecked), or null.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly ?int $schemaId,
		public readonly mixed $role,
	) {
	}//end __construct()
}//end class
