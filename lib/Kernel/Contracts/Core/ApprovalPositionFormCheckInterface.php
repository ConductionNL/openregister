<?php
/**
 * ApprovalPositionFormCheckInterface: which approval-position forms of a schema body no approver could fill in.
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

/**
 * Judges, at schema save, the forms a schema body's approval positions declare.
 *
 * The schema API (Core) asks, only for a body that declares a position form;
 * the approval-position form guard (Processes) answers and is bound to this
 * contract in its own registrar.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
interface ApprovalPositionFormCheckInterface {

	/**
	 * One refusal per position form that cannot be filled in, naming the chain, the position, the field and why.
	 *
	 * @param array<string, mixed> $data The schema body: `properties`, `configuration`, `slug`.
	 *
	 * @return array<int, string> The refusals, empty when every form can be filled in.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public function violations(array $data): array;
}//end interface
