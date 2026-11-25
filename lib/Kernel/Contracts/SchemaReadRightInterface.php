<?php
/**
 * SchemaReadRightInterface: whether a person may read a schema's objects, for areas outside Core.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The schema read right, as the object permission check decides it.
 *
 * Bound to \OCA\OpenRegister\Service\Object\SchemaReadRight.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 */
interface SchemaReadRightInterface {

	/**
	 * Whether the user may read the schema's objects in the register.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $userId   The user.
	 * @param string|null     $register The register id, or null for any.
	 *
	 * @return bool True when the read is allowed.
	 */
	public function mayRead(SchemaInterface $schema, string $userId, ?string $register): bool;
}//end interface
