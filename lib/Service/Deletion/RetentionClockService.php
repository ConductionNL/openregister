<?php

/**
 * RetentionClockService — two clocks, both visible, neither silent.
 *
 * The AVG says delete when the lawful purpose ends. The Archiefwet says keep
 * for N years. A product that merges them into one date is wrong in one
 * direction for every object it holds, so both sit on the object, each naming
 * the rule that produced it:
 *
 *  - the AVG date comes from the object's processing activity (its
 *    `retentionPeriod`, an ISO-8601 duration) counted from the last write,
 *    which is the same reading {@see \OCA\OpenRegister\Service\AvgRetentionService}
 *    already sweeps by: the bewaartermijn clock resets on every write;
 *  - the Archiefwet date is `retention.archiefactiedatum`, produced by
 *    {@see \OCA\OpenRegister\Service\RetentionService} from the selectielijst.
 *
 * Where the AVG date has passed and the archive date has not, nothing is
 * destroyed and the disagreement is reported for a person to decide. Silence
 * is the failure mode here, not the conflict.
 *
 * A legal hold outranks both, read through
 * {@see \OCA\OpenRegister\Db\ObjectEntity::hasActiveLegalHold()}, which is the
 * single definition of "held" in this codebase. A hold that meant one thing on
 * the archive clock and another on the AVG clock would not be a hold.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the AVG clock and the Archiefwet clock, and reports their
 * disagreement instead of resolving it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class RetentionClockService {
	/**
	 * The rule reported when no processing activity gives an AVG date.
	 *
	 * @var string
	 */
	public const RULE_NO_AVG = 'no processing activity with a retention period is linked to this object';

	/**
	 * The rule reported when no selectielijst gives an archive date.
	 *
	 * @var string
	 */
	public const RULE_NO_ARCHIVE = 'no archiefactiedatum has been calculated for this object';

	/**
	 * Wire the processing-activity catalogue.
	 *
	 * @param VerwerkingsactiviteitMapperInterface $activityMapper Resolves the object's processing activity.
	 * @param LoggerInterface             $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly VerwerkingsactiviteitMapperInterface $activityMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Both clocks and the hold, as the object publishes them.
	 *
	 * @param ObjectEntity           $object The object to read.
	 * @param DateTimeImmutable|null $now    Reference moment, now when omitted.
	 *
	 * @return array<string, mixed> The two clocks, the hold and any conflict.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function clocksFor(ObjectEntity $object, ?DateTimeImmutable $now = null): array {
		$moment = ($now ?? new DateTimeImmutable());
		$avg = $this->avgClock(object: $object);
		$archive = $this->archiveClock(object: $object);
		$held = $object->hasActiveLegalHold();

		$conflict = null;
		if ($held === false
			&& $avg['date'] !== null
			&& $archive['date'] !== null
			&& $avg['date'] < $archive['date']
			&& $avg['date'] <= $moment->format(DATE_ATOM)
		) {
			$conflict = [
				'code' => 'RETENTION_CLOCKS_DISAGREE',
				'message' => 'The lawful purpose of this object\'s personal data has ended while its archive '
					. 'retention has not. It is not destroyed, and a person decides which rule wins.',
				'avg' => $avg,
				'archive' => $archive,
			];
		}

		return [
			'avg' => $avg,
			'archive' => $archive,
			'legalHold' => $held,
			'conflict' => $conflict,
		];
	}//end clocksFor()

	/**
	 * The refusal a retention pass gets for this object, or null when it may
	 * proceed.
	 *
	 * A hold refuses first, so an object under hold is never reported as a
	 * clock conflict: the hold is the reason, and reporting two reasons for one
	 * refusal is how the wrong one gets acted on.
	 *
	 * @param ObjectEntity           $object The object being swept.
	 * @param DateTimeImmutable|null $now    Reference moment, now when omitted.
	 *
	 * @return DestructionRefusedException|null The refusal, or null when the clocks agree.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function refusalFor(ObjectEntity $object, ?DateTimeImmutable $now = null): ?DestructionRefusedException {
		$clocks = $this->clocksFor(object: $object, now: $now);

		if ($clocks['legalHold'] === true) {
			return new DestructionRefusedException(
				rule: 'legal-hold',
				reason: 'This object is under an active legal hold, which outranks both the AVG clock and the '
					. 'Archiefwet clock. Nothing is destroyed while the hold stands.',
				statusCode: 409,
				context: ['clocks' => $clocks]
			);
		}

		if ($clocks['conflict'] !== null) {
			return new DestructionRefusedException(
				rule: 'retention-clocks-disagree',
				reason: $clocks['conflict']['message'],
				statusCode: 409,
				context: ['clocks' => $clocks]
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * The AVG date and the rule that produced it.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{date: string|null, rule: string} The clock.
	 */
	private function avgClock(ObjectEntity $object): array {
		$activity = $this->retainingActivity(reference: ($object->getProcessingActivityId() ?? ''));
		if ($activity === null) {
			return self::noAvgClock();
		}

		$period = (string)($activity->getRetentionPeriod() ?? '');
		$due    = $this->dueAfterLastWrite(object: $object, period: $period);
		if ($due === null) {
			return self::noAvgClock();
		}

		return [
			'date' => $due->format(DATE_ATOM),
			'rule' => $this->avgRule(activity: $activity, period: $period),
		];
	}//end avgClock()

	/**
	 * The rule an AVG clock names: which processing activity keeps the data, and for how long.
	 *
	 * @param object $activity The retaining processing activity.
	 * @param string $period   Its retention period.
	 *
	 * @return string The rule.
	 */
	private function avgRule(object $activity, string $period): string {
		return 'verwerkingsactiviteit ' . (string)$activity->getUuid()
			. ' keeps this data for ' . $period . ' after the last processing';
	}//end avgRule()

	/**
	 * The processing activity that keeps the object's data for a stated period.
	 *
	 * @param string $reference The object's processing activity reference.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null when none is linked,
	 *                                    resolvable or keeping a period.
	 */
	private function retainingActivity(string $reference): ?Verwerkingsactiviteit {
		if ($reference === '') {
			return null;
		}

		$activity = $this->resolveActivity(reference: $reference);
		if ($activity === null || (string)($activity->getRetentionPeriod() ?? '') === '') {
			return null;
		}

		return $activity;
	}//end retainingActivity()

	/**
	 * Resolve a processing activity reference, logging a reference that cannot be resolved.
	 *
	 * @param string $reference The processing activity reference.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null when it cannot be resolved.
	 */
	private function resolveActivity(string $reference): ?Verwerkingsactiviteit {
		try {
			return $this->activityMapper->resolveReference($reference);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[RetentionClocks] Could not resolve the processing activity',
				context: ['reference' => $reference, 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end resolveActivity()

	/**
	 * The moment the retention period runs out, counted from the object's last write.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $period The ISO 8601 retention period.
	 *
	 * @return DateTimeImmutable|null The due date, or null when the object has no
	 *                                write date or the period cannot be parsed.
	 */
	private function dueAfterLastWrite(ObjectEntity $object, string $period): ?DateTimeImmutable {
		// The bewaartermijn clock resets on every write, which is how the AVG
		// sweep already reads it. Counting from creation would erase objects
		// that are still actively in use.
		$base = ($object->getUpdated() ?? $object->getCreated());
		if ($base === null) {
			return null;
		}

		try {
			return DateTimeImmutable::createFromInterface($base)->add(new DateInterval($period));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RetentionClocks] Unparseable retentionPeriod on a processing activity',
				context: ['reference' => ($object->getProcessingActivityId() ?? ''), 'retentionPeriod' => $period],
			);
		}

		return null;
	}//end dueAfterLastWrite()

	/**
	 * The AVG clock when no processing activity gives a date.
	 *
	 * @return array{date: null, rule: string} The clock.
	 */
	private static function noAvgClock(): array {
		return [
			'date' => null,
			'rule' => self::RULE_NO_AVG,
		];
	}//end noAvgClock()

	/**
	 * The Archiefwet date and the rule that produced it.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{date: string|null, rule: string} The clock.
	 */
	private function archiveClock(ObjectEntity $object): array {
		$retention = ($object->getRetention() ?? []);
		$actionDate = ($retention['archiefactiedatum'] ?? null);
		if (is_string($actionDate) === false || $actionDate === '') {
			return [
				'date' => null,
				'rule' => self::RULE_NO_ARCHIVE,
			];
		}

		try {
			$parsed = new DateTimeImmutable($actionDate);
		} catch (Throwable $e) {
			return [
				'date' => null,
				'rule' => self::RULE_NO_ARCHIVE,
			];
		}

		$source = (string)($retention['selectielijstBron'] ?? 'the schema archive configuration');
		$term = (string)($retention['bewaartermijn'] ?? 'an unstated term');

		return [
			'date' => $parsed->format(DATE_ATOM),
			'rule' => 'selectielijst ' . $source . ' keeps this record for ' . $term
				. ', giving an archiefactiedatum of ' . $parsed->format('Y-m-d'),
		];
	}//end archiveClock()
}//end class
