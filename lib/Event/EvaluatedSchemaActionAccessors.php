<?php

/**
 * OpenRegister EvaluatedSchemaActionAccessors
 *
 * The schema and action accessors every access-evaluation event carries.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Read-only access to the evaluated schema and action verb.
 *
 * The using event declares `private readonly Schema $schema` and
 * `private readonly string $action` (constructor-promoted).
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
trait EvaluatedSchemaActionAccessors {
	/**
	 * Schema the evaluation is about.
	 *
	 * @return Schema The schema involved in the evaluation.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()

	/**
	 * Action verb the evaluation is about.
	 *
	 * @return string The action verb.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getAction(): string {
		return $this->action;
	}//end getAction()
}//end trait
