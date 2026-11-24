<?php
/**
 * AuditEntry: one entry Core puts on the audit trail.
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

use DateTimeImmutable;

/**
 * What happened, who did it and to what, before the audit trail stores and seals it.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
final class AuditEntry {

	/**
	 * Constructor.
	 *
	 * @param string               $uuid       The entry's own uuid.
	 * @param string               $action     What happened, for example 'organisation.status'.
	 * @param string               $user       The acting user's uid, or 'system'.
	 * @param string               $userName   The acting user's display name.
	 * @param array<string, mixed> $changed    What changed.
	 * @param DateTimeImmutable    $created    When it happened.
	 * @param string|null          $objectUuid     The uuid of what it happened to, when there is one.
	 * @param string|null          $organisationId The active organisation it happened in, when one is recorded.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $action,
		public readonly string $user,
		public readonly string $userName,
		public readonly array $changed,
		public readonly DateTimeImmutable $created,
		public readonly ?string $objectUuid = null,
		public readonly ?string $organisationId = null,
	) {
	}//end __construct()
}//end class
