<?php

/**
 * A reminder declared on a notification rule is checked at schema save: a date anchor, an offset, a bounded repeat
 * and a stop condition in the scheduled filter grammar.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationReminderValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationSchemaFacts;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationReminderValidator
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleContext
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationSchemaFacts
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser
 */
final class NotificationReminderValidatorTest extends TestCase {

	/**
	 * A schema with a date, a date-time and a string property, and one rule carrying this reminder.
	 *
	 * @param mixed $reminder The reminder block.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(mixed $reminder): array {
		return [
			'properties' => [
				'hearingDate' => ['type' => 'string', 'format' => 'date'],
				'receivedAt' => ['type' => 'string', 'format' => 'date-time'],
				'title' => ['type' => 'string'],
				'status' => ['type' => 'string'],
			],
			'x-openregister-notifications' => [
				'chase' => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'subject' => 'Hello',
					'recipients' => [['kind' => 'users', 'users' => ['admin']]],
					'reminder' => $reminder,
				],
			],
		];
	}//end schema()

	/**
	 * The errors for a reminder, through the annotation validator a schema save uses.
	 *
	 * @param mixed $reminder The reminder block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function errorsFor(mixed $reminder): array {
		return (new NotificationAnnotationValidator())->validate(schema: self::schema(reminder: $reminder));
	}//end errorsFor()

	/**
	 * The fields of the reminder errors.
	 *
	 * @param array<int, array<string, mixed>> $errors The errors.
	 *
	 * @return array<int, string>
	 */
	private static function fields(array $errors): array {
		return array_map(static fn (array $error): string => (string)($error['field'] ?? ''), $errors);
	}//end fields()

	/**
	 * The spec's reminder: three business days before the hearing, every seven days at most three times, until the
	 * object is closed.
	 *
	 * @return void
	 */
	public function testAFullReminderIsAccepted(): void {
		$this->assertSame(
			[],
			self::errorsFor(
				reminder: [
					'anchor' => 'hearingDate',
					'offset' => ['value' => -3, 'unit' => 'businessDays'],
					'repeat' => ['interval' => ['value' => 7, 'unit' => 'calendarDays'], 'max' => 3],
					'stopWhen' => ['status' => ['operator' => 'equals', 'value' => 'closed']],
				]
			)
		);
		$this->assertSame([], self::errorsFor(reminder: ['anchor' => 'receivedAt', 'offset' => ['value' => 4, 'unit' => 'hours']]));
	}//end testAFullReminderIsAccepted()

	/**
	 * An anchor that is not a date, or not a property at all, is refused naming the property.
	 *
	 * @return void
	 */
	public function testAnAnchorThatIsNotADatePropertyIsRefusedNamingIt(): void {
		$notADate = self::errorsFor(reminder: ['anchor' => 'title', 'offset' => ['value' => 1, 'unit' => 'calendarDays']]);
		$missing = self::errorsFor(reminder: ['anchor' => 'decisionDate', 'offset' => ['value' => 1, 'unit' => 'calendarDays']]);

		$this->assertSame(['reminder.anchor'], self::fields($notADate));
		$this->assertSame('notification-reminder-invalid', $notADate[0]['code']);
		$this->assertSame('Notification "chase": reminder.anchor "title" is not a date property.', $notADate[0]['message']);
		$this->assertSame('Notification "chase": reminder.anchor "decisionDate" is not a property of this schema.', $missing[0]['message']);
	}//end testAnAnchorThatIsNotADatePropertyIsRefusedNamingIt()

	/**
	 * A repeat interval without a maximum is refused: the reminder could never end.
	 *
	 * @return void
	 */
	public function testAnUnboundedRepeatIsRefused(): void {
		$errors = self::errorsFor(
			reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 0, 'unit' => 'hours'], 'repeat' => ['interval' => ['value' => 7, 'unit' => 'calendarDays']]]
		);

		$this->assertSame(['reminder.repeat.max'], self::fields($errors));
		$this->assertStringContainsString('must be able to end', $errors[0]['message']);
		$this->assertSame(
			['reminder.repeat.max'],
			self::fields(self::errorsFor(reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 0, 'unit' => 'hours'], 'repeat' => ['interval' => ['value' => 7, 'unit' => 'calendarDays'], 'max' => 0]]))
		);
	}//end testAnUnboundedRepeatIsRefused()

	/**
	 * Offsets and intervals take the timers' `{value, unit}` shape; an interval must move forward.
	 *
	 * @return void
	 */
	public function testAmountsTakeTheTimerShape(): void {
		$this->assertSame(['reminder.offset'], self::fields(self::errorsFor(reminder: ['anchor' => 'hearingDate'])));
		$this->assertSame(['reminder.offset'], self::fields(self::errorsFor(reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 3, 'unit' => 'weeks']])));
		$this->assertSame(
			['reminder.repeat.interval'],
			self::fields(self::errorsFor(reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 3, 'unit' => 'hours'], 'repeat' => ['interval' => ['value' => 0, 'unit' => 'hours'], 'max' => 2]]))
		);
	}//end testAmountsTakeTheTimerShape()

	/**
	 * A stop condition is parsed by the scheduled filter grammar and its errors are passed on.
	 *
	 * @return void
	 */
	public function testAStopConditionIsCheckedByTheFilterGrammar(): void {
		$errors = self::errorsFor(
			reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 1, 'unit' => 'hours'], 'stopWhen' => ['status' => ['operator' => 'nonsense']]]
		);

		$this->assertSame(['reminder.stopWhen.status.operator'], self::fields($errors));
		$this->assertSame('notification-scheduled-bad-filter-operator', $errors[0]['code']);
		$this->assertStringStartsWith('Notification "chase" reminder.stopWhen.status.operator must be one of', $errors[0]['message']);
		$this->assertSame(['reminder.stopWhen'], self::fields(self::errorsFor(reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => 1, 'unit' => 'hours'], 'stopWhen' => []])));
	}//end testAStopConditionIsCheckedByTheFilterGrammar()

	/**
	 * A rule without a reminder is untouched, and a reminder that is not an object is refused.
	 *
	 * @return void
	 */
	public function testNoReminderIsNoErrorAndAMalformedOneIsRefused(): void {
		$validator = new NotificationReminderValidator(filterParser: new ScheduledFilterParser());
		$facts = new NotificationSchemaFacts(schema: ['properties' => []]);

		$this->assertSame([], $validator->validate(rule: new NotificationRuleContext(name: 'r', spec: [], schema: $facts)));
		$this->assertSame(['reminder'], self::fields(self::errorsFor(reminder: 'soon')));
		$this->assertSame(['reminder'], self::fields(self::errorsFor(reminder: ['hearingDate'])));
	}//end testNoReminderIsNoErrorAndAMalformedOneIsRefused()
}//end class
