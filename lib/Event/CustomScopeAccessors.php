<?php

/**
 * OpenRegister CustomScopeAccessors
 *
 * The accessors both custom-scope events share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Schema, action and user of a custom-scope evaluation.
 *
 * The using event declares `private readonly Schema $schema`,
 * `private readonly string $action` and `private readonly ?string $userId`
 * (constructor-promoted).
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
trait CustomScopeAccessors {
	use EvaluatedSchemaActionAccessors;

	/**
	 * User ID under evaluation.
	 *
	 * @return string|null The user id, or null for an anonymous evaluation.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()
}//end trait
