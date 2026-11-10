<?php

/**
 * Behaviour tests for NotificationPreferencesController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Notifications\Controller\NotificationPreferencesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationPreferencesControllerServices;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationPreferencesController::class)]
class NotificationPreferencesControllerBehaviourTest extends TestCase {

	private NotificationPreferencesInterface&MockObject $prefs;

	private bool $signedIn = true;

	protected function setUp(): void {
		$this->prefs = $this->createMock(NotificationPreferencesInterface::class);
	}

	private function controller(array $params): NotificationPreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		return new NotificationPreferencesController($request, new NotificationPreferencesControllerServices($this->prefs, $session));
	}

	public function testIndexAnonymousIs401(): void {
		$this->signedIn = false;
		$this->prefs->expects($this->never())->method('getEffectiveForUser');
		$this->assertSame(401, $this->controller([])->index()->getStatus());
	}

	public function testIndexWithScopeAsksForThatScope(): void {
		$this->prefs->expects($this->once())->method('getEffectiveForUser')->with('alice', ['register:1'])->willReturn([['schema' => 'case']]);
		$this->assertSame(['results' => [['schema' => 'case']], 'total' => 1], $this->controller(['scope' => 'register:1'])->index()->getData());
	}

	public function testIndexWithEmptyScopeAsksGlobal(): void {
		$this->prefs->expects($this->once())->method('getEffectiveForUser')->with('alice', [])->willReturn([]);
		$this->assertSame(['results' => [], 'total' => 0], $this->controller(['scope' => ''])->index()->getData());
	}

	public function testUpdateAnonymousIs401(): void {
		$this->signedIn = false;
		$this->prefs->expects($this->never())->method('setOverride');
		$response = $this->controller(['schema' => 'case', 'notification' => 'n'])->update();
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testUpdateWithoutNotificationIs422(): void {
		$this->prefs->expects($this->never())->method('setOverride');
		$this->assertSame(422, $this->controller(['schema' => 'case'])->update()->getStatus());
	}

	public function testUpdateStoresOverrideAndAnswersStoredValue(): void {
		$this->prefs->expects($this->once())->method('setOverride')->with('alice', 'case', 'n', ['enabled' => false], 's');
		$this->prefs->expects($this->once())->method('getOverride')->with('alice', 'case', 'n', 's')->willReturn(['enabled' => false]);
		$this->assertSame(
			['schema' => 'case', 'notification' => 'n', 'scope' => 's', 'override' => ['enabled' => false]],
			$this->controller(['schema' => 'case', 'notification' => 'n', 'scope' => 's', 'enabled' => false])->update()->getData()
		);
	}

	public function testUpdateResetDoesNotReadBack(): void {
		$this->prefs->expects($this->once())->method('setOverride')->with('alice', 'case', 'n', null, null);
		$this->prefs->expects($this->never())->method('getOverride');
		$this->assertSame(
			['schema' => 'case', 'notification' => 'n', 'scope' => 'global', 'override' => null],
			$this->controller(['schema' => 'case', 'notification' => 'n', 'reset' => true])->update()->getData()
		);
	}
}
