<?php

/**
 * IntegrationsMapperRegistrar registers the hand-built WebhookMapper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\IntegrationsMapperRegistrar;
use OCA\OpenRegister\Db\MapperRegistrar;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\IntegrationsMapperRegistrar
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 * @uses   \OCA\OpenRegister\Db\MapperRegistrar
 * @uses   \OCA\OpenRegister\Db\MapperWiring
 */
final class IntegrationsMapperRegistrarWebhookTest extends TestCase {

	/**
	 * The Integrations registrar registers the WebhookMapper factory, and the Core one no longer does.
	 *
	 * @return void
	 */
	public function testTheWebhookMapperIsRegisteredByItsOwnArea(): void {
		$integrations = new RecordingRegistrationContext();
		IntegrationsMapperRegistrar::register(context: $integrations);
		$core = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $core);

		$this->assertSame(expected: [WebhookMapper::class], actual: $integrations->services);
		$this->assertNotContains(needle: WebhookMapper::class, haystack: $core->services);
	}//end testTheWebhookMapperIsRegisteredByItsOwnArea()

	/**
	 * The factory builds a WebhookMapper and asks for the same services, in the same order, as before the move.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsTheMapperFromTheSameServices(): void {
		$context = new RecordingRegistrationContext();
		IntegrationsMapperRegistrar::register(context: $context);

		$asked = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked) {
				$asked[] = $id;
				return $this->createMock(originalClassName: $id);
			}
		);

		$this->assertInstanceOf(expected: WebhookMapper::class, actual: $context->factories[WebhookMapper::class]($container));
		$this->assertSame(
			expected: [
				'OCP\IDBConnection',
				'OCA\OpenRegister\Db\OrganisationMapperInterface',
				'OCP\IUserSession',
				'OCP\IGroupManager',
				'OCP\IAppConfig',
				'OCP\Security\ICrypto',
				'Psr\Log\LoggerInterface',
			],
			actual: $asked
		);
	}//end testTheFactoryBuildsTheMapperFromTheSameServices()
}//end class
