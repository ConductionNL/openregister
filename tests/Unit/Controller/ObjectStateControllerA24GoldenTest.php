<?php

/**
 * Golden outcomes of the archive and freeze routes.
 *
 * Pins, before a restructure, that every verb refuses an anonymous caller
 * before the handler, and the exact status and body of each refusal the
 * handler raises and of an allowed call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectStateController;
use OCA\OpenRegister\Controller\Support\ObjectStateControllerServices;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\ObjectStateRequest;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectStateController
 */
final class ObjectStateControllerA24GoldenTest extends TestCase {

	/**
	 * The four controller verbs and the handler method each one calls.
	 *
	 * @var array<string, string>
	 */
	private const VERBS = ['archive' => 'archiveWith', 'unarchive' => 'unarchiveWith', 'freeze' => 'freeze', 'unfreeze' => 'unfreezeWith'];

	/**
	 * The handler double.
	 *
	 * @var ArchiveHandler&MockObject
	 */
	private ArchiveHandler&MockObject $handler;

	/**
	 * Build the controller.
	 *
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $params   The request parameters.
	 *
	 * @return ObjectStateController The controller.
	 */
	private function controller(bool $signedIn, array $params = []): ObjectStateController {
		$this->handler = $this->createMock(ArchiveHandler::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		return new ObjectStateController($request, new ObjectStateControllerServices($this->handler, $session));
	}//end controller()

	/**
	 * An anonymous caller is refused 401 by every verb and the handler is never asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedByEveryVerb(): void {
		foreach (self::VERBS as $verb => $method) {
			$controller = $this->controller(signedIn: false);
			$this->handler->expects($this->never())->method($method);
			$response = $controller->$verb('1', '2', 'o');
			$this->assertSame([401, ['error' => 'Not authenticated']], [$response->getStatus(), $response->getData()]);
		}
	}//end testAnAnonymousCallerIsRefusedByEveryVerb()

	/**
	 * Each handler refusal keeps its status on every verb, and an outage leaks nothing.
	 *
	 * @return void
	 */
	public function testEveryRefusalOnEveryVerb(): void {
		$cases = [
			[new ArchiveNotOfferedException('not offered'), 422, ['error' => 'not offered']],
			[new NotAuthorizedException('no update'), 403, ['error' => 'no update']],
			[new DoesNotExistException('gone'), 404, ['error' => 'Object not found']],
			[new \RuntimeException('secret detail'), 500, ['error' => 'Internal server error']],
			[new \Error('engine'), 500, ['error' => 'Internal server error']],
		];
		foreach (self::VERBS as $verb => $method) {
			foreach ($cases as [$exception, $status, $body]) {
				$controller = $this->controller(signedIn: true);
				$this->handler->method($method)->willThrowException($exception);
				$response = $controller->$verb('1', '2', 'o');
				$this->assertSame([$status, $body], [$response->getStatus(), $response->getData()], $verb);
			}
		}
	}//end testEveryRefusalOnEveryVerb()

	/**
	 * An allowed call hands the handler the request's trimmed text and answers its marker.
	 *
	 * @return void
	 */
	public function testAllowedCallsAnswerTheMarker(): void {
		$params = ['reason' => ' done ', 'state' => ' woo '];
		$controller = $this->controller(signedIn: true, params: $params);
		$this->handler->expects($this->once())->method('archiveWith')->with(new ObjectStateRequest('o', 'done', '1', '2'))->willReturn(['archived' => true]);
		$this->assertSame(['archived' => true], $controller->archive('1', '2', 'o')->getData());

		$controller = $this->controller(signedIn: true, params: $params);
		$this->handler->expects($this->once())->method('unarchiveWith')->with(new ObjectStateRequest('o', 'done', '1', '2'))->willReturn(['archived' => false]);
		$this->assertSame(['archived' => false], $controller->unarchive('1', '2', 'o')->getData());

		$controller = $this->controller(signedIn: true, params: $params);
		$this->handler->expects($this->once())->method('freeze')->with(new ObjectStateRequest('o', 'done', '1', '2'), 'woo')->willReturn(['frozen' => true]);
		$this->assertSame(['frozen' => true], $controller->freeze('1', '2', 'o')->getData());

		$controller = $this->controller(signedIn: true, params: ['reason' => '  ']);
		$this->handler->expects($this->once())->method('unfreezeWith')->with(new ObjectStateRequest('o', null, '1', '2'))->willReturn(['frozen' => false]);
		$response = $controller->unfreeze('1', '2', 'o');
		$this->assertSame([200, ['frozen' => false]], [$response->getStatus(), $response->getData()]);
	}//end testAllowedCallsAnswerTheMarker()
}//end class
