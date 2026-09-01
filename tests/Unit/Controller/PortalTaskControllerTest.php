<?php

/**
 * The portal seam's HTTP translation, and the contract test for every
 * /api/portal-tasks endpoint (hydra gate-25).
 *
 * What the WIRE says: 401 with a stable code when no acting subject can be
 * resolved; 404 for a task that is absent OR not this subject's; 400 naming
 * the violated upload constraint; 409 for a closed task; a generic 500 whose
 * detail went to the log; and the delivery routes closed to anyone but an
 * authenticated administrator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\PortalTaskController;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\PortalSubjectException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HTTP status and body translation for every portal task route.
 *
 * @covers \OCA\OpenRegister\Controller\PortalTaskController
 * @covers \OCA\OpenRegister\Exception\PortalSubjectException
 * @covers \OCA\OpenRegister\Db\PortalTaskDelivery
 */
class PortalTaskControllerTest extends TestCase {

	/**
	 * The verifier, mocked.
	 *
	 * @var PortalSubjectAssertion&MockObject
	 */
	private PortalSubjectAssertion&MockObject $assertion;

	/**
	 * The seam service, mocked.
	 *
	 * @var PortalTaskService&MockObject
	 */
	private PortalTaskService&MockObject $portal;

	/**
	 * The delivery ledger, mocked.
	 *
	 * @var PortalTaskDeliveryService&MockObject
	 */
	private PortalTaskDeliveryService&MockObject $delivery;

