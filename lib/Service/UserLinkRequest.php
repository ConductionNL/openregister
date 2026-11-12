<?php

/**
 * A request to link a Nextcloud user to an object.
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
 * A request to link a Nextcloud user to an object: the object (uuid, register
 * and schema) and the account to link. The role travels beside it.
 *
 * ADR-011 Rule 2 operation object for {@see UserLinkWriter::write()}.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */
final class UserLinkRequest {
	/**
	 * Build the request.
	 *
	 * @param string   $objectUuid The object uuid.
	 * @param int      $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 * @param string   $userId     The account to link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly ?int $schemaId,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
