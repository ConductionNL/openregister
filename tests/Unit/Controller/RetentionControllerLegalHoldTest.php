<?php

/**
 * Legal holds through the retention routes: placing, releasing and queueing a bulk hold.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Controller\RetentionController;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\HoldOrder;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RetentionControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A hold needs its object and a reason, carries the owner key when one is named, and is audited.
 *
 * @covers \OCA\OpenRegister\Controller\RetentionController
 * @uses \OCA\OpenRegister\Controller\Support\RetentionControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\RetentionDomain
 * @uses \OCA\OpenRegister\Controller\Support\RetentionMappers
 * @uses \OCA\OpenRegister\Controller\Support\RetentionPlatform
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\HoldOrder
 * @uses \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 */
class RetentionControllerLegalHoldTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;

	private RetentionService&MockObject $retentionService;

	private AuditTrailMapper&MockObject $auditMapper;

	private IJobList&MockObject $jobList;

	private ObjectEntity $object;

	/**
	 * A controller whose request answers the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string|null          $userId The signed-in user, or null for none.
	 *
	 * @return RetentionController
	 */
	private function controller(array $params, ?string $userId = 'archivist'): RetentionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->object = new ObjectEntity();
		$this->object->setUuid('obj-1');
		$this->object->setRetention(['legalHold' => ['active' => true, 'reason' => 'Lawsuit 12']]);

		$this->objectMapper     = $this->createMock(MagicMapper::class);
		$this->retentionService = $this->createMock(RetentionService::class);
		$this->auditMapper      = $this->createMock(AuditTrailMapper::class);
		$this->jobList          = $this->createMock(IJobList::class);

		return RetentionControllerFactory::create(
			'openregister',
			$request,
			$this->retentionService,
			$this->createMock(ObjectRetentionHandler::class),
			$this->createMock(SaveObject::class),
			$this->objectMapper,
			$this->createMock(SchemaMapper::class),
			$this->auditMapper,
			$this->createMock(DestructionService::class),
			$this->jobList,
			$session,
			new NullLogger()
		);
	}

	/**
	 * Missing object id or reason is a 400 and nothing is read.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function incompletePlacements(): array {
		return [
			'no object'   => [['reason' => 'Lawsuit 12']],
			'no reason'   => [['objectId' => 'obj-1']],
			'empty both'  => [['objectId' => '', 'reason' => '']],
		];
	}

	/**
	 * Placing without an object or a reason.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 *
	 * @dataProvider incompletePlacements
	 */
	public function testPlacingWithoutObjectOrReasonIs400(array $params): void {
		$controller = $this->controller($params);
		$this->objectMapper->expects($this->never())->method('find');

		$response = $controller->placeLegalHold();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'objectId and reason are required'], $response->getData());
	}

	public function testPlacingAHoldSavesAuditsAndAnswersTheHold(): void {
		$controller = $this->controller(['objectId' => 'obj-1', 'reason' => 'Lawsuit 12', 'ownerKey' => '  case-77  ']);
		$this->objectMapper->expects($this->once())->method('find')
			->with('obj-1', null, null, false, false, false)
			->willReturn($this->object);
		$this->retentionService->expects($this->once())->method('placeHold')
			->with($this->identicalTo($this->object), $this->callback(
				static fn (HoldOrder $order): bool => $order->reason === 'Lawsuit 12' && $order->ownerKey === 'case-77'
			))
			->willReturn($this->object);
		$this->objectMapper->expects($this->once())->method('update')->with($this->identicalTo($this->object));
		$this->auditMapper->expects($this->once())->method('recordObjectEvent')->with($this->callback(
			fn (ObjectAuditEvent $event): bool => $event->object === $this->object
				&& $event->action === 'archival.legal_hold_placed'
				&& $event->context === ['reason' => 'Lawsuit 12']
		));

		$response = $controller->placeLegalHold();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['status' => 'legal_hold_placed', 'objectId' => 'obj-1', 'legalHold' => ['active' => true, 'reason' => 'Lawsuit 12']],
			$response->getData()
		);
	}

	/**
	 * A blank or non-string owner key places a manual hold.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function manualOwnerKeys(): array {
		return ['absent' => [null], 'blank' => ['   '], 'not a string' => [['case-77']]];
	}

	/**
	 * Placing with an owner key that names no matter.
	 *
	 * @param mixed $ownerKey The owner key sent.
	 *
	 * @return void
	 *
	 * @dataProvider manualOwnerKeys
	 */
	public function testABlankOwnerKeyPlacesAManualHold(mixed $ownerKey): void {
		$controller = $this->controller(['objectId' => 'obj-1', 'reason' => 'Audit', 'ownerKey' => $ownerKey]);
		$this->objectMapper->method('find')->willReturn($this->object);
		$this->retentionService->expects($this->once())->method('placeHold')
			->with($this->anything(), $this->callback(static fn (HoldOrder $order): bool => $order->ownerKey === null))
			->willReturn($this->object);

		$this->assertSame(200, $controller->placeLegalHold()->getStatus());
	}

	public function testAnUnreadableObjectIs500AndNothingIsAudited(): void {
		$controller = $this->controller(['objectId' => 'gone', 'reason' => 'Lawsuit 12']);
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('Object not found'));
		$this->retentionService->expects($this->never())->method('placeHold');
		$this->auditMapper->expects($this->never())->method('recordObjectEvent');

		$response = $controller->placeLegalHold();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
	}

	public function testReleasingWithoutAReasonIs400(): void {
		$controller = $this->controller(['reason' => '']);
		$this->objectMapper->expects($this->never())->method('find');

		$response = $controller->releaseLegalHold('obj-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Release reason is required'], $response->getData());
	}

	public function testReleasingAHoldSavesAndAudits(): void {
		$controller = $this->controller(['reason' => 'Case closed', 'ownerKey' => 'case-77']);
		$this->objectMapper->expects($this->once())->method('find')
			->with('obj-1', null, null, false, false, false)
			->willReturn($this->object);
		$this->retentionService->expects($this->once())->method('releaseHold')
			->with($this->identicalTo($this->object), $this->callback(
				static fn (HoldOrder $order): bool => $order->reason === 'Case closed' && $order->ownerKey === 'case-77'
			))
			->willReturn($this->object);
		$this->objectMapper->expects($this->once())->method('update')->with($this->identicalTo($this->object));
		$this->auditMapper->expects($this->once())->method('recordObjectEvent')->with($this->callback(
			static fn (ObjectAuditEvent $event): bool => $event->action === 'archival.legal_hold_released'
				&& $event->context === ['reason' => 'Case closed']
		));

		$response = $controller->releaseLegalHold('obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['status' => 'legal_hold_released', 'objectId' => 'obj-1'], $response->getData());
	}

	public function testAFailingReleaseIs500AndNotSaved(): void {
		$controller = $this->controller(['reason' => 'Case closed']);
		$this->objectMapper->method('find')->willReturn($this->object);
		$this->retentionService->method('releaseHold')->willThrowException(new \RuntimeException('Another matter still holds this object'));
		$this->objectMapper->expects($this->never())->method('update');
		$this->auditMapper->expects($this->never())->method('recordObjectEvent');

		$response = $controller->releaseLegalHold('obj-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Another matter still holds this object'], $response->getData());
	}

	public function testABulkHoldIsQueuedForTheSignedInUser(): void {
		$controller = $this->controller(['schemaId' => '12', 'reason' => 'Investigation']);
		$this->jobList->expects($this->once())->method('add')
			->with(JobHandles::BULK_LEGAL_HOLD, ['schemaId' => '12', 'reason' => 'Investigation', 'userId' => 'archivist']);

		$response = $controller->placeBulkLegalHold();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['status' => 'queued', 'message' => 'Bulk legal hold queued for processing via background job'],
			$response->getData()
		);
	}

	public function testABulkHoldWithoutAUserIsQueuedAsSystem(): void {
		$controller = $this->controller(['schemaId' => '12', 'reason' => 'Investigation'], null);
		$this->jobList->expects($this->once())->method('add')
			->with(JobHandles::BULK_LEGAL_HOLD, ['schemaId' => '12', 'reason' => 'Investigation', 'userId' => 'system']);

		$this->assertSame(200, $controller->placeBulkLegalHold()->getStatus());
	}

	public function testABulkHoldWithoutSchemaOrReasonIs400AndQueuesNothing(): void {
		$controller = $this->controller(['schemaId' => '12']);
		$this->jobList->expects($this->never())->method('add');

		$response = $controller->placeBulkLegalHold();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'schemaId and reason are required'], $response->getData());
	}

	public function testAFailingQueueIs500(): void {
		$controller = $this->controller(['schemaId' => '12', 'reason' => 'Investigation']);
		$this->jobList->method('add')->willThrowException(new \Exception('queue down'));

		$response = $controller->placeBulkLegalHold();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'queue down'], $response->getData());
	}
}
