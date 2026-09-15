<?php

declare(strict_types=1);

/*
 * ObjectRelationService unit tests.
 *
 * The three assertions that matter here are about TIME, not about storage:
 * what a child takes from its parent is taken once, it is recorded, and a
 * later change to the parent does not reach it. The last of those is the one
 * that is easy to write a test for and easy to write a test that cannot fail,
 * so it asserts the child's own value AFTER the parent moved, not that the
 * two differ.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Relation\ObjectRelationService
 * @covers \OCA\OpenRegister\Db\ObjectRelation
 */
class ObjectRelationServiceTest extends TestCase {
	private ObjectRelationService $service;

	/** @var ObjectRelationMapper&MockObject */
	private ObjectRelationMapper $mapper;

	/**
	 * Rows the mapper was asked to write, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->written = [];
		$this->mapper = $this->createMock(ObjectRelationMapper::class);
		$this->mapper->method('createFromArray')->willReturnCallback(
			function (array $data): ObjectRelation {
				$this->written[] = $data;
				$row = new ObjectRelation();
				$row->hydrate($data);
				$row->setUuid('row-'.count($this->written));

				return $row;
			}
		);

		$this->service = new ObjectRelationService(
			mapper: $this->mapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			relationTypes: new RelationTypeResolver(),
			logger: $this->createMock(LoggerInterface::class),
			userSession: null
		);
	}//end setUp()

	/**
	 * An object with a uuid and some data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data);

		return $entity;
	}//end object()

	/**
	 * Scenario: one melding becomes two zaken, traceably.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testASplitNamesTheSourceObjectAndTheEntryItCameFrom(): void {
		$row = $this->service->recordSplit(
			source: $this->object('uuid-melding'),
			created: $this->object('uuid-zaak'),
			entry: 'entry-42',
			relationType: 'split'
		);

		$this->assertSame('uuid-zaak', $row->getSourceUuid());
		$this->assertSame('uuid-melding', $row->getTargetUuid());
		$this->assertSame('entry-42', $row->getSourceEntry());
		$this->assertSame(ObjectRelation::ORIGIN_SPLIT, $row->getOrigin());
		$this->assertSame(ObjectRelation::KIND_OBJECT, $row->getKind());
	}//end testASplitNamesTheSourceObjectAndTheEntryItCameFrom()

	/**
	 * Scenario: a sub-case starts with the parent's access triad.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAChildTakesTheParentsTriadAndTheRowRecordsIt(): void {
		$parent = $this->object(
			'uuid-parent',
			[
				'classificatie' => 'intern',
				'vertrouwelijkheid' => 'vertrouwelijk',
				'behandelaar' => 'jdoe',
				'onderwerp' => 'Vergunning',
			]
		);

		$applied = $this->service->applyInheritance(
			parent: $parent,
			childData: ['onderwerp' => 'Deelzaak'],
			inherits: [
				'classification' => 'classificatie',
				'confidentiality' => 'vertrouwelijkheid',
				'responsible' => 'behandelaar',
			]
		);

		$this->assertSame('intern', $applied['data']['classificatie']);
		$this->assertSame('vertrouwelijk', $applied['data']['vertrouwelijkheid']);
		$this->assertSame('jdoe', $applied['data']['behandelaar']);
		// The child's own subject is untouched: inheritance fills gaps.
		$this->assertSame('Deelzaak', $applied['data']['onderwerp']);

		$this->assertSame(
			['classification', 'confidentiality', 'responsible'],
			array_keys($applied['inherited'])
		);
		$this->assertSame(
			['property' => 'classificatie', 'value' => 'intern'],
			$applied['inherited']['classification']
		);

		$row = $this->service->recordDerivation(
			parent: $parent,
			child: $this->object('uuid-child'),
			relationType: 'deelzaak',
			inherited: $applied['inherited']
		);

		$this->assertSame(ObjectRelation::ORIGIN_DERIVE, $row->getOrigin());
		$this->assertSame('vertrouwelijk', $row->getInherited()['confidentiality']['value']);
	}//end testAChildTakesTheParentsTriadAndTheRowRecordsIt()

	/**
	 * Scenario: reclassifying the parent does not reclassify the child.
	 *
	 * Inheritance happens at creation and nowhere else, so the child's value
	 * is asserted AFTER the parent moved: asserting only that the two differ
	 * would pass on a child that never inherited anything.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testReclassifyingTheParentDoesNotReclassifyTheChild(): void {
		$parent = $this->object('uuid-parent', ['classificatie' => 'intern']);

		$applied = $this->service->applyInheritance(
			parent: $parent,
			childData: [],
			inherits: ['classification' => 'classificatie']
		);

		$this->assertSame('intern', $applied['data']['classificatie']);

		// The parent is reclassified, long after the child was created.
		$parent->setObject(['classificatie' => 'openbaar']);

		$this->assertSame('intern', $applied['data']['classificatie']);
		$this->assertSame('intern', $applied['inherited']['classification']['value']);
	}//end testReclassifyingTheParentDoesNotReclassifyTheChild()

	/**
	 * A role mapped onto a property the parent does not carry inherits
	 * NOTHING, rather than inheriting null. "The child carries the parent's
	 * confidentiality" and "the child has no confidentiality" must not look
	 * the same afterwards.
	 */
	public function testARoleTheParentDoesNotCarryInheritsNothing(): void {
		$applied = $this->service->applyInheritance(
			parent: $this->object('uuid-parent', ['classificatie' => 'intern']),
			childData: [],
			inherits: ['classification' => 'classificatie', 'confidentiality' => 'vertrouwelijkheid']
		);

		$this->assertArrayNotHasKey('vertrouwelijkheid', $applied['data']);
		$this->assertArrayNotHasKey('confidentiality', $applied['inherited']);
		$this->assertArrayHasKey('classification', $applied['inherited']);
	}//end testARoleTheParentDoesNotCarryInheritsNothing()

