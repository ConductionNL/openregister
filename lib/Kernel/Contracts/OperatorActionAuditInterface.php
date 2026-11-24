<?php

/**
 * OperatorActionAuditInterface: audit an administrator's action, from any area.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Writes one audit trail entry for an action the current user took on the
 * platform itself (not on an object), such as resetting a breaker.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface OperatorActionAuditInterface {

	/**
	 * Record the action as the current user.
	 *
	 * @param string               $action  The audit action.
	 * @param array<string, mixed> $changed What changed.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the entry cannot be written.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function record(string $action, array $changed): void;
}//end interface
