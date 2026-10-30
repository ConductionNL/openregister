<?php

/**
 * The flow dry-run endpoint: refused without a session or the flow.update
 * right, 404 for a flow or object the caller cannot reach, otherwise the steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-dry-run/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FlowDryRunController;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDryRunService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlowDryRunController.
 */
class FlowDryRunControllerTest extends TestCase {
	private FlowAccess $access;
	private FlowDryRunService $service;
	private IRequest $request;

	protected function setUp(): void {
		$this->access = $this->createMock(FlowAccess::class);
		$this->service = $this->createMock(FlowDryRunService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ['register' => 'dossiq', 'schema' => 'case', 'objectId' => 'o1'][$key] ?? $default
		);
	}

	private function controller(): FlowDryRunController {
		return new FlowDryRunController(appName: 'openregister', request: $this->request, access: $this->access, service: $this->service, logger: new \Psr\Log\NullLogger());
	}

	private function signedIn(bool $mayUpdate): void {
		$this->access->method('currentUser')->willReturn($this->createMock(IUser::class));
		$this->access->method('may')->willReturnCallback(static fn (IUser $user, string $action): bool => $action === 'flow.update' && $mayUpdate);
	}

	public function testRefusesWithoutASession(): void {
		$this->access->method('currentUser')->willReturn(null);
		$this->service->expects($this->never())->method('dryRun');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->dryRun('f1')->getStatus());
	}

	public function testRefusesWithoutTheRightThatEditsFlows(): void {
		$this->signedIn(mayUpdate: false);
		$this->service->expects($this->never())->method('dryRun');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->dryRun('f1')->getStatus());
	}

	public function testAsksForTheObject(): void {
		$this->signedIn(mayUpdate: true);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturn('');
		$this->service->expects($this->never())->method('dryRun');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->dryRun('f1')->getStatus());
	}

	public function testAnswers404ForAFlowOrObjectOutOfReach(): void {
		$this->signedIn(mayUpdate: true);
		$this->service->method('dryRun')->willThrowException(new DoesNotExistException('No such object'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->dryRun('f1')->getStatus());
	}

	public function testAnswersTheSteps(): void {
		$this->signedIn(mayUpdate: true);
		$steps = ['status' => 'completed', 'steps' => [['nodeId' => 'check']]];
		$this->service->expects($this->once())->method('dryRun')
			->with('f1', 'dossiq', 'case', 'o1')
			->willReturn($steps);

		$response = $this->controller()->dryRun('f1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($steps, $response->getData());
	}

	public function testAnyOtherFailureIsAGeneric500WithoutTheMessage(): void {
		$this->signedIn(mayUpdate: true);
		$this->service->method('dryRun')->willThrowException(new \Exception('SQLSTATE[42P01]: relation oc_secret does not exist'));

		$response = $this->controller()->dryRun('f1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'The dry run could not be completed.'], $response->getData());
	}
}
