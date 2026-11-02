<?php

/**
 * OpenRegister ActionDateFollower
 *
 * Moves a record's archiefactiedatum to what its current source date gives,
 * and clears it, with an audit entry, when the source date is gone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Keeps a nominated record's archiefactiedatum in step with its source date.
 */
class ActionDateFollower {

	/**
	 * The audit action recorded when a destruction date is removed.
	 *
	 * @var string
	 */
	public const AUDIT_DATE_REMOVED = 'archival.destruction_date_removed';

	/**
	 * Constructor.
	 *
	 * @param ArchiveActionDateCalculator $calculator  Calculates the archiefactiedatum
	 * @param AuditTrailMapper            $auditMapper Records a removed date
	 * @param LoggerInterface             $logger      Logger
	 */
	public function __construct(
		private readonly ArchiveActionDateCalculator $calculator,
		private readonly AuditTrailMapper $auditMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recalculate a nominated record's archiefactiedatum from its source date.
	 *
	 * A record already transferred or destroyed, or one without a retention
	 * period, is left alone.
	 *
	 * @param ObjectEntity $object The nominated record
	 * @param Schema       $schema Its schema
	 *
	 * @return bool True when the archiefactiedatum changed
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function follow(ObjectEntity $object, Schema $schema): bool {
		$retention = ($object->getRetention() ?? []);
		if (in_array(($retention['archiefstatus'] ?? ''), RecordState::IMMUTABLE_ALIASES, true) === true) {
			return false;
		}

		$retentionPeriod = ($retention['bewaartermijn'] ?? null);
		if (is_string($retentionPeriod) === false || $retentionPeriod === '') {
			return false;
		}

		$oldDate = ($retention['archiefactiedatum'] ?? null);
		$newDate = $this->calculator->calculate(object: $object, schema: $schema, retentionPeriod: $retentionPeriod);
		if ($newDate === $oldDate) {
			return false;
		}

		if ($newDate === null) {
			$this->clear(object: $object, retention: $retention, oldDate: (string)$oldDate);
			return true;
		}

		$retention['archiefactiedatum'] = $newDate;
		$object->setRetention($retention);
		$this->logger->info(
			sprintf('[ActionDateFollower] Recalculated archiefactiedatum for object %s: %s -> %s', $object->getUuid(), $oldDate, $newDate)
		);

		return true;
	}//end follow()

	/**
	 * Remove the archiefactiedatum and say so in the audit trail.
	 *
	 * @param ObjectEntity         $object    The record
	 * @param array<string, mixed> $retention Its retention block
	 * @param string               $oldDate   The date being removed
	 *
	 * @return void
	 */
	private function clear(ObjectEntity $object, array $retention, string $oldDate): void {
		unset($retention['archiefactiedatum']);
		$object->setRetention($retention);

		$this->auditMapper->createAuditTrailEntry(
			object: $object,
			action: self::AUDIT_DATE_REMOVED,
			context: [
				'archiefactiedatum' => ['old' => $oldDate, 'new' => null],
				'reason' => 'The source date of the destruction date was cleared.',
			]
		);
		$this->logger->info(
			sprintf('[ActionDateFollower] Cleared archiefactiedatum for object %s (was %s)', $object->getUuid(), $oldDate)
		);
	}//end clear()
}//end class
