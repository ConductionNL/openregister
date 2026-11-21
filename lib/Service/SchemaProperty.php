<?php

/**
 * OpenRegister schema property
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

use OCA\OpenRegister\Db\Schema;

/**
 * One property of a schema, as a property-level access rule names it.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class SchemaProperty {
	/**
	 * Constructor.
	 *
	 * @param Schema $schema Schema containing the property definition.
	 * @param string $name   The property name.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
