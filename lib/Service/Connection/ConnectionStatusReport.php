<?php

/**
 * ConnectionStatusReport: what OpenRegister tells integriq about one connection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

/**
 * One status report for integriq's connection registry (ADR-011 Rule 2).
 *
 * Carries what was observed; {@see ConnectionReporter::reportStatus()} checks
 * the key and status against the declared sets before anything is sent.
 *
 * @spec openspec/specs/app-connections/spec.md
 */
final class ConnectionStatusReport {

	/**
	 * Constructor.
	 *
	 * @param string $key     One of {@see ConnectionReporter::KEYS}.
	 * @param string $status  One of {@see ConnectionReporter::STATUSES}.
	 * @param string $message What OpenRegister observed.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $status,
		public readonly string $message = '',
	) {
	}//end __construct()
}//end class
