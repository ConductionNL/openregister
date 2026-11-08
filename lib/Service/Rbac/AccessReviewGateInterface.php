<?php

/**
 * The gate the access-review endpoints ask before they answer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The gate the access-review endpoints ask before they answer.
 *
 * Bound to AccessReviewGate in ContractBindings. Every method is described on AccessReviewGate.
 */
interface AccessReviewGateInterface {

	/**
	 * Resolve the object through the RBAC boundary, or null when the caller cannot read it.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function resolve(string $register, string $schema, string $id): ?ObjectEntity;

	/**
	 * Whether the caller may read the object's access set (`/permissions`).
	 *
	 * The owner, an administrator, or a holder of `manage` through a rule
	 * somebody actually wrote.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return bool True when the caller may ask.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function mayReviewAccess(ObjectEntity $object): bool;

	/**
	 * Whether the caller may read the object's grants list (`/grants`).
	 *
	 * Everybody {@see mayReviewAccess()} admits, the owning group and a holder
	 * of the organisation's `grant_manage` special right on the record, and a
	 * caller the record's `readGrants` rule admits.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return bool True when the caller may ask.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function mayReadGrants(ObjectEntity $object): bool;
}//end interface
