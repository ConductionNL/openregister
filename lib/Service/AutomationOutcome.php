<?php

/**
 * OpenRegister AutomationOutcome
 *
 * The consuming-app shape of one automation run record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRecorder;

/**
 * Projects an automation run record onto the outcome a consuming app reads.
 *
 * The record store is the one `automation-run-records` keeps; this is a view
 * over it, not a second store. The view answers both kinds in one shape:
 * `kind` (`notification` or `lifecycle`), `key`, `at`, the subject object,
 * `outcome` (`sent`, `done`, `failed`, plus the stored `partial` and
 * `skipped`), a short `reason`, and for a notification the number of
 * `recipients`. It never carries a message body, a recipient address or the
 * per-channel breakdown.
 *
 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
 */
final class AutomationOutcome {

	public const KIND_NOTIFICATION = 'notification';

	public const KIND_LIFECYCLE = 'lifecycle';

	public const OUTCOME_DONE = 'done';

	/**
	 * The longest reason answered; a longer one is cut, so a stack of handler
	 * text never travels to a consuming app.
	 */
	public const REASON_MAX_LENGTH = 200;

	/**
	 * The outcome shape of one record.
	 *
	 * @param AutomationRecord $record The stored record.
	 *
	 * @return array{kind: string, key: string, at: ?string, subject: array{register: ?string, schema: ?string, id: ?string}, outcome: string, reason: ?string, recipients?: int}
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md#requirement-a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did
	 */
	public static function fromRecord(AutomationRecord $record): array {
		$isNotification = ($record->getKind() === AutomationRecord::KIND_NOTIFICATION);
		$kind           = self::KIND_LIFECYCLE;
		if ($isNotification === true) {
			$kind = self::KIND_NOTIFICATION;
		}

		$outcome = [
			'kind'    => $kind,
			'key'     => (string)$record->getAutomationKey(),
			'at'      => $record->getRecordedAt()?->format(DateTime::ATOM),
			'subject' => [
				'register' => $record->getRegisterId(),
				'schema'   => $record->getSchemaId(),
				'id'       => $record->getObjectUuid(),
			],
			'outcome' => self::outcome(record: $record),
			'reason'  => self::reason(record: $record),
		];

		if ($isNotification === true) {
			$outcome['recipients'] = self::recipients(channels: $record->channelCounts());
		}

		return $outcome;
	}//end fromRecord()

	/**
	 * The outcome word: a lifecycle action that succeeded is `done`; every
	 * other stored word is answered as stored.
	 *
	 * @param AutomationRecord $record The stored record.
	 *
	 * @return string The outcome.
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
	 */
	private static function outcome(AutomationRecord $record): string {
		$stored = (string)$record->getOutcome();
		if ($record->getKind() === AutomationRecord::KIND_LIFECYCLE_ACTION && $stored === LifecycleActionRecorder::OUTCOME_SUCCEEDED) {
			return self::OUTCOME_DONE;
		}

		return $stored;
	}//end outcome()

	/**
	 * A short reason: the stored reason (a skip's gate), else the stored
	 * message (a failure's own text), cut to REASON_MAX_LENGTH; null when
	 * neither says anything.
	 *
	 * @param AutomationRecord $record The stored record.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
	 */
	private static function reason(AutomationRecord $record): ?string {
		$reason = trim((string)$record->getReason());
		if ($reason === '') {
			$reason = trim((string)$record->getMessage());
		}

		if ($reason === '') {
			return null;
		}

		return mb_substr($reason, 0, self::REASON_MAX_LENGTH);
	}//end reason()

	/**
	 * How many recipients a notification was addressed to: the largest count
	 * any one channel attempted (sent plus failed). A person reached on two
	 * channels counts once, and the counts held by a gate are not addressed.
	 *
	 * @param array<string, mixed> $channels The stored counts per channel.
	 *
	 * @return int The number of recipients.
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
	 */
	private static function recipients(array $channels): int {
		$recipients = 0;
		foreach ($channels as $counts) {
			if (is_array($counts) === false) {
				continue;
			}

			$attempted  = ((int)($counts['sent'] ?? 0) + (int)($counts['failed'] ?? 0));
			$recipients = max($recipients, $attempted);
		}

		return $recipients;
	}//end recipients()
}//end class