	/**
	 * The request, mocked.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The log, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
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
	 * A controller with the given session user and admin status.
	 *
	 * @param string|null $uid The session user, or null for none.
	 * @param bool $admin Whether that user is an administrator.
	 *
	 * @return PortalTaskController The controller.
	 */
	private function controller(?string $uid = null, bool $admin = false): PortalTaskController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new PortalTaskController('openregister', $this->request, $this->assertion, $this->portal, $this->delivery, $session, $groups, $this->logger);
	}//end controller()

	/**
	 * The verifier resolves subject `sub-1`.
	 *
	 * @return PortalSubject The subject.
	 */
	private function subjectResolves(): PortalSubject {
		$subject = new PortalSubject(subjectRef: 'sub-1');
		$this->assertion->method('resolve')->willReturn($subject);

		return $subject;
	}//end subjectResolves()

	/**
	 * A completed task row.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_COMPLETED);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);

		return $task;
	}//end task()

	/**
	 * GET /api/portal-tasks: without a resolvable subject, 401 with the code; with one, the page.
	 *
	 * @return void
	 */
	public function testIndexRequiresASubjectAndReturnsThePage(): void {
		$this->assertion->method('resolve')->willThrowException(
			new PortalSubjectException(refusal: PortalSubjectException::CODE_MISSING, message: 'no header')
		);
		$response = $this->controller()->index();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('portal-subject-missing', $response->getData()['code']);
		$this->assertStringNotContainsString('no header', (string)json_encode($response->getData()), 'the verifier detail stays in the log');

		$this->setUp();
		$subject = $this->subjectResolves();
		$this->portal->expects($this->once())->method('listForSubject')->with($subject, 10, 5)->willReturn(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 5]);
		$response = $this->controller()->index(limit: 10, offset: 5);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
	}//end testIndexRequiresASubjectAndReturnsThePage()

	/**
	 * GET /api/portal-tasks/{uuid}: the row for the subject's task; 404 otherwise.
	 *
	 * @return void
	 */
	public function testShowAnswersTheRowOrAbsence(): void {
		$this->subjectResolves();
		$task = $this->task();
		$this->portal->method('show')->willReturnCallback(
			static function (PortalSubject $subject, string $uuid) use ($task): Task {
				if ($uuid === 't-1') {
					return $task;
				}

				throw new DoesNotExistException('nope');
			}
		);
		$this->portal->method('row')->willReturn(['uuid' => 't-1']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->show(uuid: 't-1')->getStatus());
		$absent = $this->controller()->show(uuid: 'other');
		$this->assertSame(Http::STATUS_NOT_FOUND, $absent->getStatus());
		$this->assertSame('no-such-task', $absent->getData()['code']);
	}//end testShowAnswersTheRowOrAbsence()

	/**
	 * POST /api/portal-tasks/{uuid}/complete: the uploads and answers reach the
	 * service normalised, and the completed row comes back.
	 *
	 * @return void
	 */
	public function testCompletePassesNormalisedUploadsAndAnswers(): void {
		$subject = $this->subjectResolves();
		$this->request->method('getParam')->with('answers')->willReturn('{"remarks":"ok"}');
		$this->request->method('getUploadedFile')->willReturnCallback(
			static function (string $key): ?array {
				if ($key === 'files') {
					return [
						'name' => ['a.pdf', ''],
						'type' => ['application/pdf', ''],
						'tmp_name' => ['/tmp/a', ''],
						'size' => [12, 0],
						'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
					];
				}

				return null;
			}
		);
		$this->portal->expects($this->once())
			->method('complete')
			->with(
				$subject,
				't-1',
				['remarks' => 'ok'],
				'here',
				[['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 12, 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK]],
				'submitted'
			)
			->willReturn($this->task());
		$this->portal->method('row')->willReturn(['uuid' => 't-1', 'state' => 'completed']);

		$response = $this->controller()->complete(uuid: 't-1', comment: 'here');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed', $response->getData()['state']);
	}//end testCompletePassesNormalisedUploadsAndAnswers()

	/**
	 * Completion refusals on the wire: 404 for a denial (a stranger confirms
	 * nothing), 400 naming the constraint, 409 for a closed task, a generic 500.
	 *
	 * @return void
	 */
	public function testCompletionRefusalsTranslateUniformly(): void {
		$this->subjectResolves();
		$this->request->method('getUploadedFile')->willReturn(null);
		$cases = [
			[new TaskAccessDeniedException('only the matched portal subject may answer'), Http::STATUS_NOT_FOUND, 'no-such-task'],
			[new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND, 'no-such-task'],
			[new TaskValidationException(message: 'This task requires at least one file (uploadRequired).'), Http::STATUS_BAD_REQUEST, 'upload-constraint'],
			[new TaskConflictException(message: "task 't-1' is already in terminal state 'completed'"), Http::STATUS_CONFLICT, 'task-closed'],
			[new RuntimeException('SQLSTATE[HY000] with bound parameters'), Http::STATUS_INTERNAL_SERVER_ERROR, 'internal'],
		];

		foreach ($cases as [$failure, $status, $code]) {
			$portal = $this->createMock(PortalTaskService::class);
			$portal->method('complete')->willThrowException($failure);
			$this->portal = $portal;
			$response = $this->controller()->complete(uuid: 't-1');
			$this->assertSame($status, $response->getStatus(), get_class($failure));
			$this->assertSame($code, $response->getData()['code']);
			if ($status === Http::STATUS_NOT_FOUND) {
				$this->assertStringNotContainsString('matched', (string)$response->getData()['error'], 'a denial reads as absence');
			}

			if ($status === Http::STATUS_BAD_REQUEST) {
				$this->assertStringContainsString('uploadRequired', (string)$response->getData()['error'], 'the constraint is named');
			}

			if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
				$this->assertStringNotContainsString('SQLSTATE', (string)$response->getData()['error']);
			}
		}
	}//end testCompletionRefusalsTranslateUniformly()

	/**
	 * The delivery routes: 401 without a session, 403 for a non-administrator,
	 * the rows for an administrator; settlement passes through and 404s an
	 * unknown request; a failure needs an error.
	 *
	 * @return void
	 */
	public function testDeliveryRoutesAreTheAdministrators(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->deliveries()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'alice')->deliveries()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'alice')->deliveryDelivered(uuid: 'd-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'alice')->deliveryFailed(uuid: 'd-1', error: 'x')->getStatus());

		$row = new PortalTaskDelivery();
		$row->setUuid('d-1');
		$row->setChannel(PortalTaskDelivery::CHANNEL_MAIL);
		$row->setState(PortalTaskDelivery::STATE_REQUESTED);
		$this->delivery->method('pending')->with(100)->willReturn([$row]);
		$this->delivery->method('markDelivered')->willReturnCallback(
			static function (string $uuid) use ($row): PortalTaskDelivery {
				if ($uuid !== 'd-1') {
					throw new DoesNotExistException('nope');
				}

				$row->setState(PortalTaskDelivery::STATE_DELIVERED);

				return $row;
			}
		);
		$this->delivery->method('markFailed')->willReturn($row);

		$admin = $this->controller(uid: 'root', admin: true);
		$listed = $admin->deliveries();
		$this->assertSame(Http::STATUS_OK, $listed->getStatus());
		$this->assertSame(1, $listed->getData()['total']);
		$this->assertSame('d-1', $listed->getData()['results'][0]['uuid']);

		$this->assertSame('delivered', $admin->deliveryDelivered(uuid: 'd-1')->getData()['state']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $admin->deliveryDelivered(uuid: 'ghost')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $admin->deliveryFailed(uuid: 'd-1', error: '')->getStatus());
		$this->assertSame(Http::STATUS_OK, $admin->deliveryFailed(uuid: 'd-1', error: 'smtp down')->getStatus());
	}//end testDeliveryRoutesAreTheAdministrators()
}//end class
