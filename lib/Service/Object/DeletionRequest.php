<?php

/**
 * OpenRegister DeletionRequest
 *
 * One request to delete an object by uuid within a register and schema scope.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema scope, the uuid and the options of one deletion.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DeletionRequest {

	/**
	 * Constructor.
	 *
	 * @param Register|int|string|null $register The register scope (entity, id, uuid or slug).
	 * @param Schema|int|string|null   $schema   The schema scope (entity, id, uuid or slug).
	 * @param string                   $uuid     The identifier of the object to delete.
	 * @param DeletionOptions          $options  How the deletion runs.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly Register|int|string|null $register,
		public readonly Schema|int|string|null $schema,
		public readonly string $uuid,
		public readonly DeletionOptions $options=new DeletionOptions(),
	) {
	}//end __construct()
}//end class
