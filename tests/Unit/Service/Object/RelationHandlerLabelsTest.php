<?php

declare(strict_types=1);

/*
 * RelationHandler label-enrichment unit tests.
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DOES NOT.
 *
 * It proves the two pieces the enrichment actually turns on: that a uuid is
 * mapped back to the property path it came in through, including through an
 * array and through a reference stored as a URL, and that the descriptor
 * attached to a row carries the label for THAT direction. Both are asserted on
 * the exact string, never on "a label came back": an enrichment that put the
 * near label on the reverse side would satisfy the weaker assertion and be
 * exactly the bug the change exists to end.
 *
 * It does NOT drive getUses() or getUsedBy() end to end. Both reach for
 * \OC::$server to resolve the magic tables, which a unit run has no answer
 * for -- the existing RelationHandlerTest cases around them all land on the
 * error path for that reason. The wiring from those two methods into these
 * helpers is covered by tests/e2e/ci/relation-types.spec.ts over HTTP, named
 * here so nobody has to take this comment's word for it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 */
class RelationHandlerLabelsTest extends TestCase {
	private RelationHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new RelationHandler(
			objectEntityMapper: $this->createMock(MagicMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			performanceHandler: $this->createMock(PerformanceHandler::class),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			logger: $this->createMock(LoggerInterface::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			relationTypes: new RelationTypeResolver()
		);
	}//end setUp()

	/**
	 * Call one of the handler's private enrichment helpers.
	 *
	 * @param string $method The method name.
	 * @param array<string, mixed> $arguments Its named arguments.
	 *
	 * @return mixed Whatever it returned.
	 */
	private function call(string $method, array $arguments): mixed {
		$reflection = new ReflectionClass(RelationHandler::class);
		$callable = $reflection->getMethod($method);
		$callable->setAccessible(true);

		return $callable->invokeArgs($this->handler, $arguments);
	}//end call()

	/**
	 * A schema declaring `blokkeert` as a typed relation.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setProperties(
			[
				'blokkeert' => [
					'type' => 'array',
					'items' => [
						'$ref' => 'cases',
						'x-openregister-relation' => [
							'label' => 'blocks',
							'inverseLabel' => 'blocked by',
						],
					],
				],
				'owner' => ['$ref' => 'people', 'title' => 'Eigenaar'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A single-valued reference is found under its own property name.
	 */
	public function testASingleReferenceIsFoundUnderItsProperty(): void {
		$this->assertSame(
			'owner',
			$this->call('pathHolding', ['relations' => ['owner' => 'uuid-o'], 'uuid' => 'uuid-o'])
		);
	}//end testASingleReferenceIsFoundUnderItsProperty()

	/**
	 * An array reference keeps its index, and the index is not the property.
	 */
	public function testAnArrayReferenceKeepsItsIndex(): void {
		$path = $this->call(
			'pathHolding',
			['relations' => ['blokkeert.0' => 'uuid-a', 'blokkeert.1' => 'uuid-b'], 'uuid' => 'uuid-b']
		);

		$this->assertSame('blokkeert.1', $path);
		$this->assertSame('blokkeert', RelationTypeResolver::propertyNameOf(path: $path));
	}//end testAnArrayReferenceKeepsItsIndex()

	/**
	 * A relation map whose values are lists rather than flattened paths still
	 * resolves, because both shapes exist in stored data.
	 */
	public function testAListValuedRelationMapResolves(): void {
		$this->assertSame(
			'blokkeert.1',
			$this->call(
				'pathHolding',
				['relations' => ['blokkeert' => ['uuid-a', 'uuid-b']], 'uuid' => 'uuid-b']
			)
		);
	}//end testAListValuedRelationMapResolves()

	/**
	 * A reference stored as a URL is matched on the uuid inside it, since
	 * `$ref` accepts a URI and the reverse lookup only ever holds the uuid.
	 */
	public function testAReferenceStoredAsAUrlIsMatched(): void {
		$this->assertSame(
			'owner',
			$this->call(
				'pathHolding',
				[
					'relations' => ['owner' => 'https://example.org/api/objects/1/2/uuid-o'],
					'uuid' => 'uuid-o',
				]
			)
		);
	}//end testAReferenceStoredAsAUrlIsMatched()

	/**
	 * A uuid nothing holds resolves to nothing rather than to the first path.
	 */
	public function testAUuidNothingHoldsResolvesToNull(): void {
		$this->assertNull(
			$this->call('pathHolding', ['relations' => ['owner' => 'uuid-o'], 'uuid' => 'uuid-x'])
		);
		$this->assertNull(
			$this->call('pathHolding', ['relations' => ['owner' => 'uuid-o'], 'uuid' => ''])
		);
	}//end testAUuidNothingHoldsResolvesToNull()

	/**
	 * Task 2.1: an outgoing row reads the near label.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnOutgoingRowReadsTheNearLabel(): void {
		$row = $this->call(
			'withRelation',
			[
				'row' => ['id' => 'uuid-b'],
				'schema' => $this->schema(),
				'path' => 'blokkeert.0',
				'direction' => RelationTypeResolver::DIRECTION_OUTGOING,
			]
		);

		$this->assertSame('blokkeert', $row['relation']['property']);
		$this->assertSame('blocks', $row['relation']['displayLabel']);
		$this->assertSame('blocked by', $row['relation']['inverseLabel']);
		$this->assertSame('outgoing', $row['relation']['direction']);
	}//end testAnOutgoingRowReadsTheNearLabel()

	/**
	 * Task 2.2 and the spec's "the far side reads blocked by" scenario.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnIncomingRowReadsTheInverseLabel(): void {
		$row = $this->call(
			'withRelation',
			[
				'row' => ['id' => 'uuid-a'],
				'schema' => $this->schema(),
				'path' => 'blokkeert.0',
				'direction' => RelationTypeResolver::DIRECTION_INCOMING,
			]
		);

		$this->assertSame('blokkeert', $row['relation']['property']);
		$this->assertSame('blocked by', $row['relation']['displayLabel']);
		$this->assertSame('blocks', $row['relation']['label']);
		$this->assertSame('incoming', $row['relation']['direction']);
	}//end testAnIncomingRowReadsTheInverseLabel()

	/**
	 * The spec's "an unannotated reference still reads" scenario, on the
	 * reverse side where the fallback actually shows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnUnannotatedReferenceReadsAsItsTitleAndReferencedBy(): void {
		$row = $this->call(
			'withRelation',
			[
				'row' => ['id' => 'uuid-p'],
				'schema' => $this->schema(),
				'path' => 'owner',
				'direction' => RelationTypeResolver::DIRECTION_INCOMING,
			]
		);

		$this->assertSame('owner', $row['relation']['property']);
		$this->assertSame('Eigenaar', $row['relation']['label']);
		$this->assertSame('referenced by', $row['relation']['displayLabel']);
	}//end testAnUnannotatedReferenceReadsAsItsTitleAndReferencedBy()

	/**
	 * A row whose schema could not be loaded still carries a relation block,
	 * so a client never has to tell "no labels" apart from "no relation".
	 */
	public function testARowWithNoSchemaStillCarriesARelationBlock(): void {
		$row = $this->call(
			'withRelation',
			[
				'row' => ['id' => 'uuid-b'],
				'schema' => null,
				'path' => 'blokkeert.0',
				'direction' => RelationTypeResolver::DIRECTION_INCOMING,
			]
		);

		$this->assertSame('blokkeert', $row['relation']['property']);
		$this->assertSame('referenced by', $row['relation']['displayLabel']);
	}//end testARowWithNoSchemaStillCarriesARelationBlock()

	/**
	 * Without a resolver the handler leaves rows exactly as they were, so an
	 * instance that has not wired the resolver degrades to today's behaviour
	 * rather than to a half-filled block.
	 */
	public function testWithoutAResolverTheRowIsUntouched(): void {
		$handler = new RelationHandler(
			objectEntityMapper: $this->createMock(MagicMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			performanceHandler: $this->createMock(PerformanceHandler::class),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			logger: $this->createMock(LoggerInterface::class),
			registerMapper: $this->createMock(RegisterMapper::class)
		);

		$reflection = new ReflectionClass(RelationHandler::class);
		$callable = $reflection->getMethod('withRelation');
		$callable->setAccessible(true);

		$row = $callable->invokeArgs(
			$handler,
			[
				'row' => ['id' => 'uuid-b'],
				'schema' => $this->schema(),
				'path' => 'blokkeert.0',
				'direction' => RelationTypeResolver::DIRECTION_OUTGOING,
			]
		);

		$this->assertSame(['id' => 'uuid-b'], $row);
	}//end testWithoutAResolverTheRowIsUntouched()
}//end class
