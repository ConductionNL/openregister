<?php

/**
 * SchemaBatchMigratorInterface: what callers outside the Service component ask of SchemaMigrationService.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaRun;

/**
 * Migrating one batch of a schema run, as the schema run job does. Only the method that caller calls.
 */
interface SchemaBatchMigratorInterface {

	/**
	 * Process one batch of a migration run.
	 *
	 * @param SchemaRun $run The run.
	 * @param int $batchSize The batch size.
	 *
	 * @return bool True when more work remains.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function processBatch(SchemaRun $run, int $batchSize = SchemaMigrationService::DEFAULT_BATCH): bool;
}//end interface
