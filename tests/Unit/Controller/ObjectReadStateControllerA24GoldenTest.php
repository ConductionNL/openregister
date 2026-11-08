<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectReadStateController}.
 *
 * Pins every allowed and refused answer (status and body) of the read-state
 * endpoints, and that a refusal at the RBAC boundary is reached before any
 * read-state row or notice is touched.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectReadStateController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the read-state endpoints.
 */
class ObjectReadStateControllerA24GoldenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Read-state service mock.
	 *
	 * @var ReadStateService&MockObject
	 */
	private ReadStateService&MockObject $readState;

	/**
	 * Notice clearing mock.
	 *
	 * @var NotificationClearingServiceInterface&MockObject
	 */
	private NotificationClearingServiceInterface&MockObject $clearing;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->readState = $this->createMock(ReadStateService::class);
		$this->clearing = $this->createMock(NotificationClearingServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(?ObjectEntity $object, bool $resolverThrows = false): ObjectReadStateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($resolverThrows === true) {
			$objects->method('getObject')->willThrowException(new \RuntimeException('db'));
		} else {
			$objects->method('getObject')->willReturn($object);
		}

		return new ObjectReadStateController(
			appName: 'openregister',
			request: $request,
			objectService: $objects,
			readState: $this->readState,
			clearing: $this->clearing,
			logger: $this->logger
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');

		return $object;
	}

	private function row(): ObjectReadState {
		$row = new ObjectReadState();
		$row->setUserId('alice');
		$row->setObjectUuid('11111111-1111-4111-8111-111111111111');
		$row->setSubSeen(['files' => true]);

		return $row;
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnreadableObjectIsNotFoundBeforeAnyRowIsTouched(): void {
		$this->readState->expects($this->never())->method($this->anything());
		$this->clearing->expects($this->never())->method($this->anything());
		$notFound = ['message' => 'Object not found'];

		foreach ([$this->controller(null), $this->controller(null, true)] as $controller) {
			$this->assertOutcome(404, $notFound, $controller->show('r', 's', 'x'));
			$this->assertOutcome(404, $notFound, $controller->markRead('r', 's', 'x'));
			$this->assertOutcome(404, $notFound, $controller->markUnread('r', 's', 'x'));
		}
	}

	public function testShowOutcomes(): void {
		$this->params = ['userId' => 'alice'];
		$this->readState->method('readStateFor')->with($this->anything(), 'alice')->willReturnOnConsecutiveCalls(
			null,
			$this->row(),
			$this->throwException(new NotAuthorizedException('Your own read state only')),
			$this->throwException(new \InvalidArgumentException('bad'))
		);
		$this->readState->method('unreadCounts')->willReturn(['files' => 2]);
		$controller = $this->controller($this->object());

		$unread = ['unread' => true, 'lastSeenAt' => null, 'subSeen' => [], 'unreadCounts' => ['files' => 2]];
		$this->assertOutcome(200, $unread, $controller->show('r', 's', 'x'));
		$read = ['unread' => false, 'lastSeenAt' => null, 'subSeen' => ['files' => true], 'unreadCounts' => ['files' => 2]];
		$this->assertOutcome(200, $read, $controller->show('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Your own read state only'], $controller->show('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectReadStateController] show: bad');
		$this->assertOutcome(500, ['message' => 'Could not complete the read state request'], $controller->show('r', 's', 'x'));
	}

	public function testUnreadCountsFailureIsNotCaught(): void {
		$this->readState->method('readStateFor')->willReturn(null);
		$this->readState->method('unreadCounts')->willThrowException(new \RuntimeException('counts'));

		$this->expectException(\RuntimeException::class);
		$this->controller($this->object())->show('r', 's', 'x');
	}

	public function testMarkReadOutcomes(): void {
		$this->readState->method('markRead')->with($this->anything(), 'r', 's', null)->willReturnOnConsecutiveCalls(
			$this->row(),
			$this->throwException(new NotAuthorizedException('Sign in first')),
			$this->throwException(new \InvalidArgumentException('bad'))
		);
		$this->clearing->method('clearForObject')->willReturn(2);
		$controller = $this->controller($this->object());

		$this->assertOutcome(
			200,
			array_merge($this->row()->jsonSerialize(), ['unread' => false, 'notificationsCleared' => 2]),
			$controller->markRead('r', 's', 'x')
		);
		$this->assertOutcome(403, ['message' => 'Sign in first'], $controller->markRead('r', 's', 'x'));
		$this->assertOutcome(500, ['message' => 'Could not complete the read state request'], $controller->markRead('r', 's', 'x'));
	}

	public function testMarkReadOnASubResourceClearsThatTab(): void {
		$this->params = ['subResource' => 'files'];
		$this->readState->expects($this->once())->method('markRead')->with($this->anything(), 'r', 's', 'files')->willReturn($this->row());
		$this->clearing->expects($this->once())->method('clearForSubResource')
			->with('11111111-1111-4111-8111-111111111111', 'files')->willReturn(1);

		$this->assertSame(1, $this->controller($this->object())->markRead('r', 's', 'x')->getData()['notificationsCleared']);
	}

	public function testClearingRefusalIsForbidden(): void {
		$this->readState->method('markRead')->willReturn($this->row());
		$this->clearing->method('clearForObject')->willThrowException(new NotAuthorizedException('no'));

		$this->assertOutcome(403, ['message' => 'no'], $this->controller($this->object())->markRead('r', 's', 'x'));
	}

	public function testMarkUnreadOutcomes(): void {
		$this->readState->method('markUnread')->willReturnOnConsecutiveCalls(
			true,
			$this->throwException(new NotAuthorizedException('Sign in first')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['unread' => true], $controller->markUnread('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Sign in first'], $controller->markUnread('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectReadStateController] markUnread: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the read state request'], $controller->markUnread('r', 's', 'x'));
	}
}
