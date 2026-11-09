<?php

/**
 * Characterisation of which object the relation routes read, and refuse.
 *
 * Pins, before the route address travels as one value, that every read goes
 * out with the register, schema and id the route names, for the object a
 * route works on and for the far end of a prose reference. A read under any
 * other address refuses, and a refused caller never reaches the write or the
 * walk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
class ObjectRelationsControllerRouteAddressTest extends TestCase {

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

	/**
	 * A prose reference reads both ends under the route's register and schema, the far end by its target.
	 *
	 * @return void
	 */
	public function testAProseReferenceReadsBothEndsUnderTheRouteAddress(): void {
		$controller = $this->controller(params: ['target' => 'b', 'anchor' => 'p1'], readable: ['r/s/a', 'r/s/b']);
		$row = new ObjectRelation();
		$row->setUuid('out');
		$this->relations->expects($this->once())->method('recordProseReference')
			->with('a', 'b', 'p1', null, $this->anything())
			->willReturn([$row]);

		$response = $controller->addLink('r', 's', 'a');

		$this->assertSame([201, ['results' => [['uuid' => 'out']], 'total' => 1]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['r/s/a', 'r/s/b'], $this->reads);
	}//end testAProseReferenceReadsBothEndsUnderTheRouteAddress()

	/**
	 * A far end readable only under another address is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAFarEndReadableOnlyElsewhereIsRefused(): void {
		$controller = $this->controller(params: ['target' => 'b', 'anchor' => 'p1'], readable: ['r/s/a', 's/r/b', 'x/s/b']);
		$this->relations->expects($this->never())->method('recordProseReference');

		$response = $controller->addLink('r', 's', 'a');

		$this->assertSame([404, ['error' => 'Object not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['r/s/a', 'r/s/b'], $this->reads);
	}//end testAFarEndReadableOnlyElsewhereIsRefused()

	/**
	 * A prose reference without an anchor reads only the route's own object.
	 *
	 * @return void
	 */
	public function testAProseReferenceWithoutAnAnchorNeverReadsTheFarEnd(): void {
		$controller = $this->controller(params: ['target' => 'b'], readable: ['r/s/a', 'r/s/b']);
		$this->relations->expects($this->never())->method('recordProseReference');

		$this->assertSame(422, $controller->addLink('r', 's', 'a')->getStatus());
		$this->assertSame(['r/s/a'], $this->reads);
	}//end testAProseReferenceWithoutAnAnchorNeverReadsTheFarEnd()

	/**
	 * The graph reads its root at the route address; the same id elsewhere is refused and never walked.
	 *
	 * @return void
	 */
	public function testTheGraphReadsItsRootAtTheRouteAddress(): void {
		$refused = $this->controller(params: [], readable: ['r/s/a']);
		$this->graphs->expects($this->never())->method('graph');
		$this->assertSame([404, ['error' => 'Object not found']], [$refused->graph('s', 'r', 'a')->getStatus(), $refused->graph('s', 'r', 'a')->getData()]);
		$this->assertSame(['s/r/a', 's/r/a'], $this->reads);

		$this->reads = [];
		$allowed = $this->controller(params: [], readable: ['r/s/a']);
		$this->graphs->expects($this->once())->method('graph')->willReturn(['root' => 'a', 'nodes' => [], 'edges' => []]);
		$this->assertSame(200, $allowed->graph('r', 's', 'a')->getStatus());
		$this->assertSame(['r/s/a'], $this->reads);
	}//end testTheGraphReadsItsRootAtTheRouteAddress()

	/**
	 * The tree, the affected set and the export read their root at the route address too.
	 *
	 * @return void
	 */
	public function testTheTreeAffectedAndExportReadTheirRootAtTheRouteAddress(): void {
		$controller = $this->controller(params: [], readable: []);

		$this->assertSame(404, $controller->tree('r', 's', 'a')->getStatus());
		$this->assertSame(404, $controller->affected('r', 's', 'a')->getStatus());
		$this->assertSame(404, $controller->exportGraph('r', 's', 'a')->getStatus());
		$this->assertSame(['r/s/a', 'r/s/a', 'r/s/a'], $this->reads);
	}//end testTheTreeAffectedAndExportReadTheirRootAtTheRouteAddress()

	/**
	 * A derive saves the new object into the route's register and schema.
	 *
	 * @return void
	 */
	public function testADeriveSavesIntoTheRouteRegisterAndSchema(): void {
		$controller = $this->controller(params: ['object' => ['t' => 1]], readable: ['r/s/a']);
		$this->relations->method('applyInheritance')->willReturn(['data' => ['t' => 1], 'inherited' => []]);
		$this->objects->expects($this->once())->method('saveObject')->with(['t' => 1], [], 'r', 's')
			->willThrowException(new \Exception('refused'));

		$response = $controller->derive('r', 's', 'a');

		$this->assertSame([422, ['error' => 'refused']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['r/s/a'], $this->reads);
	}//end testADeriveSavesIntoTheRouteRegisterAndSchema()
}//end class
