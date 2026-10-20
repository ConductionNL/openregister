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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
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
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Audit\PurposeRefusedException
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
	 * The graph walk double.
	 *
	 * @var RelationGraphService&MockObject
	 */
	private RelationGraphService&MockObject $graphs;

	/**
	 * The doelbinding guard double.
	 *
	 * @var PurposeGuard&MockObject
	 */
	private PurposeGuard&MockObject $purposeGuard;

	/**
	 * An object as the access check hands it over: it knows its register and schema.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('1');
		$object->setSchema('2');

		return $object;
	}//end object()

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
		$this->graphs = $this->createMock(RelationGraphService::class);
		$this->access = $this->createMock(ObjectReadAccess::class);
		$this->access->method('notReadable')->willReturn(new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404));
		$this->access->method('schemaOf')->willReturn(new Schema());
		$this->purposeGuard = $this->createMock(PurposeGuard::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());

		return new ObjectRelationsController(
			appName: 'openregister',
			request: $request,
			relations: $this->createMock(ObjectRelationService::class),
			graphs: $this->graphs,
			objectService: $this->createMock(ObjectService::class),
			exportGate: $this->createMock(ExportGate::class),
			access: $this->access,
			trees: $this->trees,
			purposeGuard: $this->purposeGuard,
			registerMapper: $registerMapper
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
		$this->access->method('readable')->willReturn($this->object());
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
		$root = $this->object();
		$this->access->method('readable')->willReturn($root);
		$this->trees->expects($this->once())->method('tree')
			->with($root, 'both', 2, ['kvkNumber', 'tradeName'])
			->willReturn(['nodes' => []]);

		$response = $controller->tree(register: 'r', schema: 's', id: 'x');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['nodes' => []], $response->getData());
	}//end testTheQueryReachesTheWalk()

	/**
	 * A schema that asks for a declared purpose refuses the tree like the object GET, and nothing is walked.
	 *
	 * @return void
	 */
	public function testARefusedPurposeAnswersItsStatusAndNothingIsWalked(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->object());
		$this->purposeGuard->expects($this->once())->method('enforce')
			->willThrowException(new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'A purpose is required.'));
		$this->trees->expects($this->never())->method('tree');

		$response = $controller->tree(register: 'r', schema: 's', id: 'x');

		$this->assertSame(403, $response->getStatus());
	}//end testARefusedPurposeAnswersItsStatusAndNothingIsWalked()

	/**
	 * The purpose check runs after the read check: an unreadable root never reaches the guard.
	 *
	 * @return void
	 */
	public function testAnUnreadableRootNeverReachesThePurposeGuard(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn(null);
		$this->purposeGuard->expects($this->never())->method('enforce');

		$this->assertSame(404, $controller->tree(register: 'r', schema: 's', id: 'x')->getStatus());
	}//end testAnUnreadableRootNeverReachesThePurposeGuard()

	/**
	 * Without a depth the walk gets null, which means the schema's own bound.
	 *
	 * @return void
	 */
	public function testNoDepthMeansTheDeclaredBound(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->object());
		$this->trees->expects($this->once())->method('tree')
			->with($this->anything(), 'descendants', null, [])
			->willReturn([]);

		$controller->tree(register: 'r', schema: 's', id: 'x');
	}//end testNoDepthMeansTheDeclaredBound()

	/**
	 * A graph node of a schema whose purpose was not named is left out with its edges and what hung on it.
	 *
	 * @return void
	 */
	public function testAGraphNodeOfARefusedSchemaIsMaskedWithItsSubtree(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->object());
		$node = static fn (string $uuid, string $schema): array => [
			'uuid' => $uuid, 'title' => $uuid, 'register' => '1', 'schema' => $schema,
			'distance' => 1, 'resolved' => true, 'external' => false,
		];
		$this->graphs->method('graph')->willReturn(
			[
				'root' => 'root',
				'nodes' => [$node('root', '2'), $node('open', '2'), $node('secret', '9'), $node('behind', '2')],
				'edges' => [
					['from' => 'root', 'to' => 'open', 'direction' => 'outgoing'],
					['from' => 'root', 'to' => 'secret', 'direction' => 'outgoing'],
					['from' => 'secret', 'to' => 'behind', 'direction' => 'outgoing'],
				],
			]
		);
		$this->access->method('schemaById')->willReturnCallback(
			static function (string|int|null $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);

				return $schema;
			}
		);
		$this->purposeGuard->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema): ?string {
				if ($schema !== null && $schema->getId() === 9) {
					throw new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'A purpose is required.');
				}

				return null;
			}
		);

		$data = $controller->graph(register: 'r', schema: 's', id: 'x')->getData();

		$this->assertSame(['root', 'open'], array_column($data['nodes'], 'uuid'));
		$this->assertCount(1, $data['edges']);
		$this->assertSame('open', $data['edges'][0]['to']);
	}//end testAGraphNodeOfARefusedSchemaIsMaskedWithItsSubtree()

	/**
	 * The CSV export of the graph leaves out the same nodes as the graph answer: no title or uuid of a node of a
	 * schema whose purpose was not named, nor of what hung on it.
	 *
	 * @return void
	 */
	public function testTheGraphExportLeavesOutANodeOfARefusedSchema(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->object());
		$node = static fn (string $uuid, string $schema): array => [
			'uuid' => $uuid, 'title' => 'title-'.$uuid, 'register' => '1', 'schema' => $schema,
			'distance' => 1, 'resolved' => true, 'external' => false,
		];
		$this->graphs->method('graph')->willReturn(
			[
				'root' => 'root',
				'nodes' => [$node('root', '2'), $node('open', '2'), $node('secret', '9'), $node('behind', '2')],
				'edges' => [
					['from' => 'root', 'to' => 'open', 'direction' => 'outgoing'],
					['from' => 'root', 'to' => 'secret', 'direction' => 'outgoing'],
					['from' => 'secret', 'to' => 'behind', 'direction' => 'outgoing'],
				],
				'truncated' => false,
			]
		);
		$rows = (new \ReflectionClass(RelationGraphService::class))->newInstanceWithoutConstructor();
		$this->graphs->method('rowsOf')->willReturnCallback(static fn (array $graph): array => $rows->rowsOf(graph: $graph));
		$this->access->method('schemaById')->willReturnCallback(
			static function (string|int|null $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);

				return $schema;
			}
		);
		$this->purposeGuard->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema): ?string {
				if ($schema !== null && $schema->getId() === 9) {
					throw new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'A purpose is required.');
				}

				return null;
			}
		);

		$csv = (string)$controller->exportGraph(register: 'r', schema: 's', id: 'x')->render();

		$this->assertStringContainsString('title-open', $csv);
		$this->assertStringNotContainsString('secret', $csv);
		$this->assertStringNotContainsString('behind', $csv);
	}//end testTheGraphExportLeavesOutANodeOfARefusedSchema()

	/**
	 * A graph whose schemas are all admitted is answered unchanged.
	 *
	 * @return void
	 */
	public function testAGraphOfAdmittedSchemasIsUnchanged(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->object());
		$this->access->method('schemaById')->willReturn(new Schema());
		$graph = [
			'root' => 'root',
			'nodes' => [['uuid' => 'root', 'register' => '1', 'schema' => '2', 'resolved' => true, 'external' => false]],
			'edges' => [],
		];
		$this->graphs->method('graph')->willReturn($graph);

		$this->assertSame($graph, $controller->graph(register: 'r', schema: 's', id: 'x')->getData());
	}//end testAGraphOfAdmittedSchemasIsUnchanged()
}
