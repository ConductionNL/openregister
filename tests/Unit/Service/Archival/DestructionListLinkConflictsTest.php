<?php

/**
 * DestructionListLinkConflicts: the schemas that can point at a list's entries
 * are read from the schema definitions (by id, uuid, slug, pointer or URL, in
 * `$ref` or `items.$ref`, in every register holding them), a reference by key
 * is left out, and a check that cannot run marks every entry partial.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts;
use OCA\OpenRegister\Service\Archival\LinkedRetentionConflictFinder;
use OCA\OpenRegister\Service\Archival\ReferencingSchema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Archival\ReferencingSchema
 */
class DestructionListLinkConflictsTest extends TestCase {

	private const CASE_UUID = '5d0c1a3e-0000-4000-8000-00000000000a';

	private LinkedRetentionConflictFinder&MockObject $finder;

	private SchemaMapperInterface&MockObject $schemas;

	private RegisterMapperInterface&MockObject $registers;

	private LoggerInterface&MockObject $logger;

	private DestructionListLinkConflicts $service;

	protected function setUp(): void {
		parent::setUp();
		$this->finder    = $this->createMock(LinkedRetentionConflictFinder::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->service   = new DestructionListLinkConflicts(
			finder: $this->finder,
			schemas: $this->schemas,
			registers: $this->registers,
			logger: $this->logger
		);
	}//end setUp()

	private function schema(int $id, string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('schema-uuid-' . $id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	private function entry(string $uuid, string $schemaId): ObjectEntity {
		$entry = new ObjectEntity();
		$entry->setUuid($uuid);
		$entry->setSchema($schemaId);
		return $entry;
	}//end entry()

	/**
	 * A catalogue around the `zaak` schema (id 5) with every way of naming it.
	 *
	 * @return array<int, Schema>
	 */
	private function catalogue(): array {
		return [
			$this->schema(5, 'zaak', ['hoofdzaak' => ['type' => 'string', '$ref' => 'zaak']]),
			$this->schema(11, 'besluit', ['zaak' => ['$ref' => '#/components/schemas/Zaak'], 'titel' => ['type' => 'string']]),
			$this->schema(12, 'document', ['zaken' => ['type' => 'array', 'items' => ['$ref' => '5']], 'besluit' => ['$ref' => '11']]),
			$this->schema(13, 'notitie', ['onderwerp' => ['$ref' => 'https://example.test/api/schemas/schema-uuid-5?x=1']]),
			$this->schema(14, 'zaaktype-code', ['zaak' => ['type' => 'string', 'referenceSchema' => 'zaak']]),
			$this->schema(15, 'klant', ['adres' => ['$ref' => 'adres']]),
		];
	}//end catalogue()

	public function testReferencingSchemasAreReadFromTheDefinitionsInEveryRegister(): void {
		$this->schemas->expects($this->once())->method('findAll')->with(null, null, [], [], [], [], false, false)->willReturn($this->catalogue());
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			static fn (int $schemaId): array => ($schemaId === 11 ? [1, 2] : [1])
		);

		$found = $this->service->referencingSchemas(entries: [$this->entry(self::CASE_UUID, '5')]);

		$this->assertSame(
			[
				['register' => 1, 'schema' => 5, 'field' => 'hoofdzaak', 'more' => []],
				['register' => 1, 'schema' => 11, 'field' => 'zaak', 'more' => []],
				['register' => 2, 'schema' => 11, 'field' => 'zaak', 'more' => []],
				['register' => 1, 'schema' => 12, 'field' => 'zaken', 'more' => []],
				['register' => 1, 'schema' => 13, 'field' => 'onderwerp', 'more' => []],
			],
			array_map(
				static fn (ReferencingSchema $ref): array => [
					'register' => $ref->registerId,
					'schema' => (int) $ref->schema->getId(),
					'field' => $ref->fieldName,
					'more' => $ref->additionalFieldNames,
				],
				$found
			)
		);
	}

	public function testSeveralFieldsOfOneSchemaAreQueriedTogether(): void {
		$this->schemas->method('findAll')->willReturn(
			[
				$this->schema(5, 'zaak', []),
				$this->schema(20, 'overdracht', ['van' => ['$ref' => 'zaak'], 'naar' => ['$ref' => 'zaak']]),
			]
		);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([4]);

		$found = $this->service->referencingSchemas(entries: [$this->entry(self::CASE_UUID, '5')]);

		$this->assertCount(1, $found);
		$this->assertSame('van', $found[0]->fieldName);
		$this->assertSame(['naar'], $found[0]->additionalFieldNames);
	}

	public function testEntriesOfAnUnknownSchemaHaveNoReferencingSchemas(): void {
		$this->schemas->method('findAll')->willReturn($this->catalogue());
		$this->registers->expects($this->never())->method('getAllRegisterIdsWithSchema');

		$this->assertSame([], $this->service->referencingSchemas(entries: [$this->entry(self::CASE_UUID, '99')]));
	}

	public function testTheFinderIsAskedOnceWithTheResolvedSchemas(): void {
		$entries = [$this->entry(self::CASE_UUID, '5'), $this->entry('b', '5')];
		$this->schemas->method('findAll')->willReturn($this->catalogue());
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$answer = [self::CASE_UUID => ['conflicts' => [['kind' => 'linked-on-hold']], 'truncated' => false], 'b' => ['conflicts' => [], 'truncated' => false]];

		$this->finder->expects($this->once())->method('forList')
			->with($entries, $this->callback(static fn (array $referencing): bool => count($referencing) === 4))
			->willReturn($answer);

		$this->assertSame($answer, $this->service->forEntries(entries: $entries));
	}

	public function testACheckThatCannotRunMarksEveryEntryPartialAndLogsAnError(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('catalogue unreadable'));
		$this->finder->expects($this->never())->method('forList');
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('catalogue unreadable'));

		$this->assertSame(
			[
				self::CASE_UUID => ['conflicts' => [], 'truncated' => true],
				'b' => ['conflicts' => [], 'truncated' => true],
			],
			$this->service->forEntries(entries: [$this->entry(self::CASE_UUID, '5'), $this->entry('b', '5')])
		);
	}

	public function testAnEmptyListAsksNothing(): void {
		$this->schemas->expects($this->never())->method('findAll');

		$this->assertSame([], $this->service->forEntries(entries: []));
	}
}
