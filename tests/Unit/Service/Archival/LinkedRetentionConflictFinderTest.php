<?php

/**
 * Unit tests for LinkedRetentionConflictFinder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\LinkedRetentionConflictFinder;
use OCA\OpenRegister\Service\Archival\ReferencingSchema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which linked records make destroying a list entry a conflict, and how they are found.
 *
 * @covers \OCA\OpenRegister\Service\Archival\LinkedRetentionConflictFinder
 * @covers \OCA\OpenRegister\Service\Archival\ReferencingSchema
 */
class LinkedRetentionConflictFinderTest extends TestCase {

	private const CASE = '11111111-0000-4000-8000-000000000042';

	private MagicMapperInterface&MockObject $objects;

	private SchemaMapperInterface&MockObject $schemas;

	private LinkedRetentionConflictFinder $finder;

	/**
	 * Records by uuid, for the outgoing batch read.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $records = [];

	/**
	 * Set up the finder over mapper doubles; the hold and relation-method rules are the real ones.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->objects->method('findMultiple')->willReturnCallback(
			fn (array $ids): array => array_values(array_filter(array_map(fn (string $id): ?ObjectEntity => ($this->records[$id] ?? null), $ids)))
		);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$holds = (new \ReflectionClass(LegalHoldService::class))->newInstanceWithoutConstructor();

		$this->finder = new LinkedRetentionConflictFinder(
			objects: $this->objects,
			schemas: $this->schemas,
			holds: $holds,
			dates: new ArchiveActionDateCalculator($this->createMock(MagicMapperInterface::class), $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * A record.
	 *
	 * @param string               $uuid      Its uuid.
	 * @param array<string, mixed> $retention Its retention block.
	 * @param array<int, string>   $relations The uuids it points at.
	 * @param string               $schema    Its schema id.
	 * @param array<string, mixed> $data      Its data.
	 *
	 * @return ObjectEntity
	 */
	private function record(string $uuid, array $retention=[], array $relations=[], string $schema='20', array $data=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName('Record '.$uuid);
		$object->setSchema($schema);
		$object->setRetention($retention);
		$object->setRelations($relations);
		$object->setObject($data);
		$this->records[$uuid] = $object;

		return $object;
	}//end record()

	/**
	 * The case on the list: destroy on 2026-09-01.
	 *
	 * @param array<int, string> $relations What it points at.
	 *
	 * @return ObjectEntity
	 */
	private function case(array $relations=[]): ObjectEntity {
		return $this->record(uuid: self::CASE, retention: ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2026-09-01'], relations: $relations, schema: '10');
	}//end case()

	/**
	 * A referencing schema with the given id and archive configuration.
	 *
	 * @param int                  $id      The schema id.
	 * @param array<string, mixed> $archive Its archive configuration.
	 *
	 * @return ReferencingSchema
	 */
	private static function referencing(int $id, array $archive=[]): ReferencingSchema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setArchive($archive);

		return new ReferencingSchema(registerId: 3, schema: $schema, fieldName: 'zaak');
	}//end referencing()

	/**
	 * Each kind of conflict, found through an outgoing link.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function kinds(): array {
		return [
			'kept permanently' => [['archiefnominatie' => 'bewaren', 'archiefactiedatum' => null], LinkedRetentionConflictFinder::KEPT_PERMANENTLY],
			'kept longer' => [['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2035-01-01'], LinkedRetentionConflictFinder::KEPT_LONGER],
			'date unknown' => [['archiefnominatie' => 'vernietigen'], LinkedRetentionConflictFinder::DATE_UNKNOWN],
			'on hold' => [['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2020-01-01', 'legalHold' => ['active' => true]], LinkedRetentionConflictFinder::ON_HOLD],
			'goes first' => [['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2025-01-01'], null],
			'same day' => [['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2026-09-01'], null],
		];
	}//end kinds()

	/**
	 * A linked record's retention decides the conflict kind.
	 *
	 * @param array<string, mixed> $retention The linked record's retention.
	 * @param string|null          $kind      The expected kind, or null for none.
	 *
	 * @dataProvider kinds
	 *
	 * @return void
	 */
	public function testEachKind(array $retention, ?string $kind): void {
		$this->record(uuid: 'doc-1', retention: $retention);
		$this->schemas->method('find')->willReturn(new Schema());

		$found = $this->finder->forList(entries: [$this->case(relations: ['documents' => ['doc-1']])], referencing: []);

		$expected = [];
		if ($kind !== null) {
			$expected[] = [
				'kind' => $kind,
				'direction' => 'outgoing',
				'uuid' => 'doc-1',
				'title' => 'Record doc-1',
				'schema' => '20',
				'archiefnominatie' => $retention['archiefnominatie'],
				'archiefactiedatum' => ($retention['archiefactiedatum'] ?? null),
			];
		}

		$this->assertSame(['conflicts' => $expected, 'truncated' => false], $found[self::CASE]);
	}//end testEachKind()

