<?php
/**
 * ConnectionStatusReporterInterface: tells the connection registry what a connection test found.
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

use OCA\OpenRegister\Kernel\Contracts\ConnectionStatusReportInterface;

/**
 * Reports one connection's status.
 *
 * The anonymisation backend (Compliance) reports what its connection test
 * found; the connection reporter (Integrations) sends it on and is bound to
 * this contract.
 */
interface ConnectionStatusReporterInterface extends ConnectionStatusReportInterface {

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: this runs beside a test or a save whose own answer is what
	 * the caller returns. Without integriq nothing is sent and nothing is
	 * logged, because a missing optional app is not a fault.
	 *
	 * @param string $key     The connection key.
	 * @param string $status  The connection status.
	 * @param string $message What OpenRegister observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function report(string $key, string $status, string $message = ''): bool;
}//end interface
