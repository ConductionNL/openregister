<?php

/**
 * OpenRegister schema revalidator contract
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
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

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaRun;

/**
 * Starts a population revalidation run of a schema and drives it batch by batch.
 *
 * The controller starts a run; the background job processes its batches.
 */
interface SchemaRevalidatorInterface {
	/**
	 * Start a revalidation run.
	 *
	 * @param RevalidationRequest $request   The schema, its register and the optional proposed definition (null = current).
	 * @param string|null         $startedBy User id.
	 *
	 * @return SchemaRun The created run.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function startRevalidation(RevalidationRequest $request, ?string $startedBy = null): SchemaRun;

	/**
	 * Process the next batch of a run.
	 *
	 * @param SchemaRun $run       The run.
	 * @param int       $batchSize Objects per batch.
	 *
	 * @return bool True when more work remains.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function processBatch(SchemaRun $run, int $batchSize = SchemaRevalidationService::DEFAULT_BATCH): bool;
}//end interface
