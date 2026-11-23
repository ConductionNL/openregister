<?php

/**
 * RemoteRecipientDenialInterface is bound to the permission evaluator and fails closed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RemoteRecipientDenialInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Object\PermissionExplanationInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler::remoteRecipientDenial
 */
final class RemoteRecipientDenialBindingTest extends TestCase {

	public function testThePortResolvesToThePermissionEvaluator(): void {
		$this->assertSame(PermissionHandler::class, CoreServiceBindings::BINDINGS[RemoteRecipientDenialInterface::class] ?? null);
		$this->assertTrue(is_subclass_of(PermissionExplanationInterface::class, RemoteRecipientDenialInterface::class));
		$this->assertTrue(is_subclass_of(PermissionHandler::class, RemoteRecipientDenialInterface::class));
	}

	public function testAnObjectTheEvaluatorCannotReadIsAnUnresolvableDenial(): void {
		$handler = PermissionHandlerFactory::create(
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			schemaMapper: $this->createMock(SchemaMapperInterface::class),
			objectEntityMapper: $this->createMock(MagicMapperInterface::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->createMock(ContainerInterface::class),
		);

		$denial = $handler->remoteRecipientDenial(
			schema: $this->createMock(SchemaInterface::class),
			object: $this->createMock(ObjectEntityInterface::class),
			principals: ['remote:*', 'remote:alice@other.example'],
			action: 'read'
		);

		$this->assertSame(
			['rule' => null, 'principal' => 'remote:*', 'action' => 'read', 'conditional' => false, 'unresolvable' => true],
			$denial
		);
	}
}//end class
