<?php

/**
 * ObjectReadCheckInterface: whether the caller may read one object.
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
 * Answers whether the session user may read an object, under RBAC and multitenancy.
 *
 * The port another component uses instead of the Core object read gate
 * (Service\Object\ObjectReadAccessInterface), which hands back the Db entity.
 * Bound to Service\Object\ObjectReadAccess.
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
 */
interface ObjectReadCheckInterface {
	/**
	 * Whether the session user may read the object an address names.
	 *
	 * No session user, an unknown object, an object the caller may not read and an
	 * identifier two objects hold all answer false.
	 *
	 * @param ObjectRef $ref The register and schema (slug or id) and the object's id, uuid or slug.
	 *
	 * @return bool True when the object is readable.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
	 */
	public function isReadable(ObjectRef $ref): bool;
}//end interface
