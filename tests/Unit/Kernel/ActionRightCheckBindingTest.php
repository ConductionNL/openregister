<?php

/**
 * ActionRightCheckInterface is bound to Open Register's action matrix.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ActionRightCheckInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * A guarded act asks the Core port; the Platform action matrix answers it.
 */
final class ActionRightCheckBindingTest extends TestCase {

	public function testThePortResolvesToTheOpenRegisterActionMatrix(): void {
		$context = new RecordingRegistrationContext();
		PlatformServiceRegistrar::register(context: $context);

		$this->assertContains(['alias' => ActionRightCheckInterface::class, 'target' => OpenRegisterActionAuthService::class], $context->aliases);
		$this->assertTrue(is_subclass_of(OpenRegisterActionAuthService::class, ActionRightCheckInterface::class));
	}

	public function testThePortRefusesAUserWithoutTheRightAsTheMatrixDoes(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"object.correct":["admin"]}');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');

		$port = new OpenRegisterActionAuthService($appConfig, $groups);

		$this->expectException(OCSForbiddenException::class);
		$port->requireAction(user: $user, action: 'object.correct');
	}

	public function testThePortLetsAnAdministratorThrough(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$port = new OpenRegisterActionAuthService($this->createMock(IAppConfig::class), $groups);
		$port->requireAction(user: $user, action: 'object.correct');

		$this->addToAssertionCount(1);
	}
}//end class
