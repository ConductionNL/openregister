<?php

/**
 * Golden outcomes of the portal task surface, pinned before a refactor.
 *
 * Pins the full wire body of every refusal: the administrator gate of the
 * delivery routes (no session, non-admin, failing backend, no backend), the
 * subject gate of the portal routes, and the translation of each service
 * refusal; plus the normalisation of answers and uploads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\PortalTaskController;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\PortalSubjectException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskCompleteViaAppException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\PortalTaskControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins allowed and refused outcomes of PortalTaskController.
 */
class PortalTaskControllerA24GoldenTest extends TestCase {

	/**
	 * Subject verifier.
	 *
	 * @var PortalSubjectAssertion&MockObject
	 */
	private PortalSubjectAssertion&MockObject $assertion;

	/**
	 * Portal task service.
	 *
	 * @var PortalTaskService&MockObject
	 */
	private PortalTaskService&MockObject $portal;

	/**
	 * Delivery outbox.
	 *
	 * @var PortalTaskDeliveryService&MockObject
	 */
	private PortalTaskDeliveryService&MockObject $delivery;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->assertion = $this->createMock(PortalSubjectAssertion::class);
		$this->portal = $this->createMock(PortalTaskService::class);
		$this->delivery = $this->createMock(PortalTaskDeliveryService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @param string|null        $uid    The session uid, or null.
	 * @param IGroupManager|null $groups The group backend.
	 *
	 * @return PortalTaskController
	 */
	private function controller(?string $uid = null, ?IGroupManager $groups = null): PortalTaskController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return PortalTaskControllerFactory::create(
			'openregister',
			$this->request,
			$this->assertion,
			$this->portal,
			$this->delivery,
			$session,
			$groups,
			$this->logger
		);
	}//end controller()

	/**
	 * A group backend answering isAdmin.
	 *
	 * @param bool|Exception $answer The answer or the failure.
	 *
	 * @return IGroupManager&MockObject
	 */
	private function groupBackend(bool|Exception $answer): IGroupManager&MockObject {
		$groups = $this->createMock(IGroupManager::class);
		if ($answer instanceof Exception) {
			$groups->method('isAdmin')->willThrowException($answer);
			return $groups;
		}

		$groups->method('isAdmin')->with('alice')->willReturn($answer);

		return $groups;
	}//end groupBackend()

	/**
	 * Every delivery route.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function deliveryRoutes(): array {
		return [
			'deliveries' => [fn (PortalTaskController $c) => $c->deliveries()],
			'delivered' => [fn (PortalTaskController $c) => $c->deliveryDelivered('d-1')],
			'failed' => [fn (PortalTaskController $c) => $c->deliveryFailed('d-1', '')],
		];
	}//end deliveryRoutes()

	/**
	 * No session: 401 with the full body, outbox untouched.
	 *
	 * @param callable $route The route.
	 *
	 * @dataProvider deliveryRoutes
	 *
	 * @return void
	 */
	public function testDeliveryWithoutSession(callable $route): void {
		$this->expectOutboxUntouched();
		$response = $route($this->controller(null, $this->groupBackend(true)));

		$this->assertSame([401, ['error' => 'No session', 'code' => 'no-session']], [$response->getStatus(), $response->getData()]);
	}//end testDeliveryWithoutSession()

	/**
	 * Non-admin, failing backend and no backend: 403 with the full body.
	 *
	 * @param callable $route The route.
	 *
	 * @dataProvider deliveryRoutes
	 *
	 * @return void
	 */
	public function testDeliveryForNonAdmins(callable $route): void {
		$this->expectOutboxUntouched();
		$backends = [$this->groupBackend(false), $this->groupBackend(new Exception('ldap')), null];
		foreach ($backends as $groups) {
			$response = $route($this->controller('alice', $groups));
			$this->assertSame([403, ['error' => 'Administrators only', 'code' => 'admin-required']], [$response->getStatus(), $response->getData()]);
		}
	}//end testDeliveryForNonAdmins()

