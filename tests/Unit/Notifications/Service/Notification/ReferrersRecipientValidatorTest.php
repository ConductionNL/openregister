<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A `referrers` recipient: accepted when well-formed, each refusal naming its field,
 * and a nested `referrers` block refused naming `recipients`.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationSchemaFacts;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientEntry;
use OCA\OpenRegister\Notifications\Service\Notification\ReferrersRecipientValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferrersRecipientValidator::class)]
#[CoversClass(NotificationRecipientValidator::class)]
#[UsesClass(NotificationRuleContext::class)]
#[UsesClass(NotificationSchemaFacts::class)]
#[UsesClass(RecipientEntry::class)]
final class ReferrersRecipientValidatorTest extends TestCase {

	/**
	 * The errors for one recipient on a moduleVersion schema with a `module` relation.
	 *
	 * @param array<string, mixed> $recipient The recipient entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function errorsFor(array $recipient): array {
		$schema = new NotificationSchemaFacts(['properties' => ['module' => ['type' => 'string'], 'version' => ['type' => 'string']]]);
		$rule   = new NotificationRuleContext(name: 'module-version-published', spec: ['recipients' => [$recipient]], schema: $schema);

		return (new NotificationRecipientValidator())->validate($rule);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid(): array {
		return [
			'kind' => 'referrers',
			'of' => 'module',
			'register' => 'softwarecatalogus',
			'schema' => 'usage',
			'property' => 'module',
			'recipients' => [['kind' => 'object-acl', 'permission' => 'read'], ['kind' => 'field', 'field' => 'contact']],
		];
	}

	public function testAWellFormedBlockIsAccepted(): void {
		$this->assertSame([], $this->errorsFor($this->valid()));

		$withoutOf = $this->valid();
		unset($withoutOf['of']);
		$this->assertSame([], $this->errorsFor($withoutOf));
	}

	public function testEachMissingLocationFieldIsNamed(): void {
		foreach (['register', 'schema', 'property'] as $key) {
			$entry = $this->valid();
			$entry[$key] = '';

			$errors = $this->errorsFor($entry);

			$this->assertSame(['notification-recipient-referrers-missing'], array_column($errors, 'code'), $key);
			$this->assertStringContainsString('non-empty '.$key, $errors[0]['message']);
			$this->assertStringStartsWith('Notification "module-version-published" recipient[0] ', $errors[0]['message']);
		}
	}

	public function testAnOfThatIsNotOnTheSchemaIsNamed(): void {
		$entry = $this->valid();
		$entry['of'] = 'supplier';

		$errors = $this->errorsFor($entry);

		$this->assertSame(['notification-recipient-referrers-bad-of'], array_column($errors, 'code'));
		$this->assertStringContainsString('of "supplier"', $errors[0]['message']);
	}

	public function testANestedReferrersBlockIsRefusedNamingRecipients(): void {
		$entry = $this->valid();
		$entry['recipients'] = [['kind' => 'object-acl', 'permission' => 'read'], $this->valid()];

		$errors = $this->errorsFor($entry);

		$this->assertSame(['notification-recipient-referrers-nested'], array_column($errors, 'code'));
		$this->assertStringContainsString('recipients[1]', $errors[0]['message']);
	}

	public function testEmptyOrBadNestedRecipientsAreRefused(): void {
		$entry = $this->valid();
		$entry['recipients'] = [];
		$this->assertSame(['notification-recipient-referrers-no-recipients'], array_column($this->errorsFor($entry), 'code'));

		$entry['recipients'] = [['kind' => 'object-acl', 'permission' => 'write'], ['kind' => 'field'], ['kind' => 'nobody']];
		$this->assertSame(
			['notification-recipient-acl-bad-permission', 'notification-recipient-field-unknown', 'notification-bad-recipient-kind'],
			array_column($this->errorsFor($entry), 'code')
		);
	}
}//end class
