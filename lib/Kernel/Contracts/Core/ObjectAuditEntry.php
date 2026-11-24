<?php
/**
 * ObjectAuditEntry: one action on one object that Core puts on the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object an action happened to, what happened, and who did it when the caller names them.
 *
 * Without an actor id the trail records the session user and ignores the name.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class ObjectAuditEntry {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $object    The object the entry relates to.
	 * @param string               $action    The action, e.g. `lock.broken`.
	 * @param array<string, mixed> $context   Additional context, stored as the entry's `changed`.
	 * @param string|null          $actorId   The explicit actor id, or null for the session user.
	 * @param string|null          $actorName The actor's display name, paired with the id.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $action,
		public readonly array $context = [],
		public readonly ?string $actorId = null,
		public readonly ?string $actorName = null,
	) {
	}//end __construct()
}//end class
