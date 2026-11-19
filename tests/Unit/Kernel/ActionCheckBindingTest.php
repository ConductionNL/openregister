<?php

/**
 * ActionCheckInterface is bound to the action matrix.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ActionCheckInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Core asks the action-check port; the Platform action matrix answers it.
 */
final class ActionCheckBindingTest extends TestCase {

	/**
	 * A user in the `editors` group.
	 *
	 * @var IUser
	 */
	private IUser $piet;

	/**
	 * A user in no granted group.
	 *
	 * @var IUser
	 */
	private IUser $klaas;

	public function testThePortIsBoundToTheActionMatrix(): void {
		$context = new RecordingRegistrationContext();
		PlatformServiceRegistrar::register(context: $context);

		$this->assertContains(['alias' => ActionCheckInterface::class, 'target' => OpenRegisterActionAuthService::class], $context->aliases);
	}

	public function testTheActionMatrixIsThePort(): void {
		$this->assertTrue(is_subclass_of(OpenRegisterActionAuthService::class, ActionCheckInterface::class));
	}

	public function testThePortAnswersAsTheMatrixDoes(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{"webhook.own":["editors"]}');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('getUserGroupIds')->willReturnMap([[$this->piet, ['editors']], [$this->klaas, ['others']]]);

		$port = new OpenRegisterActionAuthService(appConfig: $config, groupManager: $groups);

		$this->assertTrue($port->can(user: $this->piet, action: 'webhook.own'));
		$this->assertFalse($port->can(user: $this->klaas, action: 'webhook.own'));
		$this->assertFalse($port->can(user: $this->piet, action: 'flow.manage'));
	}

	protected function setUp(): void {
		$this->piet  = $this->user(uid: 'piet');
		$this->klaas = $this->user(uid: 'klaas');
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}//end class
