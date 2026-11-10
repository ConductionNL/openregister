<?php

/**
 * One pass of MappingEngine over a source row: the row, its number and the result built so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

/**
 * The run state MappingEngine::mapRow() builds and its helpers share (ADR-011 Rule 3).
 * It never leaves MappingEngine.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
final class RowMappingRun {
	/**
	 * The mapped row so far: target data, and the errors labelled with the row number.
	 *
	 * @var array{data: mixed, errors: list<array<string, mixed>>}
	 */
	public array $mapped = ['data' => [], 'errors' => []];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $sourceRow The parsed source row.
	 * @param int                  $rowNumber 1-based row number, used only to label errors.
	 */
	public function __construct(
		public readonly array $sourceRow,
		public readonly int $rowNumber,
	) {
	}//end __construct()
}//end class
