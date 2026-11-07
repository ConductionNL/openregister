<?php

/**
 * SharedSchemaDedupeServiceInterface: the part of SharedSchemaDedupeService that the dedupe command uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use RuntimeException;

/**
 * The methods of SharedSchemaDedupeService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SharedSchemaDedupeServiceInterface {

	/**
	 * Parse the repeatable `--keep` option.
	 *
	 * @param array<int, mixed> $raw The raw option values.
	 *
	 * @return array{perSchema: array<int,int>, global: int|null} The parsed overrides.
	 *
	 * @throws RuntimeException When a value is not a positive id or id pair.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function parseKeep(array $raw): array;

	/**
	 * Inspect the instance and produce the full repair plan.
	 *
	 * Reports only — never mutates, so the operator sees every split, every row
	 * move and every column that would be left behind before opting in.
	 *
	 * @param int|null          $registerId Limit to plans involving this register, or null for all.
	 * @param array<string, mixed> $keep    The parsed `--keep` overrides.
	 *
	 * @return array<int, array<string, mixed>> One entry per shared schema.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function inspect(?int $registerId, array $keep): array;

	/**
	 * Execute one planned split.
	 *
	 * Creation of the schema, the relink and the row move are one unit: a
	 * half-applied split leaves a register pointing at an entity whose table holds
	 * no rows, which is worse than the shared state it started from.
	 *
	 * @param array<string, mixed> $entry  One {@see SharedSchemaDedupeService::inspect()} plan entry.
	 * @param int                  $target The non-canonical register id to split off.
	 * @param bool                 $strict Whether unmapped columns must refuse the move.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 *
	 * @throws RuntimeException When the plan is unattributed, or strict mode refuses.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function applySplit(array $entry, int $target, bool $strict): array;
}//end interface
