<?php

/**
 * The action-right check and the lifecycle final-state ports are bound to the classes that answer them.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleFinalStatesInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ActionRightCheckInterface;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Flows ask the Core action-right port; archival asks the Compliance final-state port.
 */
final class HigherAreaPortsBindingTest extends TestCase {

	public function testTheActionRightCheckResolvesToTheOpenRegisterMatrix(): void {
		$context = new RecordingRegistrationContext();
		PlatformServiceRegistrar::register(context: $context);

		$this->assertContains(['alias' => ActionRightCheckInterface::class, 'target' => OpenRegisterActionAuthService::class], $context->aliases);
		$this->assertTrue(is_subclass_of(OpenRegisterActionAuthService::class, ActionRightCheckInterface::class));
	}

	public function testTheFinalStatePortResolvesToTheLifecycleResolver(): void {
		$this->assertSame(LifecycleFinalStateResolver::class, FlowWiring::BINDINGS[LifecycleFinalStatesInterface::class] ?? null);
		$this->assertTrue(is_subclass_of(LifecycleFinalStateResolver::class, LifecycleFinalStatesInterface::class));
	}

	public function testAFlowRightIsAskedOfThePort(): void {
		$user = $this->createMock(IUser::class);
		$rights = $this->createMock(ActionRightCheckInterface::class);
		$rights->expects($this->exactly(2))->method('can')
			->willReturnCallback(static fn (IUser $who, string $action): bool => ($who === $user && $action === 'flow.run'));

		$access = new FlowAccess(
			caller: new CallerContext(userSession: $this->createMock(IUserSession::class), groupManager: $this->createMock(IGroupManager::class)),
			actionAuth: $rights,
		);

		$this->assertTrue($access->may(user: $user, action: 'flow.run'));
		$this->assertFalse($access->may(user: $user, action: 'flow.edit'));
	}
}//end class
