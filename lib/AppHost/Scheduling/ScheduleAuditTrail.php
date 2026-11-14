<?php

/**
 * OpenRegister AppHost: records whose rights an application's schedule runs with.
 *
 * Publishing as someone else changes that identity, so each change lands on the
 * application's audit trail with the previous user, the new user and the time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the audit entry for a changed schedule identity.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the id factory AuditTrail rows use.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
class ScheduleAuditTrail {
	/**
	 * The audit action of a changed identity.
	 */
	public const ACTION = 'schedule.run_as_changed';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $audit The audit trail.
	 */
	public function __construct(
		private readonly AuditTrailMapper $audit,
	) {
	}//end __construct()

	/**
	 * Record that a schedule is about to run as its publisher.
	 *
	 * @param CompiledScheduleFlow $compiled The flow about to be written.
	 * @param string|null $previous The identity before, or null for a new schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function recordRunAs(CompiledScheduleFlow $compiled, ?string $previous): void {
		$entry = $this->entryFor(compiled: $compiled);
		$entry->setChanged(
			['schedule' => $compiled->schedule->id, 'from' => $previous, 'to' => $compiled->release->publisher]
		);
		$this->audit->applyRetentionExpiry(auditTrail: $entry, objectEntity: $compiled->release->application);
		$this->audit->insert($entry);
	}//end recordRunAs()

	/**
	 * An audit entry on the application, by the publisher, now.
	 *
	 * @param CompiledScheduleFlow $compiled The flow.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entryFor(CompiledScheduleFlow $compiled): AuditTrail {
		$application = $compiled->release->application;

		$entry = new AuditTrail();
		$entry->setUuid((string)Uuid::v4());
		$entry->setObject($application->getId());
		$entry->setObjectUuid($application->getUuid());
		$entry->setRegister((int)$application->getRegister());
		$entry->setSchema((int)$application->getSchema());
		$entry->setAction(self::ACTION);
		$entry->setUser($compiled->release->publisher);
		$entry->setCreated(new DateTime());

		return $entry;
	}//end entryFor()
}//end class
