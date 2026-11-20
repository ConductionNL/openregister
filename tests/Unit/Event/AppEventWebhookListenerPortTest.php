<?php

/**
 * The webhook-delivery port AppWebhookEvents subscribes to app-declared events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Kernel\Contracts\AppEventWebhookListenerInterface;
use OCA\OpenRegister\Listener\Registrar\FlowsListenerRegistrar;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the webhook listener implements the Kernel port and that the
 * Processes registrar binds the port to it, and only it.
 *
 * @covers \OCA\OpenRegister\Listener\Registrar\FlowsListenerRegistrar
 */
final class AppEventWebhookListenerPortTest extends TestCase {

	/**
	 * The port is an event listener and the webhook listener implements it.
	 *
	 * @return void
	 */
	public function testTheWebhookListenerImplementsThePort(): void {
		self::assertTrue(is_subclass_of(AppEventWebhookListenerInterface::class, IEventListener::class));
		self::assertTrue(is_subclass_of(WebhookEventListener::class, AppEventWebhookListenerInterface::class));
	}//end testTheWebhookListenerImplementsThePort()

	/**
	 * The Processes registrar aliases the port to the webhook listener, once.
	 *
	 * @return void
	 */
	public function testTheProcessesRegistrarBindsThePort(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);

		FlowsListenerRegistrar::register(context: $context);

		self::assertSame([[AppEventWebhookListenerInterface::class, WebhookEventListener::class]], $aliases);
	}//end testTheProcessesRegistrarBindsThePort()
}//end class
