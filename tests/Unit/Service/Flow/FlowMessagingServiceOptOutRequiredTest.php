<?php

/**
 * FlowMessagingService is never built without an opt-out authority.
 *
 * The container always resolves the bound OptOutAuthorityInterface into
 * FlowMessagingOverrides. A service built by hand without one could not ask
 * integriq whether an external address may be mailed, so it is refused at
 * construction instead of building an authority of its own.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use LogicException;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicyInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplatingInterface;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthorityInterface;
use OCA\OpenRegister\Notifications\Service\Notification\RateLimiterInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSenderInterface;
use OCA\OpenRegister\Service\Flow\FlowMessagingChannels;
use OCA\OpenRegister\Service\Flow\FlowMessagingOverrides;
use OCA\OpenRegister\Service\Flow\FlowMessagingPlatform;
use OCA\OpenRegister\Service\Flow\FlowMessagingRecipients;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowMessagingService
 */
final class FlowMessagingServiceOptOutRequiredTest extends TestCase {

	/**
	 * Without an authority the service is refused.
	 *
	 * @return void
	 */
	public function testAServiceWithoutAnOptOutAuthorityIsRefused(): void {
		$this->expectException(LogicException::class);
		$this->service(overrides: new FlowMessagingOverrides());
	}//end testAServiceWithoutAnOptOutAuthorityIsRefused()

	/**
	 * With one it is built.
	 *
	 * @return void
	 */
	public function testAServiceWithAnOptOutAuthorityIsBuilt(): void {
		$service = $this->service(overrides: new FlowMessagingOverrides(optOut: $this->createMock(OptOutAuthorityInterface::class)));

		$this->assertInstanceOf(FlowMessagingService::class, $service);
	}//end testAServiceWithAnOptOutAuthorityIsBuilt()

	/**
	 * The service over doubles, with the given overrides.
	 *
	 * @param FlowMessagingOverrides $overrides The overrides.
	 *
	 * @return FlowMessagingService
	 */
	private function service(FlowMessagingOverrides $overrides): FlowMessagingService {
		return new FlowMessagingService(
			new FlowMessagingChannels(
				$this->createMock(NotificationChannelPolicyInterface::class),
				$this->createMock(NcNotificationSenderInterface::class),
				$this->createMock(EmailSenderInterface::class),
				$this->createMock(TalkSenderInterface::class)
			),
			new FlowMessagingRecipients(
				$this->createMock(NotificationRecipientResolverInterface::class),
				$this->createMock(NotificationPreferenceServiceInterface::class),
				$this->createMock(RateLimiterInterface::class),
				$this->createMock(IUserManager::class)
			),
			new FlowMessagingPlatform(
				$this->createMock(NotificationTemplatingInterface::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(IEventDispatcher::class)
			),
			$overrides
		);
	}//end service()
}//end class
