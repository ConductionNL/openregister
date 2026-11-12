<?php

/**
 * ObjectRelationsController derivation and prose-reference hand-off tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ObjectRelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationEndpoint;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectRelationsControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A derived object is saved into the register and schema of the route it was
 * derived under (ObjectDerivation), and a prose reference hands the service
 * the read source, the read far end and the anchor (ProseReference).
 */
class ObjectRelationsControllerDerivationTest extends TestCase {

	/**
	 * The relation service double.
	 *
	 * @var ObjectRelationService&MockObject
	 */
	private ObjectRelationService&MockObject $relations;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * A controller over the given request parameters where every object is readable.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ObjectRelationsController
	 */
	private function controller(array $params): ObjectRelationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			static function (string $register, string $schema, string $id): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid($id);
				$object->setRegister('3');
				$object->setSchema('4');
				return $object;
			}
		);
		$access->method('notReadable')->willReturn(new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404));
		$access->method('schemaOf')->willReturn(new Schema());
		$access->method('registerIdOf')->willReturn(1);
		$access->method('schemaById')->willReturn(new Schema());

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());

		$this->relations = $this->createMock(ObjectRelationService::class);
		$this->relations->method('applyInheritance')->willReturnCallback(
			static fn (ObjectEntity $parent, array $childData, array $inherits): array => ['data' => $childData, 'inherited' => []]
		);
		$this->objects = $this->createMock(ObjectService::class);

		return ObjectRelationsControllerFactory::create(
			appName: 'openregister',
			request: $request,
			relations: $this->relations,
			graphs: $this->createMock(RelationGraphService::class),
			objectService: $this->objects,
			exportGate: $this->createMock(ExportGate::class),
			access: $access,
			trees: $this->createMock(ObjectTreeService::class),
			purposeGuard: $this->createMock(PurposeGuard::class),
			registerMapper: $registerMapper
		);
	}//end controller()

	/**
	 * The derived object is saved under the route's register and schema; a save
	 * failure answers 422 and records no provenance.
	 *
	 * @return void
	 */
	public function testTheDerivedObjectIsSavedUnderTheRouteAddress(): void {
		$controller = $this->controller(['object' => ['title' => 'Kopie']]);
		$saved      = [];
		$this->objects->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ?array $extend=[], mixed $register=null, mixed $schema=null) use (&$saved): ObjectEntity {
				$saved[] = [$object, $register, $schema];
				throw new Exception('title is not unique');
			}
		);
		$this->relations->expects($this->never())->method('recordDerivation');
		$this->relations->expects($this->never())->method('recordSplit');

		$response = $controller->derive('zaken', 'melding', 'src-1');

		$this->assertSame([[['title' => 'Kopie'], 'zaken', 'melding']], $saved);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'title is not unique'], $response->getData());
	}//end testTheDerivedObjectIsSavedUnderTheRouteAddress()

	/**
	 * A prose reference hands over both read ends and the anchor; with no new row it answers 200.
	 *
	 * @return void
	 */
	public function testAProseReferenceHandsOverBothEndsAndTheAnchor(): void {
		$controller = $this->controller(['target' => 'far-1', 'anchor' => 'paragraph-2']);
		$seen       = [];
		$this->relations->method('recordProseReference')->willReturnCallback(
			static function (RelationEndpoint $source, RelationEndpoint $far, string $anchor) use (&$seen): array {
				$seen = [$source->uuid, $far->uuid, $anchor];
				return [];
			}
		);

		$response = $controller->addLink('zaken', 'melding', 'src-1');

		$this->assertSame(['src-1', 'far-1', 'paragraph-2'], $seen);
		$this->assertSame(200, $response->getStatus(), 'no new row: the mention was already recorded');
	}//end testAProseReferenceHandsOverBothEndsAndTheAnchor()
}//end class
