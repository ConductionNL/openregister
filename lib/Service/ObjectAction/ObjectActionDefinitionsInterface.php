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
	 * The definition of one action of a schema, including inherited ones.
	 *
	 * @param Schema                   $schema   The schema.
	 * @param string                   $key      The action key.
	 * @param Register|int|string|null $register The register, or its id.
	 *
	 * @return ObjectActionDefinition|null The definition.
	 */
	public function find(Schema $schema, string $key, Register|int|string|null $register = null): ?ObjectActionDefinition;
}//end interface
