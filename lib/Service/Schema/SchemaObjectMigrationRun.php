<?php

/**
 * OpenRegister SchemaObjectMigrationRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The migration of one object within a batch of a migration run.
 *
 * Built by {@see SchemaMigrationService} for each object it applies the
 * plan to; it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class SchemaObjectMigrationRun {

	/**
	 * The batch the object belongs to.
	 *
	 * @var SchemaRunBatch|null
	 */
	public ?SchemaRunBatch $batch = null;

	/**
	 * The object as it was read, before the plan applied.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;
}//end class
