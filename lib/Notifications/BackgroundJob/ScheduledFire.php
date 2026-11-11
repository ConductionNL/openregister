<?php

/**
 * One firing of a scheduled notification rule on one schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;

/**
 * The rule being fired, the scan time, the object in hand and the per-fire counters.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFire {

	/**
	 * Schema id.
	 *
	 * @var integer
	 */
	public readonly int $schemaId;

	/**
	 * Object filter of the trigger.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $filter;

	/**
	 * Scan time as a mutable DateTime, for the dedupe rows.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $seenAt;

	/**
	 * Fields whose values make up an object's fingerprint.
	 *
	 * @var string[]
	 */
	public array $watchedFields = [];

	/**
	 * Uuid of the object in hand.
	 *
	 * @var string
	 */
	public string $objectUuid = '';

	/**
	 * Fingerprint of the object in hand.
	 *
	 * @var string
	 */
	public string $fingerprint = '';

	/**
	 * Objects that matched the filter.
	 *
	 * @var integer
	 */
	public int $matched = 0;

	/**
	 * Objects the notification was dispatched for.
	 *
	 * @var integer
	 */
	public int $dispatched = 0;

	/**
	 * Matching objects skipped because their fingerprint is unchanged.
	 *
	 * @var integer
	 */
	public int $deduplicated = 0;

	/**
	 * Constructor
	 *
	 * @param Schema               $schema  Schema the rule is declared on
	 * @param string               $name    Rule name
	 * @param array<string, mixed> $trigger The rule's scheduled trigger
	 * @param DateTimeImmutable    $nowDt   Scan time, in UTC
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $name,
		public readonly array $trigger,
		public readonly DateTimeImmutable $nowDt,
	) {
		$this->schemaId = (int)$schema->getId();
		$this->filter   = (array)($trigger['filter'] ?? []);
		$this->seenAt   = new DateTime($nowDt->format('Y-m-d H:i:s.u'), $nowDt->getTimezone());
	}//end __construct()

	/**
	 * App config key of the rotating window offset.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function offsetKey(): string {
		return 'sched_offset:'.$this->schemaId.':'.$this->name;
	}//end offsetKey()
}//end class
