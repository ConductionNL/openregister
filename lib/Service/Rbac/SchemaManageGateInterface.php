<?php

/**
 * SchemaManageGateInterface: what callers outside the component use of SchemaManageGate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * Declares only what callers outside the component use of SchemaManageGate; the rest
 * of the class stays its own.
 */
interface SchemaManageGateInterface {

	/**
	 * Whether the caller may manage a schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True for an administrator or a member of a group the schema's `manage` rule lists.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function mayManage(Schema $schema): bool;

	/**
	 * Whether the caller is an administrator.
	 *
	 * @return bool False without a session.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function callerIsAdministrator(): bool;

	/**
	 * The first schema a mixed-schema batch's rows name that the caller may not manage, or that cannot be resolved.
	 *
	 * A row that names no schema is left to the save, which records it as invalid.
	 *
	 * @param array<mixed> $objects The rows.
	 *
	 * @return string|null The first schema refused, or null when every named schema passes.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function firstUnmanageableRowSchema(array $objects): ?string;
}//end interface
