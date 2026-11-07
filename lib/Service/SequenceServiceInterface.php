<?php

/**
 * SequenceServiceInterface: the part of SequenceService that the generated-identifier and calculation listeners use.
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

use OCP\DB\Exception as DbException;

/**
 * The methods of SequenceService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SequenceServiceInterface {

	/**
	 * Atomically reserve and return the next number for the given scope.
	 *
	 * The returned value is never handed out twice for the same
	 * (register, schema, scope) triple. Numbering starts at 1.
	 *
	 * @param int $registerId The register the sequence is scoped to.
	 * @param int $schemaId The schema the sequence is scoped to.
	 * @param string $scopeKey The scope discriminator (e.g. "2026", "2026-06" or "" for global).
	 *
	 * @return int The reserved running number (>= 1).
	 *
	 * @throws DbException When the reservation cannot be completed.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function reserveNext(int $registerId, int $schemaId, string $scopeKey): int;

	/**
	 * Push a counter past a number that is already printed on a record.
	 *
	 * An import that supplies `Z-2026-00120` has to leave the counter above 120,
	 * or the next create issues `Z-2026-00001` and the collision does not
	 * surface until the hundred-and-twentieth create. By then the import looks
	 * like it worked.
	 *
	 * Never moves a counter DOWN. `raiseTo()` carries the `<` clause that keeps
	 * that true even when an import of old numbers runs beside live creates.
	 *
	 * @param int $registerId The register the sequence is scoped to.
	 * @param int $schemaId The schema the sequence is scoped to.
	 * @param string $scopeKey The scope discriminator.
	 * @param int $reserved The number already issued elsewhere.
	 *
	 * @return void
	 *
	 * @throws DbException When the counter cannot be read or written.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function advanceTo(int $registerId, int $schemaId, string $scopeKey, int $reserved): void;
}//end interface
