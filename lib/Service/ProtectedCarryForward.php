<?php

/**
 * ProtectedCarryForward: an update whose omitted protected properties are kept from the stored object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * One update: the schema, the incoming payload and the stored object, from
 * which the protected properties the caller omitted are carried forward.
 *
 * ADR-011 Rule 2 operation object for PropertyRbacHandler::omittedProtectedProperties().
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
 */
final class ProtectedCarryForward {

	/**
	 * Describe it.
	 *
	 * @param Schema $schema   The schema the object belongs to.
	 * @param array  $incoming The incoming update payload.
	 * @param array  $stored   The stored object.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $incoming,
		public readonly array $stored,
	) {
	}//end __construct()
}//end class
