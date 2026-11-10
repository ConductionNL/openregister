<?php

/**
 * Golden for the route address of ObjectRelationsController's row routes.
 *
 * Written against the controller before its route actions were thinned to
 * ObjectRef delegates: index, removeReferences and removeLink read exactly the
 * object their route names, and an object the caller may not read is the
 * same 404 with nothing listed, withdrawn or removed. removeLink also removes
 * only a row the object owns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectRelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectRelationsControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectRelationsController
 */
class ObjectRelationsRowRoutesAddressGoldenTest extends TestCase {

	/**
	 * The row store double.
	 *
	 * @var ObjectRelationService&MockObject
	 */
	private ObjectRelationService&MockObject $relations;

	/**
	 * The graph walk double.
	 *
	 * @var RelationGraphService&MockObject
	 */
	private RelationGraphService&MockObject $graphs;

	/**
	 * The object write double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * Every read the controller asked for, as "register/schema/id".
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Build the controller over a request and the addresses the caller may read.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param array<int, string>   $readable  The "register/schema/id" addresses the caller may read.
	 *
	 * @return ObjectRelationsController The controller.
	 */
	private function controller(array $params, array $readable): ObjectRelationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			function (string $register, string $schema, string $id) use ($readable): ?ObjectEntity {
				$address = $register.'/'.$schema.'/'.$id;
				$this->reads[] = $address;
				if (in_array($address, $readable, true) === false) {
					return null;
				}

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
		$this->relations->method('render')->willReturnCallback(
			static fn (ObjectRelation $row, string $direction, string $language): array => ['uuid' => $row->getUuid()]
		);
		$this->graphs = $this->createMock(RelationGraphService::class);
		$this->objects = $this->createMock(ObjectService::class);

		return ObjectRelationsControllerFactory::create(
			appName: 'openregister',
			request: $request,
			relations: $this->relations,
			graphs: $this->graphs,
			objectService: $this->objects,
			exportGate: $this->createMock(ExportGate::class),
			access: $access,
			trees: $this->createMock(ObjectTreeService::class),
			purposeGuard: $this->createMock(PurposeGuard::class),
			registerMapper: $registerMapper
		);
	}//end controller()

	public function testIndexListsTheRowsOfTheRoutedObject(): void {
		$controller = $this->controller(params: [], readable: ['zaken/zaak/o-1']);
		$this->relations->expects($this->once())->method('relationsFor')->with('o-1')->willReturn([['uuid' => 'row-1']]);

		$response = $controller->index('zaken', 'zaak', 'o-1');

		$this->assertSame([200, ['results' => [['uuid' => 'row-1']], 'total' => 1]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['zaken/zaak/o-1'], $this->reads);
	}

	public function testIndexOfAnUnreadableObjectIsA404WithNothingListed(): void {
		$controller = $this->controller(params: [], readable: ['zaken/zaak/o-1']);
		$this->relations->expects($this->never())->method('relationsFor');

		$this->assertSame(404, $controller->index('zaak', 'zaken', 'o-1')->getStatus());
		$this->assertSame(['zaak/zaken/o-1'], $this->reads);
	}

	public function testRemoveReferencesWithdrawsTheRoutedObjectsAnchor(): void {
		$controller = $this->controller(params: [], readable: ['zaken/zaak/o-1']);
		$this->relations->expects($this->once())->method('withdrawProseReferences')->with('o-1', 'a-9')->willReturn(2);

		$response = $controller->removeReferences('zaken', 'zaak', 'o-1', 'a-9');

		$this->assertSame([200, ['removed' => 2]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['zaken/zaak/o-1'], $this->reads);
	}

	public function testRemoveReferencesOnAnUnreadableObjectIsA404WithNothingWithdrawn(): void {
		$controller = $this->controller(params: [], readable: []);
		$this->relations->expects($this->never())->method('withdrawProseReferences');

		$this->assertSame(404, $controller->removeReferences('zaken', 'zaak', 'o-1', 'a-9')->getStatus());
		$this->assertSame(['zaken/zaak/o-1'], $this->reads);
	}

	public function testRemoveLinkRemovesARowTheRoutedObjectOwns(): void {
		$controller = $this->controller(params: [], readable: ['zaken/zaak/o-1']);
		$this->relations->method('relationsFor')->with('o-1')->willReturn([['uuid' => 'row-1']]);
		$this->relations->expects($this->once())->method('remove')->with('row-1')->willReturn(true);

		$response = $controller->removeLink('zaken', 'zaak', 'o-1', 'row-1');

		$this->assertSame([200, ['removed' => 'row-1']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['zaken/zaak/o-1'], $this->reads);
	}

	public function testRemoveLinkOfARowTheObjectDoesNotOwnIsA404WithNothingRemoved(): void {
		$controller = $this->controller(params: [], readable: ['zaken/zaak/o-1']);
		$this->relations->method('relationsFor')->willReturn([['uuid' => 'row-1']]);
		$this->relations->expects($this->never())->method('remove');

		$response = $controller->removeLink('zaken', 'zaak', 'o-1', 'row-2');

		$this->assertSame([404, ['error' => 'No such relation on this object']], [$response->getStatus(), $response->getData()]);
	}

	public function testRemoveLinkOnAnUnreadableObjectIsA404WithNothingRemoved(): void {
		$controller = $this->controller(params: [], readable: []);
		$this->relations->expects($this->never())->method('remove');

		$this->assertSame(404, $controller->removeLink('zaken', 'zaak', 'o-1', 'row-1')->getStatus());
		$this->assertSame(['zaken/zaak/o-1'], $this->reads);
	}
}
