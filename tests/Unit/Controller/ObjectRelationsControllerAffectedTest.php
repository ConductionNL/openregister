<?php

declare(strict_types=1);

/**
 * The affected route answers who else a record reaches, for the caller.
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
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

namespace Unit\Controller;

use OCA\OpenRegister\Service\Relation\GraphExportOptions;
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
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectRelationsControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectRelationsController
 * @covers \OCA\OpenRegister\Service\Relation\AffectedSet
 * @covers \OCA\OpenRegister\Service\Relation\AffectedSetQuery
 * @uses \OCA\OpenRegister\Controller\Support\ObjectRelationsMappers
 * @uses \OCA\OpenRegister\Controller\Support\ObjectRelationsGraph
 * @uses \OCA\OpenRegister\Controller\Support\ObjectRelationsGuards
 * @uses \OCA\OpenRegister\Controller\Support\ObjectRelationsControllerServices
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Audit\PurposeRefusedException
 */
class ObjectRelationsControllerAffectedTest extends TestCase {

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

		$this->graphs = $this->createMock(RelationGraphService::class);
		$this->access = $this->createMock(ObjectReadAccess::class);
		$this->access->method('notReadable')->willReturn(new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404));
		$this->access->method('schemaOf')->willReturn(new Schema());
		$this->access->method('schemaById')->willReturnCallback(
			static function (string|int|null $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);

				return $schema;
			}
		);
		$this->purposeGuard = $this->createMock(PurposeGuard::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());

		return ObjectRelationsControllerFactory::create(
			appName: 'openregister',
			request: $request,
			relations: $this->createMock(ObjectRelationService::class),
			graphs: $this->graphs,
			objectService: $this->createMock(ObjectService::class),
			exportGate: $this->createMock(ExportGate::class),
			access: $this->access,
			trees: $this->createMock(ObjectTreeService::class),
			purposeGuard: $this->purposeGuard,
			registerMapper: $registerMapper
		);
	}//end controller()

	/**
	 * A readable root object.
	 *
	 * @return ObjectEntity The object.
	 */
	private function root(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case');
		$object->setRegister('1');
		$object->setSchema('2');

		return $object;
	}//end root()

	/**
	 * A graph node as the walk answers it.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $schema The schema id.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function node(string $uuid, string $schema): array {
		return [
			'uuid' => $uuid, 'title' => $uuid, 'register' => '1', 'schema' => $schema,
			'distance' => 1, 'resolved' => true, 'external' => false,
		];
	}//end node()

	/**
	 * A case referencing two objects, each referencing a party, plus an archival branch.
	 *
	 * @return array<string, mixed> The graph.
	 */
	private function caseGraph(): array {
		return [
			'root' => 'case',
			'nodes' => [
				$this->node('case', '2'), $this->node('permit', '3'), $this->node('plot', '3'),
				$this->node('owner', '5'), $this->node('tenant', '5'), $this->node('archive', '4'),
			],
			'edges' => [
				['from' => 'case', 'to' => 'permit', 'type' => 'betreft'],
				['from' => 'case', 'to' => 'plot', 'type' => 'betreft'],
				['from' => 'permit', 'to' => 'owner', 'type' => 'belanghebbende'],
				['from' => 'plot', 'to' => 'tenant', 'type' => 'belanghebbende'],
				['from' => 'case', 'to' => 'archive', 'type' => 'archief'],
			],
			'truncated' => false,
			'truncatedBy' => null,
		];
	}//end caseGraph()

	/**
	 * Both objects and both parties are returned, each with the path that reached it.
	 *
	 * @return void
	 */
	public function testWhoElseHearsAboutThisDecision(): void {
		$controller = $this->controller(params: ['types' => 'betreft, belanghebbende', 'partySchemas' => '5']);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->expects($this->once())->method('graph')->with('case')->willReturn($this->caseGraph());

		$response = $controller->affected(register: 'r', schema: 's', id: 'case');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['permit', 'plot'], array_column($data['objects'], 'uuid'));
		$this->assertSame(['owner', 'tenant'], array_column($data['parties'], 'uuid'));
		$this->assertSame(
			[['from' => 'case', 'to' => 'permit', 'type' => 'betreft'], ['from' => 'permit', 'to' => 'owner', 'type' => 'belanghebbende']],
			$data['parties'][0]['path']
		);
		$this->assertSame([], $data['pruned']);
	}//end testWhoElseHearsAboutThisDecision()

	/**
	 * The archive branch is excluded and the exclusion is reported with its type and node.
	 *
	 * @return void
	 */
	public function testThePrunedBranchIsReported(): void {
		$controller = $this->controller(params: ['prune' => ['archief']]);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->method('graph')->willReturn($this->caseGraph());

		$data = $controller->affected(register: 'r', schema: 's', id: 'case')->getData();

		$this->assertNotContains('archive', array_column($data['objects'], 'uuid'));
		$this->assertSame([['type' => 'archief', 'at' => 'case', 'to' => 'archive']], $data['pruned']);
	}//end testThePrunedBranchIsReported()

	/**
	 * Nothing excluded behaves as the unpruned walk and reports no exclusions.
	 *
	 * @return void
	 */
	public function testNothingExcludedEqualsTheUnprunedWalk(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->method('graph')->willReturn($this->caseGraph());

		$data = $controller->affected(register: 'r', schema: 's', id: 'case')->getData();

		$this->assertSame(['permit', 'plot', 'owner', 'tenant', 'archive'], array_column($data['objects'], 'uuid'));
		$this->assertSame([], $data['pruned']);
	}//end testNothingExcludedEqualsTheUnprunedWalk()

	/**
	 * A reachable record the caller may not read is absent from the answer.
	 *
	 * @return void
	 */
	public function testARecordTheCallerMayNotReadIsAbsent(): void {
		$graph = $this->caseGraph();
		$graph['nodes'][1] = ['uuid' => 'permit', 'title' => null, 'register' => null, 'schema' => null, 'distance' => 1, 'resolved' => false, 'external' => false];
		$controller = $this->controller(params: ['partySchemas' => '5']);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->method('graph')->willReturn($graph);

		$data = $controller->affected(register: 'r', schema: 's', id: 'case')->getData();

		$this->assertSame(['plot', 'archive'], array_column($data['objects'], 'uuid'));
		$this->assertSame(['tenant'], array_column($data['parties'], 'uuid'), 'the party only reachable through it is absent too');
		$this->assertStringNotContainsString('permit', (string)json_encode($data));
	}//end testARecordTheCallerMayNotReadIsAbsent()

	/**
	 * A node of a schema whose purpose was not named is masked with what hung on it.
	 *
	 * @return void
	 */
	public function testANodeOfARefusedPurposeIsMasked(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->method('graph')->willReturn($this->caseGraph());
		$this->purposeGuard->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema): ?string {
				if ($schema !== null && $schema->getId() === 4) {
					throw new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'A purpose is required.');
				}

				return null;
			}
		);

		$data = $controller->affected(register: 'r', schema: 's', id: 'case')->getData();

		$this->assertNotContains('archive', array_column($data['objects'], 'uuid'));
		$this->assertContains('permit', array_column($data['objects'], 'uuid'));
	}//end testANodeOfARefusedPurposeIsMasked()

	/**
	 * A truncated walk gives a truncated answer.
	 *
	 * @return void
	 */
	public function testATruncatedAnswerSaysSo(): void {
		$graph = $this->caseGraph();
		$graph['truncated'] = true;
		$graph['truncatedBy'] = 'depth';
		$controller = $this->controller(params: ['depth' => '9']);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->expects($this->once())->method('graph')->with('case', $this->callback(static fn (GraphExportOptions $options): bool => $options->depth === 9))->willReturn($graph);

		$data = $controller->affected(register: 'r', schema: 's', id: 'case')->getData();

		$this->assertTrue($data['truncated']);
		$this->assertSame('depth', $data['truncatedBy']);
	}//end testATruncatedAnswerSaysSo()

	/**
	 * An empty type list is refused with 400 and nothing is walked.
	 *
	 * @return void
	 */
	public function testAnEmptyTypeListIsRefused(): void {
		$controller = $this->controller(params: ['types' => '']);
		$this->access->method('readable')->willReturn($this->root());
		$this->graphs->expects($this->never())->method('graph');

		$this->assertSame(400, $controller->affected(register: 'r', schema: 's', id: 'case')->getStatus());
	}//end testAnEmptyTypeListIsRefused()

	/**
	 * A root the caller may not read is a 404, before the purpose guard and the walk.
	 *
	 * @return void
	 */
	public function testAnUnreadableRootIsNotFoundAndNotWalked(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn(null);
		$this->purposeGuard->expects($this->never())->method('enforce');
		$this->graphs->expects($this->never())->method('graph');

		$this->assertSame(404, $controller->affected(register: 'r', schema: 's', id: 'case')->getStatus());
	}//end testAnUnreadableRootIsNotFoundAndNotWalked()

	/**
	 * A refused purpose on the root answers its status and nothing is walked.
	 *
	 * @return void
	 */
	public function testARefusedPurposeOnTheRootIsNotWalked(): void {
		$controller = $this->controller(params: []);
		$this->access->method('readable')->willReturn($this->root());
		$this->purposeGuard->method('enforce')
			->willThrowException(new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'A purpose is required.'));
		$this->graphs->expects($this->never())->method('graph');

		$this->assertSame(403, $controller->affected(register: 'r', schema: 's', id: 'case')->getStatus());
	}//end testARefusedPurposeOnTheRootIsNotWalked()
}//end class
