<?php

/**
 * RegisterSchemaLinkageRepairServiceInterface: the part of RegisterSchemaLinkageRepairService that the relink command uses.
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

/**
 * The methods of RegisterSchemaLinkageRepairService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RegisterSchemaLinkageRepairServiceInterface {

	/**
	 * Inspect registers for recoverable schema linkage.
	 *
	 * Reports only — never mutates. Row counts are included because they separate
	 * strong evidence (a table holding rows) from weak (an empty table left by a
	 * schema attached and never used). Both are recoverable; the operator should be
	 * able to see which is which before deciding.
	 *
	 * @param int|null $registerId Inspect only this register, or all when null.
	 *
	 * @return array<int, array{registerId:int, registerSlug:string|null, currentIds:int[], recoverable:array<int,int>}>
	 *         One entry per register that would gain at least one id.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function inspect(?int $registerId=null): array;

	/**
	 * Apply a repair to one register.
	 *
	 * Strictly additive: the stored ids are preserved and the recovered ids merged
	 * in. The method never removes an id, so a register cannot lose correct
	 * configuration because one of its schemas has no objects yet.
	 *
	 * @param int   $registerId The register to repair.
	 * @param int[] $schemaIds  The schema ids to add.
	 *
	 * @return int[] The register's schema ids after the repair.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function apply(int $registerId, array $schemaIds): array;
}//end interface
