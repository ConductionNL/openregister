<?php

/**
 * OpenRegister MigrationSide
 *
 * One end of a migration: the register and schema as requested, and as loaded.
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

/**
 * The register and schema one end of a migration names, and the entities they load to.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MigrationSide {

	/**
	 * The loaded register, or null when it was not found.
	 *
	 * @var mixed
	 */
	public mixed $registerEntity = null;

	/**
	 * The loaded schema, or null when it was not found.
	 *
	 * @var mixed
	 */
	public mixed $schemaEntity = null;

	/**
	 * Constructor.
	 *
	 * @param string|int $register The register id or slug as requested.
	 * @param string|int $schema   The schema id or slug as requested.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
	) {
	}//end __construct()

	/**
	 * Whether both the register and the schema were loaded.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isLoaded(): bool {
		return $this->registerEntity !== null && $this->schemaEntity !== null;
	}//end isLoaded()
}//end class