	/**
	 * A value the child already carries is the child's own.
	 */
	public function testInheritanceDoesNotOverruleAValueTheChildAlreadyHas(): void {
		$applied = $this->service->applyInheritance(
			parent: $this->object('uuid-parent', ['classificatie' => 'intern']),
			childData: ['classificatie' => 'openbaar'],
			inherits: ['classification' => 'classificatie']
		);

		$this->assertSame('openbaar', $applied['data']['classificatie']);
		$this->assertSame([], $applied['inherited']);
	}//end testInheritanceDoesNotOverruleAValueTheChildAlreadyHas()

	/**
	 * Scenario: a link to another system is part of the record.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnExternalAddressIsARelationWithATitleAndAType(): void {
		$row = $this->service->addExternalLink(
			sourceUuid: 'uuid-zaak',
			url: 'https://zoek.officielebekendmakingen.nl/stcrt-2026-1',
			title: 'Publicatie in de Staatscourant',
			relationType: 'publicatie',
			register: 1,
			schema: 2
		);

		$this->assertSame(ObjectRelation::KIND_EXTERNAL, $row->getKind());
		$this->assertSame('https://zoek.officielebekendmakingen.nl/stcrt-2026-1', $row->getTargetUrl());
		$this->assertSame('Publicatie in de Staatscourant', $row->getTargetTitle());
		$this->assertSame('publicatie', $row->getRelationType());
		$this->assertNull($row->getTargetUuid());
	}//end testAnExternalAddressIsARelationWithATitleAndAType()

	/**
	 * A title nobody gave falls back to the address, so the row is never
	 * nameless in a list.
	 */
	public function testAnExternalLinkWithoutATitleIsNamedByItsAddress(): void {
		$row = $this->service->addExternalLink(sourceUuid: 'uuid-zaak', url: 'https://example.org/x');

		$this->assertSame('https://example.org/x', $row->getTargetTitle());
	}//end testAnExternalLinkWithoutATitleIsNamedByItsAddress()

