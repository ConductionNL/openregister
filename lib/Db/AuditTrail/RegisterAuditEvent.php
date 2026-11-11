<?php

/**
 * OpenRegister RegisterAuditEvent
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

use InvalidArgumentException;

/**
 * Something that happened to a register as a whole, for the audit trail.
 *
 * The operation object of AuditTrailMapper::recordRegisterEvent(). The action is
 * namespaced as `<area>.<verb>` (e.g. `compliance.export_requested`), so it can
 * never be read as one of the plain `create` / `update` / `delete` / `read`
 * object actions the statistics count.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
final class RegisterAuditEvent {

	/**
	 * Constructor.
	 *
	 * @param int                  $register The register id the event is about.
	 * @param string               $action   The namespaced action, `<area>.<verb>`.
	 * @param array<string, mixed> $details  What the event records; stored as the entry's result summary.
	 * @param NamedActor           $actor    The acting principal; without an id the entry names the session
	 *                                       user, or `system` when there is none.
	 *
	 * @throws InvalidArgumentException When the register id is not positive or the action is not namespaced.
	 */
	public function __construct(
		public readonly int $register,
		public readonly string $action,
		public readonly array $details = [],
		public readonly NamedActor $actor = new NamedActor(),
	) {
		if ($register < 1) {
			throw new InvalidArgumentException('A register event names a register id, got ' . $register . '.');
		}

		if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $action) !== 1) {
			throw new InvalidArgumentException('A register event action is namespaced as <area>.<verb>, got "' . $action . '".');
		}
	}//end __construct()
}//end class