	/**
	 * A decision that references the case and is kept permanently is an incoming conflict,
	 * found with one query for its schema.
	 *
	 * @return void
	 */
	public function testAnIncomingKeptDecisionIsFoundWithOneQueryPerSchema(): void {
		$decision = $this->record(uuid: 'B-2019-0007', retention: ['archiefnominatie' => 'bewaren'], relations: ['zaak' => self::CASE], schema: '14');
		$this->objects->expects($this->once())->method('findByRelationBatchInSchema')
			->with([self::CASE], 14, 3, 'zaak', [])
			->willReturn([$decision]);

		$found = $this->finder->forList(entries: [$this->case()], referencing: [self::referencing(id: 14)]);

		$this->assertCount(1, $found[self::CASE]['conflicts']);
		$this->assertSame(
			['linked-kept-permanently', 'incoming', 'B-2019-0007'],
			[$found[self::CASE]['conflicts'][0]['kind'], $found[self::CASE]['conflicts'][0]['direction'], $found[self::CASE]['conflicts'][0]['uuid']]
		);
	}//end testAnIncomingKeptDecisionIsFoundWithOneQueryPerSchema()

	/**
	 * A sub-case whose schema takes its date from the case through a relation method is flagged as such.
	 *
	 * @return void
	 */
	public function testASubCaseThatDerivesItsDateIsFlagged(): void {
		$sub = $this->record(
			uuid: 'sub-1',
			retention: ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2026-09-01'],
			relations: ['hoofdzaak' => self::CASE],
			schema: '15',
			data: ['hoofdzaak' => self::CASE]
		);
		$this->objects->method('findByRelationBatchInSchema')->willReturn([$sub]);

		$found = $this->finder->forList(
			entries: [$this->case()],
			referencing: [self::referencing(id: 15, archive: ['afleidingswijze' => 'hoofdzaak', 'sourceRelation' => 'hoofdzaak', 'sourceRelationProperty' => 'archiefactiedatum'])]
		);

		$this->assertSame(LinkedRetentionConflictFinder::DERIVES_DATE, $found[self::CASE]['conflicts'][0]['kind']);
	}//end testASubCaseThatDerivesItsDateIsFlagged()

	/**
	 * Records destroyed together do not warn about each other.
	 *
	 * @return void
	 */
	public function testALinkedRecordOnTheSameListIsIgnored(): void {
		$document = $this->record(uuid: 'doc-1', retention: ['archiefnominatie' => 'vernietigen'], relations: ['zaak' => self::CASE]);
		$this->objects->method('findByRelationBatchInSchema')->willReturn([$document]);
		$this->objects->expects($this->never())->method('findMultiple');

		$found = $this->finder->forList(entries: [$this->case(relations: ['docs' => ['doc-1']]), $document], referencing: [self::referencing(id: 20)]);

		$this->assertSame(['conflicts' => [], 'truncated' => false], $found[self::CASE]);
		$this->assertSame(['conflicts' => [], 'truncated' => false], $found['doc-1']);
	}//end testALinkedRecordOnTheSameListIsIgnored()

	/**
	 * Fifty links are examined; the fifty-first marks the entry truncated and is not read.
	 *
	 * @return void
	 */
	public function testTheFiftyFirstLinkTruncates(): void {
		$targets = [];
		for ($i = 1; $i <= 51; $i++) {
			$targets[] = 'doc-'.$i;
			$this->record(uuid: 'doc-'.$i, retention: ['archiefnominatie' => 'bewaren']);
		}

		$this->schemas->method('find')->willReturn(new Schema());
		$this->objects->expects($this->once())->method('findMultiple')
			->with($this->callback(static fn (array $ids): bool => count($ids) === 50 && in_array('doc-51', $ids, true) === false))
			->willReturnCallback(fn (array $ids): array => array_map(fn (string $id): ObjectEntity => $this->records[$id], $ids));

		$found = $this->finder->forList(entries: [$this->case(relations: ['docs' => $targets])], referencing: []);

		$this->assertTrue($found[self::CASE]['truncated']);
		$this->assertCount(50, $found[self::CASE]['conflicts']);
	}//end testTheFiftyFirstLinkTruncates()

	/**
	 * Fifty links exactly are not truncated.
	 *
	 * @return void
	 */
	public function testFiftyLinksAreComplete(): void {
		$targets = [];
		for ($i = 1; $i <= 50; $i++) {
			$targets[] = 'doc-'.$i;
			$this->record(uuid: 'doc-'.$i, retention: ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2020-01-01']);
		}

		$this->schemas->method('find')->willReturn(new Schema());

		$this->assertFalse($this->finder->forList(entries: [$this->case(relations: ['docs' => $targets])], referencing: [])[self::CASE]['truncated']);
	}//end testFiftyLinksAreComplete()
}//end class
