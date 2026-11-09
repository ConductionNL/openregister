<?php

/**
 * Behaviour tests for NotificationTemplatesController.
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

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Notifications\Controller\NotificationTemplatesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationTemplatesControllerServices;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(NotificationTemplatesController::class)]
class NotificationTemplatesControllerBehaviourTest extends TestCase {

	private NotificationTemplatesInterface&MockObject $registry;

	private IGroupManager&MockObject $groups;

	private bool $signedIn = true;

	protected function setUp(): void {
		$this->registry = $this->createMock(NotificationTemplatesInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
	}

	private function controller(array $params = []): NotificationTemplatesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('root');
		}

		$session->method('getUser')->willReturn($user);
		return new NotificationTemplatesController(
			$request,
			new NotificationTemplatesControllerServices($this->registry, new ControllerSession($session, $this->groups, $this->createMock(LoggerInterface::class)))
		);
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function actions(): array {
		return ['index' => ['index', []], 'gaps' => ['gaps', []], 'update' => ['update', ['object.created']]];
	}

	#[DataProvider('actions')]
	public function testAnonymousCallerIs401(string $action, array $args): void {
		$this->signedIn = false;
		$this->registry->expects($this->never())->method($this->anything());
		$response = $this->controller()->$action(...$args);
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testIndexCarriesTheGapsWithTheListing(): void {
		$this->registry->method('listAll')->willReturn([['event' => 'a'], ['event' => 'b']]);
		$this->registry->method('gaps')->willReturn(['c']);
		$this->assertSame(
			['results' => [['event' => 'a'], ['event' => 'b']], 'total' => 2, 'gaps' => ['c']],
			$this->controller()->index()->getData()
		);
	}

	public function testGapsAnswersCountedList(): void {
		$this->registry->method('gaps')->willReturn(['c', 'd']);
		$this->assertSame(['results' => ['c', 'd'], 'total' => 2], $this->controller()->gaps()->getData());
	}

	public function testUpdateByNonAdminIs403(): void {
		$this->groups->expects($this->once())->method('isAdmin')->with('root')->willReturn(false);
		$this->registry->expects($this->never())->method('edit');
		$response = $this->controller(['template' => ['subject' => 'x']])->update('object.created');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Administrator required'], $response->getData());
	}

	public function testUpdateWithoutTemplateObjectIs422(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->registry->expects($this->never())->method('edit');
		$response = $this->controller(['template' => 'not-an-object'])->update('object.created');
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'A "template" object, or "reset": true, is required'], $response->getData());
	}

	public function testUpdateRejectedByRegistryIs422WithItsMessage(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->registry->method('edit')->willThrowException(new InvalidArgumentException('unknown event'));
		$this->registry->expects($this->never())->method('templateFor');
		$response = $this->controller(['template' => ['subject' => 'x']])->update('bad.event');
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'unknown event'], $response->getData());
	}

	public function testUpdateStoresTemplateAndAnswersWhatIsStored(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->registry->expects($this->once())->method('edit')->with('object.created', ['subject' => 'x']);
		$this->registry->method('templateFor')->with('object.created')->willReturn(['subject' => 'x', 'body' => 'b']);
		$this->registry->method('hasEdit')->with('object.created')->willReturn(true);
		$response = $this->controller(['template' => ['subject' => 'x']])->update('object.created');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['event' => 'object.created', 'template' => ['subject' => 'x', 'body' => 'b'], 'edited' => true],
			$response->getData()
		);
	}

	public function testUpdateWithResetClearsTheEdit(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->registry->expects($this->once())->method('edit')->with('object.created', null);
		$this->registry->method('hasEdit')->willReturn(false);
		$response = $this->controller(['reset' => true, 'template' => 'ignored'])->update('object.created');
		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['edited']);
	}
}
