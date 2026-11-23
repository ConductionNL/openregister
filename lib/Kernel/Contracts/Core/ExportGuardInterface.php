<?php

/**
 * ExportGuardInterface: whether an export may run, as the object store's export paths ask it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Answers whether an export may run, as the refusal response to return or null.
 *
 * The export paths of the object store (Core) ask; the export permission
 * gate (Configuration) answers and is bound to this contract.
 */
interface ExportGuardInterface {

	/**
	 * The refusal this export should return, or null when it may run.
	 *
	 * @param Schema|null $schema     The schema being exported.
	 * @param string      $profile    What the audit entry calls this export.
	 * @param int|null    $registerId The register, for the audit entry.
	 *
	 * @return JSONResponse|null The refusal, or null when the export may run.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 */
	public function refusalFor(?Schema $schema, string $profile, ?int $registerId = null): ?JSONResponse;
}//end interface
