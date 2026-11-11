<?php

/**
 * The quiet-hours and digest-schedule hold on a recipient delivery.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Decides whether a delivery waits (quiet hours, a digest schedule, or both)
 * and keeps the held row; moved out of the dispatcher.
 *
 * Without a window service no recipient is inside quiet hours; without a
 * digest evaluator no rule declares a digest; without a queue mapper a held
 * delivery is not persisted; without a time factory the wall clock is used.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeliveryHold {
	/**
	 * The hold reason per (inside quiet hours, digest declared) pair, each as 0 or 1.
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private const REASONS = [
		0 => [
			0 => null,
			1 => QueuedNotification::REASON_DIGEST_SCHEDULE,
		],
		1 => [
			0 => QueuedNotification::REASON_QUIET_HOURS,
			1 => QueuedNotification::REASON_BOTH,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param NotificationDeliveryWindowService|null $windowService   Override-only quiet-hours resolver + evaluator.
	 * @param DigestScheduleEvaluator|null           $digestEvaluator Live evaluator for the `digest` fixed-time schedule.
	 * @param QueuedNotificationMapper|null          $queue           Durable queue mapper for held deliveries.
	 * @param ITimeFactory|null                      $timeFactory     Time source (tests inject a controllable clock).
	 */
	public function __construct(
		private readonly ?NotificationDeliveryWindowService $windowService = null,
		private readonly ?DigestScheduleEvaluator $digestEvaluator = null,
		private readonly ?QueuedNotificationMapper $queue = null,
		private readonly ?ITimeFactory $timeFactory = null,
	) {
	}//end __construct()

	/**
	 * Whether the recipient's delivery of this rule is held now, and why.
	 *
	 * @param RuleFiring $firing The rule (its `digest` declaration).
	 * @param string     $uid    The recipient.
	 *
	 * @return HoldDecision|null The hold, or null when it may go out now.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function decide(RuleFiring $firing, string $uid): ?HoldDecision {
		$now    = $this->now();
		$reason = $this->reason(
			windowActive: $this->insideQuietHours(uid: $uid, now: $now),
			digestDeclared: $this->digestDeclared(spec: $firing->spec)
		);
		if ($reason === null) {
			return null;
		}

		return new HoldDecision(reason: $reason, at: $now);
	}//end decide()

	/**
	 * Whether a held delivery is persisted (a queue mapper is present).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function keepsRows(): bool {
		return $this->queue !== null;
	}//end keepsRows()

	/**
	 * Persist a held delivery's row. Throws what the mapper throws.
	 *
	 * @param QueuedNotification $row The row, with reason and payload set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function keep(QueuedNotification $row): void {
		$this->queue?->insert(entity: $row);
	}//end keep()

	/**
	 * "Now": the injected time factory when present, else the wall clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		if ($this->timeFactory !== null) {
			return DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime());
		}

		return new DateTimeImmutable('now');
	}//end now()

	/**
	 * Why a recipient's delivery is held: quiet hours, a digest schedule, both,
	 * or null when it may go out now.
	 *
	 * @param bool $windowActive   Whether the recipient is inside quiet hours.
	 * @param bool $digestDeclared Whether the rule declares a digest schedule.
	 *
	 * @return string|null A QueuedNotification reason, or null.
	 */
	private function reason(bool $windowActive, bool $digestDeclared): ?string {
		return self::REASONS[(int)$windowActive][(int)$digestDeclared];
	}//end reason()

	/**
	 * Whether the recipient is inside their quiet hours now.
	 *
	 * @param string            $uid The recipient.
	 * @param DateTimeImmutable $now The current time.
	 *
	 * @return bool
	 */
	private function insideQuietHours(string $uid, DateTimeImmutable $now): bool {
		if ($this->windowService === null) {
			return false;
		}

		$window = $this->windowService->getForUser(userId: $uid);
		if ($window === null) {
			return false;
		}

		return $this->windowService->isInsideWindow(window: $window, now: $now);
	}//end insideQuietHours()

	/**
	 * Whether the rule declares a valid digest schedule.
	 *
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return bool
	 */
	private function digestDeclared(array $spec): bool {
		$digestSpec = ($spec['digest'] ?? null);

		return is_array($digestSpec) === true
			&& $this->digestEvaluator !== null
			&& $this->digestEvaluator->isValidDigestSpec($digestSpec) === true;
	}//end digestDeclared()
}//end class
