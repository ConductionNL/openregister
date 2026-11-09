<?php

/**
 * OpenRegister NamedActor
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * The actor a caller names for an audit entry instead of the session user.
 *
 * Both fields are optional: an entry written without them falls back to the
 * actor its own recording method chooses (the session user, or `system`).
 */
final class NamedActor {

	/**
	 * Constructor.
	 *
	 * @param string|null $id   The actor id, or null to fall back.
	 * @param string|null $name The display name or pseudonymous reference, paired with the id.
	 */
	public function __construct(
		public readonly ?string $id = null,
		public readonly ?string $name = null,
	) {
	}//end __construct()
}//end class
