<?php

/**
 * OpenRegister bulk create check
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * One bulk row's create, as the permission handler judges it: the schema, the row's owner, the user and the register.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkCreateCheck {
	/**
	 * Constructor.
	 *
	 * @param Schema      $schema     The row's schema.
	 * @param mixed       $owner      The row's owner.
	 * @param string|null $userId     The user the save runs for.
	 * @param string|null $registerId The register of the call, as an id.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly mixed $owner,
		public readonly string|null $userId,
		public readonly string|null $registerId,
	) {
	}//end __construct()
}//end class
