<?php

/**
 * SyncRecordsController: the queue with mappedData, one resolution, and a bulk resolution with a partial failure.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\SyncRecordsController;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\SyncRecordsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Service\Sync\SyncRecordResolver
 * @uses   \OCA\OpenRegister\Service\Sync\ResolutionRefused
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestImportServices
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 * @uses   \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 * @uses   \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\SyncRecord
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 */
class SyncRecordsControllerTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var SourceMapperInterface&MockObject */
	private SourceMapperInterface $sources;

	/** @var SyncRecordMapperInterface&MockObject */
	private SyncRecordMapperInterface $records;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var MappingMapperInterface&MockObject */
	private MappingMapperInterface $mappings;

	/** @var HarvestPipelineService&MockObject */
	private HarvestPipelineService $pipeline;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	private bool $admin = true;

	private bool $signedIn = true;

	private SyncRecordsController $controller;

	protected function setUp(): void {
		$this->request     = $this->createMock(IRequest::class);
		$this->sources     = $this->createMock(SourceMapperInterface::class);
		$this->records     = $this->createMock(SyncRecordMapperInterface::class);
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->mappings    = $this->createMock(MappingMapperInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->pipeline    = $this->getMockBuilder(HarvestPipelineService::class)
			->disableOriginalConstructor()->onlyMethods(['applyMapping'])->getMock();
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);

		$this->records->method('transitionStatus')->willReturnCallback(
			static function (SyncRecord $record, string $status): SyncRecord {
				$record->setStatus($status);
				return $record;
			}
		);

		$this->controller = new SyncRecordsController(
			request: $this->request,
			sources: $this->sources,
			records: $this->records,
			resolver: new SyncRecordResolver(
				records: $this->records,
				import: new HarvestImportServices(
					mappingMapper: $this->mappings,
					mappingService: $this->createMock(MappingService::class),
					objectService: $this->objects,
					conflictResolver: new SyncConflictResolver()
				),
				pipeline: $this->pipeline,
				permissions: $this->permissions,
				schemas: $schemas
			),
			session: new ControllerSession(userSession: $userSession, groupManager: $groups, logger: new NullLogger())
		);
	}

	private function source(): Source {
		$source = new Source();
		$source->setId(2);
		$source->setUuid('src-1');
		$source->setMappingId(8);
		$source->setTargetSchema('6');
		$source->setProtectedFields(['status']);
		$this->sources->method('find')->with(2)->willReturn($source);
		return $source;
	}

	private function conflict(int $id, string $objectUuid='obj-1'): SyncRecord {
		$record = new SyncRecord();
		$record->setId($id);
		$record->setSourceId(2);
		$record->setExternalId('ext-'.$id);
		$record->setStatus(SyncRecordStatus::CONFLICT);
		$record->setObjectUuid($objectUuid);
		$record->setRawData(['t' => 'Harvested']);
		return $record;
	}

	private function local(string $owner='alice'): ObjectEntity {
		$local = new ObjectEntity();
		$local->setUuid('obj-1');
		$local->setSchema('6');
		$local->setOwner($owner);
		$local->setObject(['title' => 'Local']);
		return $local;
	}

	public function testTheQueueCarriesMappedDataWithoutTheProtectedField(): void {
		$this->source();
		$this->request->method('getParam')->with('status', '')->willReturn('conflict');
		$this->records->method('findLatestBySource')->with(2, 'conflict')->willReturn([$this->conflict(11)]);
		$this->mappings->method('find')->willReturn(new Mapping());
		$this->pipeline->method('applyMapping')->willReturn(['title' => 'Harvested', 'status' => 'published']);

		$response = $this->controller->index(2);

		$this->assertSame(200, $response->getStatus());
		$row = $response->getData()['results'][0];
		$this->assertSame(['title' => 'Harvested'], $row['mappedData']);
		$this->assertArrayNotHasKey('status', $row['mappedData']);
		$this->assertSame(['t' => 'Harvested'], $row['rawData']);
	}

	public function testAFailingMappingKeepsTheRecordInTheQueueWithTheError(): void {
		$this->source();
		$this->request->method('getParam')->willReturn('conflict');
		$this->records->method('findLatestBySource')->willReturn([$this->conflict(11)]);
		$this->mappings->method('find')->willReturn(new Mapping());
		$this->pipeline->method('applyMapping')->willThrowException(new RuntimeException('twig: unknown filter'));

		$row = $this->controller->index(2)->getData()['results'][0];

		$this->assertSame(11, $row['id']);
		$this->assertNull($row['mappedData']);
		$this->assertSame('twig: unknown filter', $row['mappingError']);
	}

	public function testASourceOutsideTheCallersOrganisationIs404AndNoUserIs401(): void {
		$this->sources->method('find')->willThrowException(new DoesNotExistException('foreign'));
		$this->assertSame(404, $this->controller->index(9)->getStatus());

		$this->signedIn = false;
		$this->assertSame(401, $this->controller->index(9)->getStatus());
	}

	public function testResolvingAnObjectTheCallerMayNotUpdateIs403(): void {
		$this->admin = false;
		$this->source();
		$this->records->method('findOfSource')->with(2, 11)->willReturn($this->conflict(11));
		$this->objects->method('find')->willReturn($this->local(owner: 'bob'));
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->request->method('getParams')->willReturn(['action' => 'keep-local']);

		$response = $this->controller->resolve(2, 11);

		$this->assertSame(403, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testResolvingARecordOfAnotherSourceIs404(): void {
		$this->source();
		$this->records->method('findOfSource')->willReturn(null);
		$this->request->method('getParams')->willReturn(['action' => 'keep-local']);

		$this->assertSame(404, $this->controller->resolve(2, 99)->getStatus());
	}

	public function testOneResolutionReturnsTheSettledRecord(): void {
		$this->source();
		$this->records->method('findOfSource')->willReturn($this->conflict(11));
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->request->method('getParams')->willReturn(['action' => 'keep-local']);

		$response = $this->controller->resolve(2, 11);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('shadowed', $response->getData()['status']);
		$this->assertSame('alice', $response->getData()['resolutions'][0]['resolvedBy']);
	}

	public function testABulkResolutionReportsEachItemAndOneFailureRollsBackNothing(): void {
		$this->source();
		$first  = $this->conflict(11);
		$second = $this->conflict(12);
		$this->records->method('findOfSource')->willReturnCallback(
			static fn (int $sourceId, int $recordId): ?SyncRecord => [11 => $first, 12 => $second][$recordId] ?? null
		);
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->request->method('getParam')->with('resolutions')->willReturn([
			['recordId' => 11, 'action' => 'discard'],
			['recordId' => 12, 'action' => 'overwrite'],
			['recordId' => 13, 'action' => 'discard'],
		]);

		$results = $this->controller->resolveBulk(2)->getData()['results'];

		$this->assertSame([[11, 200], [12, 422], [13, 404]], array_map(static fn (array $r): array => [$r['recordId'], $r['status']], $results));
		$this->assertSame('rejected', $first->getStatus());
		$this->assertTrue($first->getResolutions()[0]['bulk']);
		$this->assertSame(SyncRecordStatus::CONFLICT, $second->getStatus());
	}

	public function testABulkBodyWithoutAListIs422(): void {
		$this->source();
		$this->request->method('getParam')->willReturn(null);

		$this->assertSame(422, $this->controller->resolveBulk(2)->getStatus());
	}
}
