<?php

/**
 * The run ledger and the object population of one schema.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRunEntryMapperInterface;
use OCA\OpenRegister\Db\SchemaRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * What a run over a schema's objects reads and records: the run and its
 * per-object entries, the schema, and the objects of the population.
 *
 * Shared by the revalidation and migration passes and the versioning reads of
 * the latest run (ADR-011 Rule 4).
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class SchemaPopulationRuns {
	/**
	 * Bundle the run ledger and the population source.
	 *
	 * @param SchemaRunMapperInterface      $runMapper      Run persistence.
	 * @param SchemaRunEntryMapperInterface $runEntryMapper Per-object run entries.
	 * @param SchemaMapperInterface         $schemaMapper   Schema lookup.
	 * @param ObjectOperationsInterface     $objectService  Object read/save pipeline.
	 */
	public function __construct(
		public readonly SchemaRunMapperInterface $runMapper,
		public readonly SchemaRunEntryMapperInterface $runEntryMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
