<?php

/**
 * Contract tests for the two hinge endpoints on ObjectsController.
 *
 * `referencedBy` is the reverse view and `geoFeatures` the inherited map
 * features. Both resolve the object first, so both have the same two failure
 * modes worth pinning: an object that is not there must answer 404 rather than
 * an empty success, and the caller's access must reach the service rather than
 * being decided after the fact.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollector;
use OCA\OpenRegister\Service\Hinge\ReferencedByService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class ObjectsControllerHingeTest extends TestCase {

	private ObjectsController $controller;
	private IRequest&MockObject $request;
	private ObjectService&MockObject $objectService;
	private SchemaMapper&MockObject $schemaMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['_limit' => 5]);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-address');
		$object->setSchema(12);
		return $object;
	}

	public function testTheReverseViewReturnsTheGroupsTheServiceAnswers(): void {
		$this->objectService->method('find')->willReturn($this->object());

		$answer = [
			'object' => ['id' => 'uuid-address'],
			'groups' => [['schema' => ['slug' => 'melding'], 'total' => 3, 'results' => []]],
			'total' => 3,
		];

		$service = $this->createMock(ReferencedByService::class);
		$service->expects($this->once())
			->method('getReferencingGroups')
			->with(
				$this->isInstanceOf(ObjectEntity::class),
				$this->arrayHasKey('_limit'),
				// An ordinary caller reads with the access filter on. An
				// endpoint that decided this after the query would return a
				// total that advertises what the list withholds.
				true
			)
			->willReturn($answer);

		$response = $this->controller->referencedBy(
			'uuid-address',
			'3',
			'12',
			$this->objectService,
			$service
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}

	public function testTheReverseViewRefusesAnObjectThatIsNotThere(): void {
		$this->objectService->method('find')->willReturn(null);

		$service = $this->createMock(ReferencedByService::class);
		$service->expects($this->never())->method('getReferencingGroups');

		$response = $this->controller->referencedBy(
			'uuid-gone',
			'3',
			'12',
			$this->objectService,
			$service
		);

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testTheFeatureEndpointReturnsTheCollectionTheCollectorBuilds(): void {
		$this->objectService->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$collection = [
			'type' => 'FeatureCollection',
			'features' => [['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [5.1, 52.1]], 'properties' => []]],
		];

		$collector = $this->createMock(InheritedGeoCollector::class);
		$collector->expects($this->once())
			->method('collect')
			->with($this->isInstanceOf(ObjectEntity::class), $this->isInstanceOf(Schema::class), true)
			->willReturn($collection);

		$response = $this->controller->geoFeatures(
			'uuid-address',
			'3',
			'12',
			$this->objectService,
			$collector
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($collection, $response->getData());
	}

	public function testTheFeatureEndpointStillAnswersWhenTheSchemaCannotBeRead(): void {
		$this->objectService->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));

		$collector = $this->createMock(InheritedGeoCollector::class);
		$collector->expects($this->once())
			->method('collect')
			->with($this->isInstanceOf(ObjectEntity::class), null, true)
			->willReturn(['type' => 'FeatureCollection', 'features' => []]);

		$response = $this->controller->geoFeatures(
			'uuid-address',
			'3',
			'12',
			$this->objectService,
			$collector
		);

		$this->assertSame(200, $response->getStatus());
	}

	public function testTheFeatureEndpointRefusesAnObjectThatIsNotThere(): void {
		$this->objectService->method('find')->willReturn(null);

		$collector = $this->createMock(InheritedGeoCollector::class);
		$collector->expects($this->never())->method('collect');

		$response = $this->controller->geoFeatures(
			'uuid-gone',
			'3',
			'12',
			$this->objectService,
			$collector
		);

		$this->assertSame(404, $response->getStatus());
	}
}
