<?php
/**
 * ElevationAttributionInterface: the declared action that elevated a write, as the audit trail stamps it on the row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Answers whether an audited write was elevated by a declared object action, and by whom.
 *
 * The audit trail (Compliance) asks while it builds or inserts a row; the
 * object-action run (Processes) answers from the request-scoped elevation frame
 * it opened, and binds itself to this contract in its own wiring. The answer is
 * request state: null means no open elevation covered this register, schema and
 * verb.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
interface ElevationAttributionInterface {

	/**
	 * The elevation attribution for a row, if the open action frame elevated a write on its register, schema and verb.
	 *
	 * @param AuditTrail $row The audit row being written.
	 *
	 * @return array{elevatedBy: string, triggeredBy: string|null, runAs: string}|null The attribution, or null.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function attributionFor(AuditTrail $row): ?array;
}//end interface
