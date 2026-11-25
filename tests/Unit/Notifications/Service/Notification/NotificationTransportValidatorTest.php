<?php

/**
 * NotificationTransportValidator: the faults a notification's transports list can carry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTransportValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationTransportValidator
 */
class NotificationTransportValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var NotificationTransportValidator
	 */
	private NotificationTransportValidator $validator;

	protected function setUp(): void {
		$this->validator = new NotificationTransportValidator();
	}

	public function testANonArrayTransportsValueIsOneFault(): void {
		$this->assertSame(
			[[
				'code' => 'notification-transports-malformed',
				'message' => 'Notification "welcome" transports must be an array.',
			]],
			$this->validator->validate('outbound', 'welcome')
		);
	}

	public function testAnEmptyListAndAValidTransportHaveNoFaults(): void {
		$this->assertSame([], $this->validator->validate([], 'welcome'));
		$this->assertSame([], $this->validator->validate([['kind' => 'outbound', 'handler' => 'mail']], 'welcome'));
	}

	public function testAnEntryThatIsNotAnObjectIsNamedByIndex(): void {
		$this->assertSame(
			[[
				'code' => 'notification-transport-malformed',
				'message' => 'Notification "welcome" transports[1] must be an object.',
			]],
			$this->validator->validate([['kind' => 'outbound', 'handler' => 'x'], 'oops'], 'welcome')
		);
	}

	public function testAnUnknownOrMissingKindIsRefusedWithTheAllowedKinds(): void {
		$faults = $this->validator->validate([['kind' => 'inbound'], ['handler' => 'x']], 'n');

		$this->assertSame(
			[
				['code' => 'notification-transport-bad-kind', 'message' => 'Notification "n" transports[0] kind "inbound" not in [outbound].'],
				['code' => 'notification-transport-bad-kind', 'message' => 'Notification "n" transports[1] kind "" not in [outbound].'],
			],
			$faults
		);
	}

	public function testAnOutboundTransportNeedsANonEmptyHandler(): void {
		$faults = $this->validator->validate(['a' => ['kind' => 'outbound'], 'b' => ['kind' => 'outbound', 'handler' => '']], 'n');

		$this->assertSame(
			[
				['code' => 'notification-transport-no-handler', 'message' => 'Notification "n" transports[a] kind=outbound requires a handler (DI tag or FQCN).'],
				['code' => 'notification-transport-no-handler', 'message' => 'Notification "n" transports[b] kind=outbound requires a handler (DI tag or FQCN).'],
			],
			$faults
		);
	}

	public function testFaultsAreReindexedAroundValidEntries(): void {
		$faults = $this->validator->validate(
			[['kind' => 'outbound', 'handler' => 'x'], 7, ['kind' => 'outbound', 'handler' => 'y']],
			'n'
		);

		$this->assertSame([0], array_keys($faults));
		$this->assertSame('notification-transport-malformed', $faults[0]['code']);
	}
}
