<?php

/**
 * OpenRegister ObjectService removal services: delete, cascade, merge and migration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;

/**
 * ObjectService removal services: delete, cascade, merge and migration.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectRemovalServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeleteObject     $deleteHandler    Handler for object deletion.
	 * @param CascadingHandler $cascadingHandler Handler for cascading operations.
	 * @param MergeHandler     $mergeHandler     Handler for merge and migration.
	 * @param MigrationHandler $migrationHandler Handler for migration operations.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly DeleteObject $deleteHandler,
		public readonly CascadingHandler $cascadingHandler,
		public readonly MergeHandler $mergeHandler,
		public readonly MigrationHandler $migrationHandler,
	) {
	}//end __construct()
}//end class
