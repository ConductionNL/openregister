<?php

/**
 * Integriq ConnectionStatusReportedEvent test stub.
 *
 * Mirrors the constructor in hydra change connection-registry, design D6,
 * verbatim: parameter names, order and defaults. A stub that differs from the
 * contract would encode the caller's bug as correct. The real class ships in
 * integriq; tests/bootstrap.php loads this stub only when that class is absent.
 *
 * @category Test
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app reports what only it can observe about one declared connection.
 */
final class ConnectionStatusReportedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $app     The declaring app id.
	 * @param string $key     The connection key from the app's connections.json.
	 * @param string $status  configured, limited, unconfigured, simulated, unavailable or error.
	 * @param string $message What the app observed.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
		public readonly string $status,
		public readonly string $message = '',
	) {
		parent::__construct();
	}//end __construct()
}//end class
