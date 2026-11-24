<?php

/**
 * OpenRegister OperatorActionAudit
 *
 * Audits an administrator's action on the platform itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\OperatorActionAuditInterface;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * Writes one audit trail entry as the current user, through the audit entry port.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
final class OperatorActionAudit implements OperatorActionAuditInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditEntryWriterInterface $entries     The audit trail writer.
	 * @param IUserSession              $userSession The current user.
	 */
	public function __construct(
		private readonly AuditEntryWriterInterface $entries,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * @inheritDoc
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 */
	public function record(string $action, array $changed): void {
		$user = $this->userSession->getUser();

		$this->entries->writeAuditEntries(
			entries: [
				new AuditEntry(
					uuid: (string) Uuid::v4(),
					action: $action,
					user: (string) $user?->getUID(),
					userName: (string) $user?->getDisplayName(),
					changed: $changed,
					created: new DateTimeImmutable(),
				),
			]
		);
	}//end record()
}//end class
