<?php

/**
 * ScopeAuditInterface: the part of ScopeAudit that the permissions audit controller uses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The methods of ScopeAudit that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ScopeAuditInterface {

	/**
	 * One row per schema of these registers.
	 *
	 * A schema that belongs to none of the registers in hand is skipped rather
	 * than reported against all of them: an audit that paired every schema with
	 * every register would report rules that do not apply, which is worse than
	 * reporting none.
	 *
	 * @param array<int, Register> $registers The registers to report on.
	 * @param array<int, Schema>   $schemas   The schemas to consider.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rows(array $registers, array $schemas): array;
}//end interface
