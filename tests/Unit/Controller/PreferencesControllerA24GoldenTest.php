<?php

/**
 * Golden outcomes of the per-user preferences API.
 *
 * Pins the allowed and refused outcomes of PreferencesController: anonymous
 * calls are refused, keys are confined to the `pref_` namespace of the app,
 * and an empty value deletes the stored one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\PreferencesController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\PreferencesControllerServices;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden allowed/refused outcomes of PreferencesController.
 */
class PreferencesControllerA24GoldenTest extends TestCase {
	/** @var PreferencesController */
	private PreferencesController $controller;

	/** @var IConfig&MockObject */
	private IConfig&MockObject $config;

	/** @var IUserSession&MockObject */
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new PreferencesController(
			$this->createMock(IRequest::class),
			new PreferencesControllerServices(
				config: $this->config,
				session: new ControllerSession(
					userSession: $this->userSession,
					groupManager: $this->createMock(IGroupManager::class),
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);
	}

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function assertOutcome(int $status, array $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	private function untouchedConfig(): void {
		$this->config->expects($this->never())->method('getUserValue');
		$this->config->expects($this->never())->method('setUserValue');
		$this->config->expects($this->never())->method('deleteUserValue');
	}

	public function testAnonymousCallsAreRefusedWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->untouchedConfig();

		$this->assertOutcome(401, ['message' => 'Not logged in'], $this->controller->getPreference('seen'));
		$this->assertOutcome(401, ['message' => 'Not logged in'], $this->controller->setPreference('seen', 'x'));
		$this->assertOutcome(401, ['message' => 'Not logged in'], $this->controller->setPreference('!!!', ''));
	}

	public function testKeysWithNothingSafeLeftAreRefusedWith400(): void {
		$this->signedIn();
		$this->untouchedConfig();

		$this->assertOutcome(400, ['message' => 'Invalid key'], $this->controller->getPreference('_/.$'));
		$this->assertOutcome(400, ['message' => 'Invalid key'], $this->controller->setPreference('', 'x'));
	}

	public function testReadIsConfinedToThePrefNamespace(): void {
		$this->signedIn();
		$this->config->expects($this->exactly(2))->method('getUserValue')
			->with('alice', 'openregister', 'pref_support-seen', '')
			->willReturnOnConsecutiveCalls('yes', '');

		$this->assertOutcome(200, ['value' => 'yes'], $this->controller->getPreference('Support-Seen!'));
		$this->assertOutcome(200, ['value' => null], $this->controller->getPreference('support-seen'));
	}

	public function testLongKeysAreTruncatedTo64Characters(): void {
		$this->signedIn();
		$this->config->expects($this->once())->method('setUserValue')
			->with('alice', 'openregister', 'pref_' . str_repeat('a', 64), 'v');

		$this->assertOutcome(200, ['value' => 'v'], $this->controller->setPreference(str_repeat('a', 70), 'v'));
	}

	public function testEmptyValueDeletes(): void {
		$this->signedIn();
		$this->config->expects($this->once())->method('deleteUserValue')->with('alice', 'openregister', 'pref_seen');
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertOutcome(200, ['value' => null], $this->controller->setPreference('seen'));
	}
}//end class