	/**
	 * Something that is not an address is refused, rather than stored as a
	 * link that resolves nowhere.
	 */
	public function testSomethingThatIsNotAnAddressIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->addExternalLink(sourceUuid: 'uuid-zaak', url: 'zie het andere dossier');
	}//end testSomethingThatIsNotAnAddressIsRefused()

	/**
	 * Scenario: the graph follows from the prose. A mention writes a row on
	 * BOTH sides, under one anchor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAProseReferenceWritesARowOnBothSides(): void {
		$this->mapper->method('findByAnchor')->willReturn([]);

		$rows = $this->service->recordProseReference(
			sourceUuid: 'uuid-a',
			targetUuid: 'uuid-b',
			anchor: 'note-7',
			relationType: 'noemt',
			scope: ['sourceRegister' => 1, 'sourceSchema' => 2, 'targetRegister' => 1, 'targetSchema' => 3]
		);

		$this->assertCount(2, $rows);
		$this->assertSame('uuid-a', $rows[0]->getSourceUuid());
		$this->assertSame('uuid-b', $rows[0]->getTargetUuid());
		$this->assertSame('uuid-b', $rows[1]->getSourceUuid());
		$this->assertSame('uuid-a', $rows[1]->getTargetUuid());

		foreach ($rows as $row) {
			$this->assertSame(ObjectRelation::ORIGIN_PROSE, $row->getOrigin());
			$this->assertSame('note-7', $row->getAnchor());
		}
	}//end testAProseReferenceWritesARowOnBothSides()

	/**
	 * Saving the same text twice does not write the mention twice.
	 */
	public function testTheSameMentionIsNotWrittenTwice(): void {
		$existing = new ObjectRelation();
		$existing->hydrate(['sourceUuid' => 'uuid-a', 'targetUuid' => 'uuid-b', 'anchor' => 'note-7']);
		$this->mapper->method('findByAnchor')->willReturn([$existing]);

		$this->assertSame([], $this->service->recordProseReference(
			sourceUuid: 'uuid-a',
			targetUuid: 'uuid-b',
			anchor: 'note-7'
		));
		$this->assertSame([], $this->written);
	}//end testTheSameMentionIsNotWrittenTwice()

	/**
	 * An object cannot mention itself into a relation with itself.
	 */
	public function testAnObjectDoesNotMentionItself(): void {
		$this->assertSame([], $this->service->recordProseReference(
			sourceUuid: 'uuid-a',
			targetUuid: 'uuid-a',
			anchor: 'note-7'
		));
	}//end testAnObjectDoesNotMentionItself()

	/**
	 * Withdrawing the text withdraws the rows on both sides.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testWithdrawingTheTextWithdrawsBothSides(): void {
		$existing = new ObjectRelation();
		$existing->hydrate(['sourceUuid' => 'uuid-a', 'targetUuid' => 'uuid-b', 'anchor' => 'note-7']);
		$this->mapper->method('findByAnchor')->willReturn([$existing]);

		$deleted = [];
		$this->mapper->method('deleteByAnchor')->willReturnCallback(
			function (string $sourceUuid, string $anchor) use (&$deleted): int {
				$deleted[] = $sourceUuid;

				return 1;
			}
		);

		$this->assertSame(2, $this->service->withdrawProseReferences(
			sourceUuid: 'uuid-a',
			anchor: 'note-7'
		));
		$this->assertSame(['uuid-b', 'uuid-a'], $deleted);
	}//end testWithdrawingTheTextWithdrawsBothSides()

	/**
	 * A stored row reads with a label for its own direction, and a row nothing
	 * named still reads by its origin rather than as an empty string.
	 */
	public function testAStoredRowReadsWithALabelForItsDirection(): void {
		$row = new ObjectRelation();
		$row->hydrate(['sourceUuid' => 'uuid-child', 'targetUuid' => 'uuid-parent']);
		$row->setOrigin(ObjectRelation::ORIGIN_SPLIT);

		$near = $this->service->render(row: $row, direction: RelationTypeResolver::DIRECTION_OUTGOING);
		$far = $this->service->render(row: $row, direction: RelationTypeResolver::DIRECTION_INCOMING);

		$this->assertSame('split from', $near['relation']['displayLabel']);
		$this->assertSame('referenced by', $far['relation']['displayLabel']);
		$this->assertSame(ObjectRelation::ORIGIN_SPLIT, $near['relation']['origin']);
	}//end testAStoredRowReadsWithALabelForItsDirection()
}//end class
