<?php

/**
 * A removed or disabled owner's webhooks are disabled (webhooks-for-owners, D-6).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Listener\OwnedWebhooksEndWithOwnerListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Removal and disabling stop an owner's webhooks; nothing else does.
 */
#[CoversClass(OwnedWebhooksEndWithOwnerListener::class)]
class OwnedWebhooksEndWithOwnerListenerTest extends TestCase {

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testARemovedOwnersWebhooksAreDisabled(): void {
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->expects($this->once())->method('disableOwnedBy')->with('alice')->willReturn(2);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->stringContains('Disabled the webhooks'), ['count' => 2]);

		(new OwnedWebhooksEndWithOwnerListener(webhooks: $webhooks, logger: $logger))
			->handle(new BeforeUserDeletedEvent($this->user(uid: 'alice')));
	}//end testARemovedOwnersWebhooksAreDisabled()

	/**
	 * A disabling, in the value shapes core sends.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function disablings(): array {
		return ['bool' => [false], 'string' => ['false'], 'zero' => ['0']];
	}//end disablings()

	#[DataProvider('disablings')]
	public function testADisabledOwnersWebhooksAreDisabled(mixed $value): void {
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->expects($this->once())->method('disableOwnedBy')->with('bob')->willReturn(0);

		(new OwnedWebhooksEndWithOwnerListener(webhooks: $webhooks, logger: $this->createMock(LoggerInterface::class)))
			->handle(new UserChangedEvent($this->user(uid: 'bob'), 'enabled', $value, true));
	}//end testADisabledOwnersWebhooksAreDisabled()

	/**
	 * Events that leave the webhooks alone.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function otherChanges(): array {
		return [
			'enabled again' => ['enabled', true],
			'enabled again as text' => ['enabled', 'true'],
			'a new display name' => ['displayName', 'Bob'],
			'an email change to false-looking text' => ['eMailAddress', 'false'],
		];
	}//end otherChanges()

	#[DataProvider('otherChanges')]
	public function testOtherChangesLeaveTheWebhooksAlone(string $feature, mixed $value): void {
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->expects($this->never())->method('disableOwnedBy');

		(new OwnedWebhooksEndWithOwnerListener(webhooks: $webhooks, logger: $this->createMock(LoggerInterface::class)))
			->handle(new UserChangedEvent($this->user(uid: 'bob'), $feature, $value, null));
	}//end testOtherChangesLeaveTheWebhooksAlone()

	public function testOtherEventsAreIgnored(): void {
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->expects($this->never())->method('disableOwnedBy');

		(new OwnedWebhooksEndWithOwnerListener(webhooks: $webhooks, logger: $this->createMock(LoggerInterface::class)))
			->handle(new Event());
	}//end testOtherEventsAreIgnored()

	public function testAFailureIsLoggedAndDoesNotStopTheRemoval(): void {
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->method('disableOwnedBy')->willThrowException(new RuntimeException('table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not disable'), $this->callback(fn (array $context) => $context['error'] === 'table locked'));
		$logger->expects($this->never())->method('info');

		(new OwnedWebhooksEndWithOwnerListener(webhooks: $webhooks, logger: $logger))
			->handle(new BeforeUserDeletedEvent($this->user(uid: 'alice')));
	}//end testAFailureIsLoggedAndDoesNotStopTheRemoval()
}//end class
