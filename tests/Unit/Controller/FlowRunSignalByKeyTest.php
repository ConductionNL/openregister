<?php

/**
 * Correlation-addressed signal delivery is fail-closed in both directions
 * (flow-approval-consolidation task 5.1, design D-7):
 *
 *  - zero matches → 404, and the signal is NOT buffered for a later run
 *  - more than one match → 409, and NOTHING is woken
 *  - exactly one match → the same guards and the same signal() as resume()
 *
 * The "cannot decide a user task" boundary is
 * UserTaskNodeTest::testASignalWithADecisionDoesNotAnswerForThePerformer —
 * a delivered signal reaches the run's context, and the user-task node
 * treats it as a nudge, never as an answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\FlowRunController
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 */
class FlowRunSignalByKeyTest extends TestCase {

	private IRequest&MockObject $request;
	private FlowRunMapper&MockObject $mapper;
	private FlowRunService&MockObject $runner;
	private FlowService&MockObject $flows;
	private FlowRunController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['key' => 'vote:proposal-42', 'decision' => 'approve']);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->flows = $this->createMock(FlowService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new FlowRunController(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			runner: $this->runner,
			resolvers: $this->createMock(FlowLocator::class),
			userSession: $userSession,
			organisationService: $this->createMock(OrganisationService::class),
			flows: $this->flows
		);
	}//end setUp()

	private function suspendedRun(string $uuid): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setCorrelationKey('vote:proposal-42');

		return $run;
	}//end suspendedRun()

	public function testAnUnmatchedKeyIs404AndNothingIsBuffered(): void {
		$this->mapper->method('findSuspendedByCorrelationKey')->willReturn([]);
		$this->runner->expects(self::never())->method('signal');

		$response = $this->controller->signalByKey(key: 'vote:proposal-99');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertStringContainsString('not stored', (string)$response->getData()['error']);
	}//end testAnUnmatchedKeyIs404AndNothingIsBuffered()

	public function testAnAmbiguousKeyIs409AndWakesNothing(): void {
		$this->mapper->method('findSuspendedByCorrelationKey')->willReturn(
			[$this->suspendedRun('run-1'), $this->suspendedRun('run-2')]
		);
		$this->runner->expects(self::never())->method('signal');

		$response = $this->controller->signalByKey(key: 'vote:proposal-42');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertStringContainsString('More than one', (string)$response->getData()['error']);
	}//end testAnAmbiguousKeyIs409AndWakesNothing()

	public function testExactlyOneMatchIsSignalledWithThePayload(): void {
		$run = $this->suspendedRun('run-1');
		$this->mapper->method('findSuspendedByCorrelationKey')->willReturn([$run]);
		$this->runner->expects(self::once())->method('signal')
			->with($run, ['decision' => 'approve'])
			->willReturn($run);

		$response = $this->controller->signalByKey(key: 'vote:proposal-42');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testExactlyOneMatchIsSignalledWithThePayload()

	public function testTheFlowAuthorityIsTheSameAsResume(): void {
		// The flow does not resolve for this caller: the run must be as
		// invisible as a nonexistent one, exactly like resume().
		$run = $this->suspendedRun('run-1');
		$this->mapper->method('findSuspendedByCorrelationKey')->willReturn([$run]);
		$this->flows->method('find')->willThrowException(new \RuntimeException('not yours'));
		$this->runner->expects(self::never())->method('signal');

		$response = $this->controller->signalByKey(key: 'vote:proposal-42');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTheFlowAuthorityIsTheSameAsResume()

	public function testABlankKeyIsRefusedAsNotFound(): void {
		$this->mapper->expects(self::never())->method('findSuspendedByCorrelationKey');

		$response = $this->controller->signalByKey(key: '   ');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testABlankKeyIsRefusedAsNotFound()
}//end class
