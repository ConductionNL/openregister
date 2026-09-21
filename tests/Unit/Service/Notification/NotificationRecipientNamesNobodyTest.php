<?php

declare(strict_types=1);

/**
 * A recipient that can never resolve is refused where somebody is looking.
 *
 * 🔴 THE DISTINCTION THIS FILE EXISTS FOR. `groups: []` names nobody
 * STRUCTURALLY: no instance state makes it match, so it is a stub or a typo and
 * belongs in a validation error at import time. A group that is DECLARED but
 * currently empty is a different thing entirely — declared groups ship empty on
 * purpose across this fleet, and refusing them would fail the import of every
 * correctly written annotation on a fresh install.
 *
 * That second case is recorded at dispatch instead, by RuleReachRecorder.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
 */

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the declaration-time refusal of a recipient naming nobody.
 */
class NotificationRecipientNamesNobodyTest extends TestCase {

	private NotificationAnnotationValidator $validator;

	/**
	 * Wire the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new NotificationAnnotationValidator();
	}//end setUp()

	/**
	 * The error codes a rule produces.
	 *
	 * @param array<string, mixed> $recipient The recipient to declare.
	 *
	 * @return array<int, string> The codes.
	 */
	private function codesFor(array $recipient): array {
		$schema = [
			'properties' => ['title' => ['type' => 'string']],
			'x-openregister-notifications' => [
				'termijn' => [
					'enabled' => true,
					'channels' => ['nc-notification'],
					'recipients' => [$recipient],
					'subject' => ['en' => 'Something happened'],
				],
			],
		];

		return array_column($this->validator->validate($schema), 'code');
	}//end codesFor()

	/**
	 * 🔴 AN EMPTY GROUPS LIST IS REFUSED. It can never resolve to anybody, so
	 * it is a stub, and leaving it produces a rule that runs nightly and does
	 * nothing.
	 *
	 * @return void
	 */
	public function testAGroupsRecipientNamingNoGroupsIsRefused(): void {
		$this->assertContains('notification-recipient-names-nobody', $this->codesFor(recipient: ['kind' => 'groups', 'groups' => []]));
		$this->assertContains('notification-recipient-names-nobody', $this->codesFor(recipient: ['kind' => 'groups']));
	}//end testAGroupsRecipientNamingNoGroupsIsRefused()

	/**
	 * The same for an empty users list.
	 *
	 * @return void
	 */
	public function testAUsersRecipientNamingNoUsersIsRefused(): void {
		$this->assertContains('notification-recipient-names-nobody', $this->codesFor(recipient: ['kind' => 'users', 'users' => []]));
	}//end testAUsersRecipientNamingNoUsersIsRefused()

	/**
	 * 🔴 A DECLARED GROUP IS ACCEPTED EVEN THOUGH IT MAY BE EMPTY TODAY. This
	 * is the assertion that stops the refusal becoming a blanket: declared
	 * groups ship empty across this fleet, and refusing them would fail the
	 * import of every correctly written annotation on a fresh install.
	 *
	 * @return void
	 */
	public function testADeclaredGroupIsAcceptedEvenIfItIsEmptyToday(): void {
		$this->assertNotContains(
			'notification-recipient-names-nobody',
			$this->codesFor(recipient: ['kind' => 'groups', 'groups' => ['docudesk-woo-officers']])
		);
	}//end testADeclaredGroupIsAcceptedEvenIfItIsEmptyToday()

	/**
	 * Other recipient kinds are untouched: an object-acl or a parties
	 * recipient names nobody by list and resolves at dispatch.
	 *
	 * @return void
	 */
	public function testOtherRecipientKindsAreNotRefusedForHavingNoList(): void {
		foreach ([['kind' => 'object-acl', 'permission' => 'manage'], ['kind' => 'watchers']] as $recipient) {
			$this->assertNotContains(
				'notification-recipient-names-nobody',
				$this->codesFor(recipient: $recipient),
				(string)$recipient['kind']
			);
		}
	}//end testOtherRecipientKindsAreNotRefusedForHavingNoList()

	/**
	 * The message says what to do rather than only what is wrong, and names the
	 * distinction so a reader does not "fix" a legitimately empty group.
	 *
	 * @return void
	 */
	public function testTheRefusalExplainsTheDistinction(): void {
		$schema = [
			'properties' => ['title' => ['type' => 'string']],
			'x-openregister-notifications' => [
				'termijn' => [
					'enabled' => true,
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'groups', 'groups' => []]],
					'subject' => ['en' => 'Something happened'],
				],
			],
		];

		$messages = array_column($this->validator->validate($schema), 'message');
		$joined = implode(' ', $messages);

		$this->assertStringContainsString('can never resolve', $joined);
		$this->assertStringContainsString('unstaffed group is fine', $joined);
	}//end testTheRefusalExplainsTheDistinction()
}//end class
