<?php

/**
 * A write a change gate held answers 202 with the pending change, a second one 409.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers and mock builders use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Listener\ChangeGateListener;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The create, update and patch actions share one refusal mapping; this drives it directly.
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */
class ObjectsControllerHeldChangeTest extends TestCase {

	/**
	 * A held write answers 202 with the pending change's key, the object and the chain.
	 *
	 * @return void
	 */
	public function testAHeldWriteAnswersAccepted(): void {
		$response = $this->refusal(
			new HookStoppedException(
				message: 'This change waits for approval through chain "bank-details".',
				errors: [
					'code' => ChangeGateListener::CODE_PENDING,
					'status' => 202,
					'message' => 'This change waits for approval through chain "bank-details".',
					'pendingChange' => 'pending-1',
					'object' => 'sup-1',
					'chain' => 'bank-details',
				]
			)
		);

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'This change waits for approval through chain "bank-details".',
				'pendingChange' => 'pending-1',
				'object' => 'sup-1',
				'chain' => 'bank-details',
			],
			$response->getData()
		);
	}//end testAHeldWriteAnswersAccepted()

	/**
	 * A gated write while a pending change is open answers 409 naming it.
	 *
	 * @return void
	 */
	public function testASecondGatedWriteAnswersConflict(): void {
		$errors = ['code' => ChangeGateListener::CODE_OPEN, 'status' => 409, 'pendingChange' => 'pending-1'];

		$response = $this->refusal(new HookStoppedException(message: 'Already waiting (pending-1).', errors: $errors));

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Already waiting (pending-1).', 'errors' => $errors], $response->getData());
	}//end testASecondGatedWriteAnswersConflict()

	/**
	 * Any other hook refusal keeps its 422, even when it names a status.
	 *
	 * @return void
	 */
	public function testAnyOtherHookRefusalStillAnswers422(): void {
		$errors = ['code' => 'approval-chain-pending', 'status' => 202];

		$response = $this->refusal(new HookStoppedException(message: 'Transition requires approval.', errors: $errors));

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Transition requires approval.', 'errors' => $errors], $response->getData());
	}//end testAnyOtherHookRefusalStillAnswers422()

	/**
	 * The controller's answer to a write refusal.
	 *
	 * @param HookStoppedException $exception The refusal.
	 *
	 * @return JSONResponse The answer.
	 */
	private function refusal(HookStoppedException $exception): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		$method = new ReflectionMethod(ObjectsController::class, 'writeRefusal');
		$response = $method->invoke($controller, $exception, $this->createMock(ObjectOperationsInterface::class));
		$this->assertInstanceOf(JSONResponse::class, $response);

		return $response;
	}//end refusal()
}//end class
