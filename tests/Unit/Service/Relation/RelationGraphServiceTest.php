<?php

declare(strict_types=1);

/*
 * RelationGraphService unit tests.
 *
 * The bound is the subject. A graph read that quietly returns part of the
 * answer is worse than one that refuses, so every test here asserts BOTH what
 * came back and what the answer says about itself: `truncated`, and which of
 * the two bounds stopped it. A test that only counted nodes would pass on a
 * walk that silently stopped early.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

namespace Unit\Service\Relation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Relation\RelationGraphService
 */
class RelationGraphServiceTest extends TestCase {
	/**
	 * Build a graph service over a fixed chain of objects.
	 *
	 * Each object references the next, so depth is the only thing that decides
	 * how far the walk gets.
	 *
	 * @param array<string, array<int, string>> $chain Uuid to the uuids it references.
	 * @param int $maxDepth The administered depth ceiling.
	 * @param int $maxNodes The administered node cap.
	 *
	 * @return RelationGraphService The service.
	 */
	private function service(
		array $chain,
		int $maxDepth = 3,
		int $maxNodes = 250,
		array $stored = [],
	): RelationGraphService {
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturnCallback(
			function (mixed $identifier) use ($chain): ObjectEntity {
				$uuid = (string)$identifier;
				if (isset($chain[$uuid]) === false) {
					throw new \RuntimeException('no such object');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setName('Object '.$uuid);
				$entity->setRegister(1);
				$entity->setSchema(2);
				$entity->setRelations(['volgende' => $chain[$uuid]]);

				return $entity;
			}
		);

		$relationMapper = $this->createMock(ObjectRelationMapper::class);
		$relationMapper->method('findTouching')->willReturnCallback(
			static function (array $uuids) use ($stored): array {
				return array_values(
					array_filter(
						$stored,
						static fn (ObjectRelation $row): bool => in_array(
							(string)$row->getSourceUuid(),
							$uuids,
							true
						)
					)
				);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => match ($key) {
				RelationGraphService::MAX_DEPTH_KEY => $maxDepth,
				RelationGraphService::MAX_NODES_KEY => $maxNodes,
				default => $default,
			}
		);

		return new RelationGraphService(
			objectEntityMapper: $objectMapper,
			relationMapper: $relationMapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			relationTypes: new RelationTypeResolver(),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Scenario: what is this case linked to.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testADepthTwoRequestReturnsTheObjectsAndTheirTypedEdges(): void {
		$service = $this->service(['a' => ['b'], 'b' => ['c'], 'c' => []]);

		$graph = $service->graph(rootUuid: 'a', depth: 2);

		$this->assertSame('a', $graph['root']);
		$this->assertSame(2, $graph['depth']);
		$this->assertSame(['a', 'b', 'c'], array_column($graph['nodes'], 'uuid'));
		$this->assertSame([0, 1, 2], array_column($graph['nodes'], 'distance'));

		$this->assertCount(2, $graph['edges']);
		$this->assertSame('a', $graph['edges'][0]['from']);
		$this->assertSame('b', $graph['edges'][0]['to']);
		$this->assertSame(RelationTypeResolver::DIRECTION_OUTGOING, $graph['edges'][0]['direction']);
		// No annotation on the property, so the edge reads as the property.
		$this->assertSame('volgende', $graph['edges'][0]['label']);

		$this->assertFalse($graph['truncated']);
		$this->assertNull($graph['truncatedBy']);
	}//end testADepthTwoRequestReturnsTheObjectsAndTheirTypedEdges()

	/**
	 * A walk that still had somewhere to go says so, even within the ceiling.
	 */
	public function testAWalkThatStillHadSomewhereToGoIsMarkedTruncated(): void {
		$service = $this->service(['a' => ['b'], 'b' => ['c'], 'c' => ['d'], 'd' => []]);

		$graph = $service->graph(rootUuid: 'a', depth: 1);

		$this->assertSame(['a', 'b'], array_column($graph['nodes'], 'uuid'));
		$this->assertTrue($graph['truncated']);
		$this->assertSame(RelationGraphService::TRUNCATED_DEPTH, $graph['truncatedBy']);
	}//end testAWalkThatStillHadSomewhereToGoIsMarkedTruncated()

	/**
	 * Scenario: a truncated graph says so.
	 *
	 * A request above the administered maximum is BOUNDED at the maximum and
	 * marked, rather than refused or silently answered at a smaller depth.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testARequestAboveTheMaximumIsBoundedAtItAndMarked(): void {
		$service = $this->service(
			['a' => ['b'], 'b' => ['c'], 'c' => ['d'], 'd' => ['e'], 'e' => []],
			maxDepth: 2
		);

		$graph = $service->graph(rootUuid: 'a', depth: 9);

		$this->assertSame(9, $graph['requestedDepth']);
		$this->assertSame(2, $graph['maxDepth']);
		$this->assertSame(2, $graph['depth']);
		$this->assertSame(['a', 'b', 'c'], array_column($graph['nodes'], 'uuid'));
		$this->assertTrue($graph['truncated']);
		$this->assertSame(RelationGraphService::TRUNCATED_DEPTH, $graph['truncatedBy']);
	}//end testARequestAboveTheMaximumIsBoundedAtItAndMarked()

	/**
	 * The node cap is named separately from the depth, because "there is no
	 * more" and "there is more and you were not shown it" are different
	 * answers.
	 */
	public function testTheNodeCapIsNamedAsTheReasonWhenItIsTheOneThatStops(): void {
		$service = $this->service(
			['a' => ['b'], 'b' => ['c'], 'c' => ['d'], 'd' => []],
			maxDepth: 5,
			maxNodes: 2
		);

		$graph = $service->graph(rootUuid: 'a', depth: 5);

		$this->assertCount(2, $graph['nodes']);
		$this->assertTrue($graph['truncated']);
		$this->assertSame(RelationGraphService::TRUNCATED_CAP, $graph['truncatedBy']);
	}//end testTheNodeCapIsNamedAsTheReasonWhenItIsTheOneThatStops()

	/**
	 * A cycle terminates rather than walking forever, and each edge appears
	 * once.
	 */
	public function testACycleTerminates(): void {
		$service = $this->service(['a' => ['b'], 'b' => ['a']], maxDepth: 5);

		$graph = $service->graph(rootUuid: 'a', depth: 5);

		$this->assertSame(['a', 'b'], array_column($graph['nodes'], 'uuid'));
		$this->assertCount(2, $graph['edges']);
	}//end testACycleTerminates()

	/**
	 * A node whose object cannot be read still appears, with `resolved` false.
	 * Dropping it would remove the edge that names it, and "the link is not
	 * there" is not the same answer as "you may not read what it points at".
	 */
	public function testAnUnreadableNodeStillAppears(): void {
		$service = $this->service(['a' => ['gone']]);

		$graph = $service->graph(rootUuid: 'a', depth: 1);

		$this->assertSame(['a', 'gone'], array_column($graph['nodes'], 'uuid'));
		$this->assertTrue($graph['nodes'][0]['resolved']);
		$this->assertFalse($graph['nodes'][1]['resolved']);
		$this->assertNull($graph['nodes'][1]['title']);
	}//end testAnUnreadableNodeStillAppears()

	/**
	 * An external address is a graph node with the title it was given.
	 *
	 * It is not an object, so loading it fails, and passing it through the
	 * object path would draw an anonymous node while the row was carrying a
	 * perfectly good title. The distinction matters because "a node whose
	 * object you may not read" and "a link to another system" are different
	 * answers to "what is this case linked to".
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnExternalAddressIsANamedGraphNode(): void {
		$external = new ObjectRelation();
		$external->hydrate(
			[
				'sourceUuid' => 'a',
				'targetUrl' => 'https://example.org/stcrt-2026-1',
				'targetTitle' => 'Publicatie in de Staatscourant',
				'kind' => ObjectRelation::KIND_EXTERNAL,
				'origin' => ObjectRelation::ORIGIN_MANUAL,
				'label' => 'gepubliceerd in',
			]
		);

		$graph = $this->service(['a' => []], stored: [$external])->graph(rootUuid: 'a', depth: 1);

		$node = null;
		foreach ($graph['nodes'] as $candidate) {
			if ($candidate['uuid'] === 'https://example.org/stcrt-2026-1') {
				$node = $candidate;
			}
		}

		$this->assertNotNull($node, 'the external address is not a node in the graph');
		$this->assertSame('Publicatie in de Staatscourant', $node['title']);
		$this->assertTrue($node['external']);
		$this->assertTrue($node['resolved']);

		// And the object node beside it is still marked as not external, so
		// the flag separates them rather than being decoration.
		$this->assertFalse($graph['nodes'][0]['external']);

		$edge = $graph['edges'][0];
		$this->assertSame('gepubliceerd in', $edge['label']);
		$this->assertSame(ObjectRelation::KIND_EXTERNAL, $edge['kind']);
	}//end testAnExternalAddressIsANamedGraphNode()

	/**
	 * The export is an edge list with a header, and the truncation marker
	 * rides along as a row so it survives a paste into a sheet.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testTheExportIsAnEdgeListThatCarriesItsTruncation(): void {
		$service = $this->service(['a' => ['b'], 'b' => ['c'], 'c' => []]);

		$export = $service->export(rootUuid: 'a', depth: 1);

		$this->assertSame(
			['from', 'fromTitle', 'relation', 'direction', 'to', 'toTitle', 'origin', 'distance'],
			$export['rows'][0]
		);
		$this->assertSame('a', $export['rows'][1][0]);
		$this->assertSame('Object a', $export['rows'][1][1]);
		$this->assertSame('volgende', $export['rows'][1][2]);
		$this->assertSame('b', $export['rows'][1][4]);

		$last = $export['rows'][count($export['rows']) - 1];
		$this->assertSame('truncated', $last[0]);
		$this->assertSame(RelationGraphService::TRUNCATED_DEPTH, $last[1]);
	}//end testTheExportIsAnEdgeListThatCarriesItsTruncation()

	/**
	 * A complete export carries no truncation row, so the marker means
	 * something when it is there.
	 */
	public function testACompleteExportCarriesNoTruncationRow(): void {
		$service = $this->service(['a' => ['b'], 'b' => []]);

		$export = $service->export(rootUuid: 'a', depth: 2);

		$this->assertFalse($export['graph']['truncated']);
		$this->assertCount(2, $export['rows']);
	}//end testACompleteExportCarriesNoTruncationRow()

	/**
	 * The administered bounds have floors: a zero or negative setting would
	 * otherwise make every graph empty and every answer truncated.
	 */
	public function testTheAdministeredBoundsHaveFloors(): void {
		$service = $this->service(['a' => []], maxDepth: 0, maxNodes: 0);

		$this->assertSame(1, $service->maximumDepth());
		$this->assertSame(1, $service->maximumNodes());
	}//end testTheAdministeredBoundsHaveFloors()
}//end class
