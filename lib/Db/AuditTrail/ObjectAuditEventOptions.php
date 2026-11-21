<?php

/**
 * OpenRegister ObjectAuditEventOptions
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
 * Who an object event names as its actor and where it came from, when not the session.
 */
final class ObjectAuditEventOptions {

	/**
	 * Constructor.
	 *
	 * @param NamedActor  $actor     Explicit actor; without an id the session user is recorded.
	 * @param string|null $ipAddress The calling address, for a principal that is not a session.
	 */
	public function __construct(
		public readonly NamedActor $actor = new NamedActor(),
		public readonly ?string $ipAddress = null,
	) {
	}//end __construct()
}//end class
