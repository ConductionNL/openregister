<?php

/**
 * ConnectionStatusReportInterface: report a named connection's status, from any area.
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
 * Reports the status of one of the connections the admin connections page lists.
 *
 * Kernel\Contracts\Compliance\ConnectionStatusReporterInterface extends it;
 * load shedding (Platform) reports breaker transitions through this one.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface ConnectionStatusReportInterface {

	/**
	 * Report a connection's status.
	 *
	 * @param string $key     The connection key.
	 * @param string $status  The status, for example `configured` or `unavailable`.
	 * @param string $message A short explanation.
	 *
	 * @return bool Whether it was recorded.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function report(string $key, string $status, string $message = ''): bool;
}//end interface
