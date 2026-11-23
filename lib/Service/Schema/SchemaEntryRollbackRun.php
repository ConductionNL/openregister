<?php

/**
 * OpenRegister SchemaEntryRollbackRun
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

use OCA\OpenRegister\Db\SchemaRunEntry;

/**
 * The rollback of one migrated entry of a migration run.
 *
 * Built by {@see SchemaMigrationService} for each migrated entry it rolls
 * back; it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class SchemaEntryRollbackRun {

	/**
	 * The rollback, as a batch over the run's schema.
	 *
	 * @var SchemaRunBatch|null
	 */
	public ?SchemaRunBatch $batch = null;

	/**
	 * The migrated entry being rolled back.
	 *
	 * @var SchemaRunEntry|null
	 */
	public ?SchemaRunEntry $entry = null;

	/**
	 * The restored and conflict counts of the whole rollback.
	 *
	 * @var SchemaRollbackTally|null
	 */
	public ?SchemaRollbackTally $tally = null;
}//end class