	/**
	 * Administrator: rows, settlement, missing error 400, unknown 404.
	 *
	 * @return void
	 */
	public function testDeliveryForAdmin(): void {
		$row = new PortalTaskDelivery();
		$row->setUuid('d-1');
		$this->delivery->expects($this->once())->method('pending')->with(7)->willReturn([$row]);
		$this->delivery->method('markDelivered')->willThrowException(new DoesNotExistException('x'));
		$this->delivery->method('markFailed')->with('d-1', 'smtp')
			->willReturnOnConsecutiveCalls($row, $this->throwException(new DoesNotExistException('x')));
		$admin = $this->controller('alice', $this->groupBackend(true));

		$listed = $admin->deliveries(7);
		$missing = $admin->deliveryDelivered('d-9');
		$blank = $admin->deliveryFailed('d-1', '  ');
		$failed = $admin->deliveryFailed('d-1', 'smtp');
		$gone = $admin->deliveryFailed('d-1', 'smtp');

		$this->assertSame([200, ['results' => [$row->jsonSerialize()], 'total' => 1]], [$listed->getStatus(), $listed->getData()]);
		$this->assertSame([404, ['error' => 'No such delivery request', 'code' => 'no-such-delivery']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(
			[400, ['error' => 'A failed delivery needs an error naming why.', 'code' => 'error-required']],
			[$blank->getStatus(), $blank->getData()]
		);
		$this->assertSame([200, $row->jsonSerialize()], [$failed->getStatus(), $failed->getData()]);
		$this->assertSame([404, ['error' => 'No such delivery request', 'code' => 'no-such-delivery']], [$gone->getStatus(), $gone->getData()]);
	}//end testDeliveryForAdmin()

	/**
	 * Every portal route refuses without a subject, logging the verifier detail.
	 *
	 * @return void
	 */
	public function testPortalRoutesWithoutSubject(): void {
		$this->assertion->method('resolve')->willThrowException(new PortalSubjectException(refusal: 'portal-subject-missing', message: 'no header'));
		$this->logger->expects($this->exactly(3))->method('info')
			->with('[PortalTaskController] Refused a portal request: no header', ['code' => 'portal-subject-missing']);
		$this->portal->expects($this->never())->method('listForSubject');
		$this->portal->expects($this->never())->method('show');
		$this->portal->expects($this->never())->method('complete');

		$controller = $this->controller();
		foreach ([$controller->index(), $controller->show('t-1'), $controller->complete('t-1')] as $response) {
			$this->assertSame(
				[401, ['error' => 'No acting portal subject', 'code' => 'portal-subject-missing']],
				[$response->getStatus(), $response->getData()]
			);
		}
	}//end testPortalRoutesWithoutSubject()

	/**
	 * Each service refusal maps to its exact body.
	 *
	 * @return void
	 */
	public function testRefusalBodies(): void {
		$this->assertion->method('resolve')->willReturn(new PortalSubject(subjectRef: 'sub-1'));
		$boom = new Exception('SQL detail');
		$this->portal->method('show')->willReturnOnConsecutiveCalls(
			$this->throwException(new TaskValidationException('too big')),
			$this->throwException(new TaskAccessDeniedException('not yours')),
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new TaskCompleteViaAppException(app: 'a', action: 'b')),
			$this->throwException(new TaskConflictException('closed')),
			$this->throwException($boom)
		);
		$this->logger->expects($this->once())->method('error')
			->with('[PortalTaskController] Portal task operation failed: SQL detail', ['exception' => $boom]);

		$expected = [
			[400, ['error' => 'too big', 'code' => 'upload-constraint']],
			[404, ['error' => 'No such task', 'code' => 'no-such-task']],
			[404, ['error' => 'No such task', 'code' => 'no-such-task']],
			[
				409,
				['error' => 'This task is completed through the b action of a.', 'code' => 'complete-via-app', 'completeVia' => ['app' => 'a', 'action' => 'b']],
			],
			[409, ['error' => 'closed', 'code' => 'task-closed']],
			[500, ['error' => 'The portal task operation failed. The details are in the server log.', 'code' => 'internal']],
		];
		foreach ($expected as $outcome) {
			$response = $this->controller()->show('t-1');
			$this->assertSame($outcome, [$response->getStatus(), $response->getData()]);
		}
	}//end testRefusalBodies()

	/**
	 * Allowed: index and show answer the service's page and row.
	 *
	 * @return void
	 */
	public function testAllowedReads(): void {
		$subject = new PortalSubject(subjectRef: 'sub-1');
		$this->assertion->method('resolve')->willReturn($subject);
		$task = new Task();
		$this->portal->method('listForSubject')->with($subject, 25, 0)->willReturn(['total' => 0]);
		$this->portal->method('show')->with($subject, 't-1')->willReturn($task);
		$this->portal->method('row')->with($task)->willReturn(['uuid' => 't-1']);

		$this->assertSame(['total' => 0], $this->controller()->index()->getData());
		$this->assertSame(['uuid' => 't-1'], $this->controller()->show('t-1')->getData());
	}//end testAllowedReads()

	/**
	 * Answers: JSON string, array, invalid JSON and other types; single-slot uploads.
	 *
	 * @param mixed                $raw      The raw answers param.
	 * @param array<string, mixed> $expected The answers the service receives.
	 *
	 * @testWith ["{\"a\":1}", {"a": 1}]
	 *           [{"b": 2}, {"b": 2}]
	 *           ["not json", []]
	 *           ["7", []]
	 *           [5, []]
	 *           [null, []]
	 *
	 * @return void
	 */
	public function testCompleteNormalisesInput(mixed $raw, array $expected): void {
		$subject = new PortalSubject(subjectRef: 'sub-1');
		$this->assertion->method('resolve')->willReturn($subject);
		$this->request->method('getParam')->with('answers')->willReturn($raw);
		$this->request->method('getUploadedFile')->willReturnCallback(
			static fn (string $key): ?array => match ($key) {
				'file' => ['name' => 'b.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/b', 'size' => '3', 'error' => '0'],
				'files' => [],
			}
		);
		$task = new Task();
		$this->portal->expects($this->once())->method('complete')
			->with(
				$subject,
				't-1',
				$expected,
				'c',
				[['name' => 'b.txt', 'type' => 'text/plain', 'size' => 3, 'tmp_name' => '/tmp/b', 'error' => 0]],
				'approve'
			)
			->willReturn($task);
		$this->portal->method('row')->willReturn(['done' => true]);

		$response = $this->controller()->complete('t-1', 'approve', 'c');

		$this->assertSame([200, ['done' => true]], [$response->getStatus(), $response->getData()]);
	}//end testCompleteNormalisesInput()

	/**
	 * Multi-slot uploads keep only the slots that carry a file, filling gaps.
	 *
	 * @return void
	 */
	public function testMultiSlotUploads(): void {
		$this->assertion->method('resolve')->willReturn(new PortalSubject(subjectRef: 'sub-1'));
		$this->request->method('getUploadedFile')->willReturnCallback(
			static fn (string $key): ?array => match ($key) {
				'files' => [
					'name' => ['x' => 'a.pdf', 'y' => 'b.pdf', 'z' => 'c.pdf'],
					'type' => ['x' => 'p'],
					'tmp_name' => ['x' => '/a', 'y' => '/b', 'z' => '/c'],
					'size' => ['x' => 1],
					'error' => ['x' => 0, 'y' => 4],
				],
				'file' => null,
			}
		);
		$this->portal->expects($this->once())->method('complete')
			->with(
				$this->anything(),
				't-1',
				[],
				null,
				[['name' => 'a.pdf', 'type' => 'p', 'size' => 1, 'tmp_name' => '/a', 'error' => 0]],
				PortalTaskService::DEFAULT_OUTCOME
			)
			->willReturn(new Task());
		$this->portal->method('row')->willReturn([]);

		$this->assertSame(200, $this->controller()->complete('t-1')->getStatus());
	}//end testMultiSlotUploads()

	/**
	 * The outbox is not reached.
	 *
	 * @return void
	 */
	private function expectOutboxUntouched(): void {
		foreach (['pending', 'markDelivered', 'markFailed'] as $method) {
			$this->delivery->expects($this->never())->method($method);
		}
	}//end expectOutboxUntouched()
}//end class
