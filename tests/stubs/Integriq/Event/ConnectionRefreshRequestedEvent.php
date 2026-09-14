<?php

/**
 * Integriq ConnectionRefreshRequestedEvent test stub.
 *
 * Mirrors the constructor in hydra change connection-registry, design D6:
 * `(app, ?key)`. The real class ships in integriq; tests/bootstrap.php loads
 * this stub only when that class is absent.
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
 * An app asks integriq to resolve its connections again after a settings save.
 */
final class ConnectionRefreshRequestedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string      $app The declaring app id.
	 * @param string|null $key One connection key, or null for every connection of the app.
	 */
	public function __construct(
		public readonly string $app,
		public readonly ?string $key = null,
	) {
		parent::__construct();
	}//end __construct()
}//end class
