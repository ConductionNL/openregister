<?php

/**
 * SystemAuditLineInterface: writes one audit line for work the system did on its own.
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
 * Lets any area record a system audit line (user 'system') without naming the trail's Db classes.
 */
interface SystemAuditLineInterface {

	/**
	 * Write one audit line by the system, dated now.
	 *
	 * @param string               $action   What happened, for example 'bag.load'.
	 * @param string               $userName The display name the line carries.
	 * @param array<string, mixed> $changed  What changed.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception When the trail cannot store it.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function recordSystemLine(string $action, string $userName, array $changed): void;
}//end interface
