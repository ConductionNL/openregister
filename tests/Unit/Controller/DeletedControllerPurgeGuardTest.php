<?php

declare(strict_types=1);

/**
 * Purge-guard contract tests for DeletedController.
 *
 * `DELETE /api/deleted/{uuid}` permanently destroys a row. It is meant to
 * empty the trash, so it must be reachable for exactly one kind of object:
 * a soft-deleted row on a schema that does not declare
 * `x-openregister-archival`. These tests pin all three answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the trash-purge endpoints.
 */
class DeletedControllerPurgeGuardTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var DeletedController
	 */
	private DeletedController $controller;

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Object mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * User session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new DeletedController(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$this->userSession,
			$this->groupManager,
			$this->createMock(PermissionHandler::class)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
	}//end setUp()

	/**
	 * UUIDs the controller actually passed to the mapper's hard delete.
	 *
	 * A spy rather than a `never()` expectation: an unmet expectation raises
	 * inside the controller's own try/catch and surfaces as a 500, which hides
	 * the status code the test is really about.
	 *
	 * @var string[]
	 */
	private array $purged = [];

	/**
	 * Record every hard delete the controller performs.
	 *
	 * @return void
	 */
	private function spyOnPurges(): void {
		$this->objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->purged[] = (string)$entity->getUuid();

				return $entity;
			}
		);
	}//end spyOnPurges()

	/**
	 * Build a real Schema entity. `getSlug()` is a magic Entity accessor, so it
	 * cannot be stubbed on a mock — the schema has to be a real one.
	 *
	 * @param string $slug     The schema slug.
	 * @param bool   $archival Whether it declares x-openregister-archival.
	 *
	 * @return Schema The prepared schema.
	 */
	private function makeSchema(string $slug, bool $archival): Schema {
		$configuration = [];
		if ($archival === true) {
			$configuration = ['x-openregister-archival' => ['retention' => ['default' => 'P30D']]];
		}

		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end makeSchema()

	/**
	 * Build an object entity in a given lifecycle state.
	 *
	 * @param string $uuid       Object UUID.
	 * @param bool   $trashed    Whether the object carries deletion metadata.
	 * @param bool   $archival   Whether its schema declares x-openregister-archival.
	 *
	 * @return ObjectEntity The prepared entity.
	 */
	private function makeObject(string $uuid, bool $trashed, bool $archival): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('172');
		if ($trashed === true) {
			$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00', 'deletedBy' => 'admin']);
		}

		$this->schemaMapper->method('find')->willReturn($this->makeSchema(slug: 'case', archival: $archival));

		return $object;
	}//end makeObject()

	/**
	 * A live object is not in the trash, so purging it must be refused.
	 *
	 * @return void
	 */
	public function testPurgeRefusesALiveObject(): void {
		$object = $this->makeObject(uuid: 'live-1', trashed: false, archival: false);
		$this->objectMapper->method('find')->willReturn($object);
		$this->spyOnPurges();

		$response = $this->controller->destroy('live-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Object is not deleted', $response->getData()['error']);
		$this->assertSame([], $this->purged, 'a live object must never be purged');
	}//end testPurgeRefusesALiveObject()

	/**
	 * A live object on an archival schema is refused, and refused as archival:
	 * the sanctioned delete path answers 403 SCHEMA_ARCHIVAL_IMMUTABLE and this
	 * endpoint must not be a second door with a different answer.
	 *
	 * @return void
	 */
	public function testPurgeRefusesALiveArchivalObject(): void {
		$object = $this->makeObject(uuid: 'live-arch', trashed: false, archival: true);
		$this->objectMapper->method('find')->willReturn($object);
		$this->spyOnPurges();

		$response = $this->controller->destroy('live-arch');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', $response->getData()['error']);
		$this->assertSame([], $this->purged, 'an archival record must never be purged');
	}//end testPurgeRefusesALiveArchivalObject()

	/**
	 * A soft-deleted object on an archival schema is still legally retained:
	 * emptying the trash must not destroy it.
	 *
	 * @return void
	 */
	public function testPurgeRefusesATrashedArchivalObject(): void {
		$object = $this->makeObject(uuid: 'trash-arch', trashed: true, archival: true);
		$this->objectMapper->method('find')->willReturn($object);
		$this->spyOnPurges();

		$response = $this->controller->destroy('trash-arch');

		$this->assertSame(403, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', $data['error']);
		$this->assertSame('case', $data['schema']);
		$this->assertSame('purge', $data['operation']);
		$this->assertSame([], $this->purged, 'an archival record must never be purged');
	}//end testPurgeRefusesATrashedArchivalObject()

	/**
	 * Emptying the trash still works for an ordinary soft-deleted object.
	 *
	 * @return void
	 */
	public function testPurgeAllowsATrashedNonArchivalObject(): void {
		$object = $this->makeObject(uuid: 'trash-plain', trashed: true, archival: false);
		$this->objectMapper->method('find')->willReturn($object);
		$this->spyOnPurges();

		$response = $this->controller->destroy('trash-plain');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(['trash-plain'], $this->purged, 'emptying the trash must still work');
	}//end testPurgeAllowsATrashedNonArchivalObject()

	/**
	 * The bulk endpoint carries the same destructive power, so it carries the
	 * same three answers: a live row and an archival row are both refused, and
	 * only the ordinary trashed row is destroyed.
	 *
	 * @return void
	 */
	public function testPurgeMultipleRefusesLiveAndArchivalRows(): void {
		$live = new ObjectEntity();
		$live->setUuid('live-2');
		$live->setSchema('10');

		$archival = new ObjectEntity();
		$archival->setUuid('arch-2');
		$archival->setSchema('172');
		$archival->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);

		$trashed = new ObjectEntity();
		$trashed->setUuid('trash-2');
		$trashed->setSchema('10');
		$trashed->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);

		$plainSchema = $this->makeSchema(slug: 'document', archival: false);
		$archivalSchema = $this->makeSchema(slug: 'case', archival: true);

		$this->schemaMapper->method('find')->willReturnCallback(
			static function (int $id) use ($plainSchema, $archivalSchema): Schema {
				if ($id === 172) {
					return $archivalSchema;
				}

				return $plainSchema;
			}
		);

		$this->request->method('getParam')->willReturnMap(
			[['ids', [], ['live-2', 'arch-2', 'trash-2']]]
		);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')
			->willReturn([$live, $archival, $trashed]);
		$this->spyOnPurges();

		$response = $this->controller->destroyMultiple();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $data['deleted']);
		$this->assertSame(2, $data['failed']);
		$this->assertSame(['trash-2'], $this->purged, 'only the ordinary trashed row may be purged');
	}//end testPurgeMultipleRefusesLiveAndArchivalRows()
}//end class
