<?php

/**
 * Records an organisation's lifecycle on the audit trail.
 *
 * An organisation is not a register object, so suspending, reactivating, ending
 * or renaming one left no entry on the hash-chained audit trail: the status and
 * its timestamp were the only trace, and they say what is, not who did it. Each
 * change now writes one entry keyed by the organisation's uuid (`object_uuid`),
 * so the admin audit list can be asked for one organisation's history.
 *
 * FAIL-SOFT, like the settings and grant auditors: the change has been stored
 * when this runs, and failing here would report a failed change that in fact
 * happened. A missing entry is logged at ERROR instead.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Organisation;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Turns a change of an organisation into an audit-trail entry.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
class OrganisationLifecycleAuditor {

	/**
	 * The typed action a change of an organisation's status writes.
	 *
	 * @var string
	 */
	public const ACTION_STATUS = 'organisation.status_changed';

	/**
	 * The typed action an edit of an organisation's details writes.
	 *
	 * @var string
	 */
	public const ACTION_UPDATED = 'organisation.updated';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $mapper      The trail, which seals every row it writes.
	 * @param IUserSession     $userSession Who made the change.
	 * @param LoggerInterface  $logger      The logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a change of an organisation's status.
	 *
	 * @param Organisation $organisation The organisation, after the change.
	 * @param string       $before       The status before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function recordStatus(Organisation $organisation, string $before): void {
		$after = (string) ($organisation->getStatus() ?? '');
		if ($after === $before) {
			return;
		}

		$this->write(
			organisation: $organisation,
			action: self::ACTION_STATUS,
			changed: ['status' => ['old' => $before, 'new' => $after]]
		);
	}//end recordStatus()

	/**
	 * Record an edit of an organisation's details.
	 *
	 * @param Organisation         $organisation The organisation, after the edit.
	 * @param array<string, mixed> $before       The edited fields as they were, by name.
	 * @param array<string, mixed> $after        The same fields as they are now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function recordUpdate(Organisation $organisation, array $before, array $after): void {
		$changed = [];
		foreach ($after as $field => $value) {
			$old = ($before[$field] ?? null);
			if ($old !== $value) {
				$changed[$field] = ['old' => $old, 'new' => $value];
			}
		}

		if ($changed === []) {
			return;
		}

		$this->write(organisation: $organisation, action: self::ACTION_UPDATED, changed: $changed);
	}//end recordUpdate()

	/**
	 * Write one sealed entry; never throws.
	 *
	 * @param Organisation         $organisation The organisation.
	 * @param string               $action       The typed action.
	 * @param array<string, mixed> $changed      What changed.
	 *
	 * @return void
	 */
	private function write(Organisation $organisation, string $action, array $changed): void {
		$user     = $this->userSession->getUser();
		$userId   = 'system';
		$userName = 'System';
		if ($user !== null) {
			$userId   = $user->getUID();
			$userName = $user->getDisplayName();
		}

		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setObjectUuid($organisation->getUuid());
		$row->setAction($action);
		$row->setUser($userId);
		$row->setUserName($userName);
		$row->setChanged($changed);
		$row->setCreated(new DateTime());

		try {
			$this->mapper->insertAuditTrails(entries: [$row]);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[OrganisationLifecycleAuditor] Could not record a change of an organisation; the change itself was stored',
				context: [
					'file'      => __FILE__,
					'line'      => __LINE__,
					'action'    => $action,
					'uuid'      => $organisation->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end write()
}//end class
