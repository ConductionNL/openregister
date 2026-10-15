<?php

declare(strict_types=1);

/**
 * The tree route resolves its root under the caller's RBAC before it walks.
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
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectRelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectRelationsController
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectRelationsControllerTreeTest extends TestCase {

	/**
	 * The tree walk double.
	 *
	 * @var ObjectTreeService&MockObject
	 */
	private ObjectTreeService&MockObject $trees;

	/**
	 * The access double.
	 *
	 * @var ObjectReadAccess&MockObject
	 */
	private ObjectReadAccess&MockObject $access;

	/**
	 * Build the controller over a set of query parameters.
	 *
	 * @param array<string, mixed> $params The query.
	 *
	 * @return ObjectRelationsController The controller.
	 */
	private function controller(array $params): ObjectRelationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$this->trees = $this->createMock(ObjectTreeService::class);
		$this->access = $this->createMock(ObjectReadAccess::class);
		$this->access->method('notReadable')->willReturn(new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404));

		return new ObjectRelationsController(
			appName: 'openregister',
			request: $request,
			relations: $this->createMock(ObjectRelationService::class),
			graphs: $this->createMock(RelationGraphService::class),
			objectService: $this->createMock(ObjectService::class),
			exportGate: $this->createMock(ExportGate::class),
			access: $this->access,
			trees: $this->trees
		);
	}//end controller()

	/**
	 * A root the caller may not read is a 404 and nothing is walked.
	 *
	 * @return void
	 */
	public function testAnUnreadableRootIsNotFoundAndNotWalked(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn(null);
		$this->trees->expects($this->never())->method('tree');

		$this->assertSame(404, $controller->tree(register: 'r', schema: 's', id: 'x')->getStatus());
	}//end testAnUnreadableRootIsNotFoundAndNotWalked()

	/**
	 * An unknown direction is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownDirectionIsRefused(): void {
		$controller = $this->controller(params: ['direction' => 'sideways']);
		$this->access->method('readable')->willReturn(new ObjectEntity());
		$this->trees->expects($this->never())->method('tree');

		$this->assertSame(400, $controller->tree(register: 'r', schema: 's', id: 'x')->getStatus());
	}//end testAnUnknownDirectionIsRefused()

	/**
	 * The query reaches the walk, with RBAC left on.
	 *
	 * @return void
	 */
	public function testTheQueryReachesTheWalk(): void {
		$controller = $this->controller(params: ['direction' => 'both', 'depth' => '2', 'fields' => 'kvkNumber, tradeName']);
		$root = new ObjectEntity();
		$this->access->method('readable')->willReturn($root);
		$this->trees->expects($this->once())->method('tree')
			->with($root, 'both', 2, ['kvkNumber', 'tradeName'])
			->willReturn(['nodes' => []]);

		$response = $controller->tree(register: 'r', schema: 's', id: 'x');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['nodes' => []], $response->getData());
	}//end testTheQueryReachesTheWalk()

	/**
	 * Without a depth the walk gets null, which means the schema's own bound.
	 *
	 * @return void
	 */
	public function testNoDepthMeansTheDeclaredBound(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn(new ObjectEntity());
		$this->trees->expects($this->once())->method('tree')
			->with($this->anything(), 'descendants', null, [])
			->willReturn([]);

		$controller->tree(register: 'r', schema: 's', id: 'x');
	}//end testNoDepthMeansTheDeclaredBound()
}//end class
