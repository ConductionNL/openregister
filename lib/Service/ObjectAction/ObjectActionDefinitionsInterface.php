<?php

/**
 * ObjectActionDefinitionsInterface: what callers outside the Service component ask of ObjectActionDefinitions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Finding a declared action, as the object-actions controller uses it. Only
 * the method that caller calls.
 */
interface ObjectActionDefinitionsInterface {

	/**
	 * The well-formed actions of a schema, inherited ones included.
	 *
	 * @param Schema                   $schema   The schema.
	 * @param Register|int|string|null $register The register, or its id, or null when unknown.
	 *
	 * @return array<string, ObjectActionDefinition> Action key => definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
	 */
	public function forSchema(Schema $schema, Register|int|string|null $register = null): array;
}//end interface
