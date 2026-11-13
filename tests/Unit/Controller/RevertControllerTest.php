<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\RevertController;
use OCA\OpenRegister\Controller\Support\RevertControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RevertController
 *
 * @package Unit\Controller
 */
class RevertControllerTest extends TestCase {
	private RevertController $controller;
	private IRequest&MockObject $request;
	private RevertHandler&MockObject $revertService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->revertService = $this->createMock(RevertHandler::class);

		$this->controller = new RevertController(
			$this->request,
			new RevertControllerServices(
				$this->revertService
			)
		);
	}

	public function testRevertWithDatetimeReturnsSuccess(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn(['id' => 1, 'uuid' => 'uuid-123']);

		$this->request->method('getParams')->willReturn([
			'datetime' => '2024-01-01T00:00:00',
		]);
		$this->revertService->method('revert')->willReturn($object);

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(200, $result->getStatus());
	}

	public function testRevertWithAuditTrailIdReturnsSuccess(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn(['id' => 1]);

		$this->request->method('getParams')->willReturn([
			'auditTrailId' => 42,
		]);
		$this->revertService->method('revert')->willReturn($object);

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(200, $result->getStatus());
	}

	public function testRevertWithVersionReturnsSuccess(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn(['id' => 1]);

		$this->request->method('getParams')->willReturn([
			'version' => '1.0.0',
		]);
		$this->revertService->method('revert')->willReturn($object);

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(200, $result->getStatus());
	}

	public function testRevertReturns400WhenNoCriteriaProvided(): void {
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(400, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('datetime', $data['error']);
	}

	public function testRevertReturns404WhenObjectNotFound(): void {
		$this->request->method('getParams')->willReturn([
			'datetime' => '2024-01-01T00:00:00',
		]);
		$this->revertService->method('revert')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(404, $result->getStatus());
	}

	public function testRevertReturns403WhenNotAuthorized(): void {
		$this->request->method('getParams')->willReturn([
			'datetime' => '2024-01-01T00:00:00',
		]);
		$this->revertService->method('revert')
			->willThrowException(new NotAuthorizedException('Forbidden'));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(403, $result->getStatus());
	}

	public function testRevertReturns423WhenLocked(): void {
		$this->request->method('getParams')->willReturn([
			'datetime' => '2024-01-01T00:00:00',
		]);
		$this->revertService->method('revert')
			->willThrowException(new LockedException('Locked'));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(423, $result->getStatus());
	}

	public function testRevertReturns409WhenFrozen(): void {
		$this->request->method('getParams')->willReturn(['version' => '1.0.1']);
		$frozen = new ObjectEntity();
		$frozen->setFrozen(['by' => 'bob', 'at' => '2026-09-01']);
		$this->revertService->method('revert')
			->willThrowException(ObjectStateWriteException::frozen($frozen));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(409, $result->getStatus());
	}

	/**
	 * A restored version the current schema refuses answers 422 with the
	 * validation errors, naming the field.
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testRevertReturns422WhenRestoredDataFailsTheSchema(): void {
		$this->request->method('getParams')->willReturn(['version' => '1.0.1']);
		$this->revertService->method('revert')
			->willThrowException(new ValidationException(message: "The required property 'einddatum' is missing"));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(422, $result->getStatus());
		$this->assertSame(
			[
				'error' => "This version does not fit the record's schema as it is now.",
				'errors' => [['message' => "The required property 'einddatum' is missing"]],
			],
			$result->getData()
		);
	}

	/**
	 * With the object service at hand, the 422 carries the errors in the shape an edit gets them.
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testThe422CarriesTheErrorsAsAnEditGetsThem(): void {
		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$controller = new RevertController($this->request, new RevertControllerServices($this->revertService, $objects));
		$this->request->method('getParams')->willReturn(['version' => '1.0.1']);
		$refusal = new ValidationException(message: 'einddatum is required');
		$this->revertService->method('revert')->willThrowException($refusal);
		$objects->expects($this->once())->method('handleValidationException')->with($refusal)->willReturn(
			new \OCP\AppFramework\Http\JSONResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => [['property' => 'einddatum']]], 400)
		);

		$result = $controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(422, $result->getStatus());
		$this->assertSame(['error' => "This version does not fit the record's schema as it is now.", 'errors' => [['property' => 'einddatum']]], $result->getData());
	}

	public function testRevertReturns500OnGenericException(): void {
		$this->request->method('getParams')->willReturn([
			'datetime' => '2024-01-01T00:00:00',
		]);
		$this->revertService->method('revert')
			->willThrowException(new Exception('Internal error'));

		$result = $this->controller->revert('reg', 'schema', 'uuid-123');

		$this->assertSame(500, $result->getStatus());
	}

	/**
	 * No refused or failed revert answers the exception's text: each answers a
	 * fixed sentence, and a 500 names a request id the log entry holding the
	 * exception names too.
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testNoAnswerCarriesExceptionText(): void {
		$frozen = new ObjectEntity();
		$frozen->setFrozen(['by' => 'bob', 'at' => '2026-09-01']);
		$cases = [
			[new DoesNotExistException('row 7 of oc_or_5_7 missing'), 404, ['error' => 'Record not found.']],
			[new NotAuthorizedException('rbac rule 3 refused alice'), 403, ['error' => 'You may not restore this record.']],
			[new LockedException('Object is locked by bob'), 423, ['error' => 'This record is locked by someone else.']],
			[ObjectStateWriteException::frozen($frozen), 409, ['error' => 'This record is archived or frozen and cannot be changed.']],
			[new \OCA\OpenRegister\Exception\HookStoppedException('hook said no', ['message' => 'Closed cases stay closed']), 422, ['error' => 'A rule on this record refused the restore.', 'errors' => ['message' => 'Closed cases stay closed']]],
			[new Exception('SQLSTATE[42S02] secret table'), 500, ['error' => 'The record could not be restored.', 'requestId' => 'req-42']],
		];

		foreach ($cases as [$exception, $status, $body]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn(['version' => '1.0.1']);
			$request->method('getId')->willReturn('req-42');
			$handler = $this->createMock(RevertHandler::class);
			$handler->method('revert')->willThrowException($exception);
			$logger = $this->createMock(LoggerInterface::class);
			if ($status === 500) {
				$logger->expects($this->once())->method('error')->with(
					$this->anything(),
					$this->callback(static fn (array $context): bool => $context['requestId'] === 'req-42' && $context['exception'] === $exception)
				);
			} else {
				$logger->expects($this->never())->method('error');
			}

			$controller = new RevertController($request, new RevertControllerServices($handler, null, $logger));
			$result = $controller->revert('reg', 'schema', 'uuid-123');

			$this->assertSame([$status, $body], [$result->getStatus(), $result->getData()], get_class($exception));
			$this->assertStringNotContainsString($exception->getMessage(), (string) json_encode($result->getData()), get_class($exception));
		}
	}
}
