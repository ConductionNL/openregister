<?php

/**
 * Unit tests for the `{"watchers": true}` recipient block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-watchers/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * The block is validated when the schema is saved.
 *
 * A rule addressed to `"yes"` rather than `true` would pass silently and then
 * address nobody, every night, with nothing in any log to say so. Catching it
 * at save time is the whole point of the check.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator
 */
class NotificationAnnotationValidatorWatchersTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var NotificationAnnotationValidator
	 */
	private NotificationAnnotationValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new NotificationAnnotationValidator();
	}//end setUp()

	/**
	 * A schema carrying one rule with the given recipients block.
	 *
	 * @param array<int, mixed> $recipients The recipients declaration.
	 *
	 * @return array<string, mixed> The schema definition.
	 */
	private function schemaWith(array $recipients): array {
		return [
			'x-openregister-notifications' => [
				'statusChanged' => [
					'trigger' => ['type' => 'transition', 'action' => 'open'],
					'recipients' => $recipients,
					'channels' => ['nc-notification'],
					'subject' => 'The case moved on',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		];
	}//end schemaWith()

	/**
	 * The canonical spelling is accepted.
	 *
	 * @return void
	 */
	public function testWatchersTrueIsAccepted(): void {
		$errors = $this->validator->validate($this->schemaWith(recipients: [['watchers' => true]]));

		$this->assertSame(expected: [], actual: $errors);
	}//end testWatchersTrueIsAccepted()

	/**
	 * A string where a boolean was meant is refused.
	 *
	 * @return void
	 */
	public function testWatchersYesIsRejected(): void {
		$errors = $this->validator->validate($this->schemaWith(recipients: [['watchers' => 'yes']]));

		$this->assertContains(
			needle: 'notification-recipient-watchers-not-true',
			haystack: array_column($errors, 'code')
		);
	}//end testWatchersYesIsRejected()

	/**
	 * `false` is refused too: a rule that addresses nobody is a mistake, not a
	 * way of turning a rule off.
	 *
	 * @return void
	 */
	public function testWatchersFalseIsRejected(): void {
		$errors = $this->validator->validate($this->schemaWith(recipients: [['watchers' => false]]));

		$this->assertContains(
			needle: 'notification-recipient-watchers-not-true',
			haystack: array_column($errors, 'code')
		);
	}//end testWatchersFalseIsRejected()

	/**
	 * The block sits beside the other recipient kinds without disturbing them.
	 *
	 * @return void
	 */
	public function testWatchersCombinesWithOtherRecipientKinds(): void {
		$errors = $this->validator->validate(
			$this->schemaWith(recipients: [
				['kind' => 'users', 'users' => ['admin']],
				['watchers' => true],
			])
		);

		$this->assertSame(expected: [], actual: $errors);
	}//end testWatchersCombinesWithOtherRecipientKinds()

	/**
	 * An unknown kind is still refused: adding `watchers` widened the
	 * vocabulary by exactly one entry, not by everything.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsStillRejected(): void {
		$errors = $this->validator->validate($this->schemaWith(recipients: [['kind' => 'followers']]));

		$this->assertContains(
			needle: 'notification-bad-recipient-kind',
			haystack: array_column($errors, 'code')
		);
	}//end testAnUnknownKindIsStillRejected()
}//end class
