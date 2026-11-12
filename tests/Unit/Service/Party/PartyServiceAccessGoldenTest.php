<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden outcomes of reading parties: which lookups a read makes and what
 * it answers when a lookup fails, resolution by address, the parent check's
 * allowed and refused outcomes, and how stored addresses and indicators
 * normalise, pinned before those methods were split.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartyServiceAccessGoldenTest extends TestCase {

	/**
	 * Every object-layer call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A party schema.
	 *
	 * @param int $id The id.
	 * @param int $maxDepth The nesting bound.
	 * @param bool $nests Whether the schema declares a parent property.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, int $maxDepth=3, bool $nests=true): Schema {
		$party = ['kind' => 'organisation', 'addressesProperty' => 'adressen', 'indicatorsProperty' => 'indicatoren', 'maxDepth' => $maxDepth];
		if ($nests === true) {
			$party['parentProperty'] = 'moeder';
		}

		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(['adressen' => [], 'indicatoren' => [], 'moeder' => []]);
		$schema->setConfiguration(['x-openregister-party' => $party]);

		return $schema;
	}//end schema()

	/**
	 * A party.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The party.
	 */
	private function party(string $uuid, array $data, string $schema='7'): ObjectEntity {
		$party = new ObjectEntity();
		$party->setUuid($uuid);
		$party->setSchema($schema);
		$party->setObject($data);

		return $party;
	}//end party()

	/**
	 * A party service.
	 *
	 * @param array<string, ObjectEntity|RuntimeException> $stored What find answers per "<id>@<schema>"; "<id>@" is the instance-wide lookup.
	 * @param array<int, array<int, mixed>|RuntimeException> $byAddress What findAll answers per schema id.
	 * @param array<int, int>|RuntimeException|false $registers The register ids per schema; false for no register mapper.
	 * @param array<int, Schema> $schemas The party schemas.
	 *
	 * @return PartyService The service.
	 */
	private function service(array $stored, array $byAddress=[], array|RuntimeException|false $registers=false, array $schemas=[]): PartyService {
		$schemas = ($schemas === [] ? [$this->schema(7)] : $schemas);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($stored): ?ObjectEntity {
				[$id, $_extend, $files, $register, $schema] = FindCallShape::find(args: $args);
				$key = $id . '@' . (string)$schema;
				$this->calls[] = 'find:' . $key . ':' . (string)$register;
				$hit = ($stored[$key] ?? null);
				if ($hit instanceof RuntimeException) {
					throw $hit;
				}

				return $hit;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($byAddress): array {
				$this->calls[] = 'findAll:' . $config['filters']['schema'] . ':' . $config['search'] . ':' . $config['limit'];
				$hit = ($byAddress[$config['filters']['schema']] ?? []);
				if ($hit instanceof RuntimeException) {
					throw $hit;
				}

				return $hit;
			}
		);

		$mapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();
		$mapper->method('findAll')->willReturn($schemas);
		$mapper->method('find')->willReturnCallback(
			function (int|string $id) use ($schemas): Schema {
				foreach ($schemas as $schema) {
					if ((string)$schema->getId() === (string)$id) {
						return $schema;
					}
				}

				return $schemas[0];
			}
		);

		if ($registers === false) {
			return new PartyService($objects, $this->catalog(schemas: $mapper, registers: $this->createMock(RegisterMapperInterface::class)));
		}

		$registerMapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getAllRegisterIdsWithSchema'])
			->getMock();
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			function (int $schemaId) use ($registers): array {
				$this->calls[] = 'registers:' . $schemaId;
				if ($registers instanceof RuntimeException) {
					throw $registers;
				}

				return ($registers[$schemaId] ?? []);
			}
		);

		return new PartyService($objects, $this->catalog(schemas: $mapper, registers: $registerMapper));
	}//end service()

	/**
	 * A schema catalog serving the given schema and register mappers.
	 *
	 * @param SchemaMapperInterface   $schemas   The schema mapper.
	 * @param RegisterMapperInterface $registers The register mapper.
	 *
	 * @return SchemaCatalogInterface The catalog.
	 */
	private function catalog(SchemaMapperInterface $schemas, RegisterMapperInterface $registers): SchemaCatalogInterface {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($schemas);
		$catalog->method('registers')->willReturn($registers);
		return $catalog;
	}//end catalog()

	/**
	 * A blank uuid reads nothing.
	 *
	 * @return void
	 */
	public function testABlankUuidReadsNothing(): void {
		$this->assertNull($this->service([])->find('  '));
		$this->assertSame([], $this->calls);
	}//end testABlankUuidReadsNothing()

	/**
	 * The party tables are tried in order, a failing one skipped, then the instance; the table list is read once.
	 *
	 * @return void
	 */
	public function testTheTablesAreTriedInOrderThenTheInstance(): void {
		$hit = $this->party('p', []);
		$service = $this->service(
			['p@7' => new RuntimeException('no table'), 'p@8' => $hit, 'q@' => new RuntimeException('down')],
			[],
			[7 => [1, 2], 8 => [3]],
			[$this->schema(7), $this->schema(8)]
		);

		$this->assertSame($hit, $service->find('p'));
		$this->assertNull($service->find('q'));
		$this->assertSame(
			[
				'registers:7', 'registers:8',
				'find:p@7:1', 'find:p@7:2', 'find:p@8:3',
				'find:q@7:1', 'find:q@7:2', 'find:q@8:3', 'find:q@:',
			],
			$this->calls
		);
	}//end testTheTablesAreTriedInOrderThenTheInstance()

	/**
	 * A register lookup that fails drops every table, so the read goes instance-wide.
	 *
	 * @return void
	 */
	public function testAFailingRegisterLookupFallsBackToTheInstance(): void {
		$hit = $this->party('p', []);
		$service = $this->service(['p@' => $hit], [], new RuntimeException('x'), [$this->schema(7), $this->schema(8)]);

		$this->assertSame($hit, $service->find('p'));
		$this->assertSame(['registers:7', 'find:p@:'], $this->calls);
	}//end testAFailingRegisterLookupFallsBackToTheInstance()

	/**
	 * Resolution by address: blank finds nothing, a failing schema is skipped, a non-entity is skipped, the first holder wins.
	 *
	 * @return void
	 */
	public function testResolutionByAddress(): void {
		$holder = $this->party('h', ['adressen' => [['value' => 'A@Example.org']]], '9');
		$other  = $this->party('o', ['adressen' => ['b@example.org']], '9');
		$late   = $this->party('l', ['adressen' => ['a@example.org']], '10');
		$service = $this->service(
			[],
			[8 => new RuntimeException('x'), 9 => ['junk', $other, $holder], 10 => [$late]],
			false,
			[$this->schema(8), $this->schema(9), $this->schema(10)]
		);

		$this->assertNull($service->resolveByAddress('   '));
		$this->assertSame([], $this->calls);
		$this->assertSame($holder, $service->resolveByAddress(' a@example.org'));
		$this->assertSame(['findAll:8: a@example.org:50', 'findAll:9: a@example.org:50'], $this->calls);
		$this->assertNull($service->resolveByAddress('nobody@example.org'));
	}//end testResolutionByAddress()

	/**
	 * The parent check: blank parent allowed, self refused, a missing ancestor ends the walk, a non-nesting ancestor ends it.
	 *
	 * @return void
	 */
	public function testTheParentCheckOutcomes(): void {
		$service = $this->service(
			[
				'b@' => $this->party('b', ['moeder' => 'c']),
				'c@' => $this->party('c', ['moeder' => 'missing']),
				'n@' => $this->party('n', ['moeder' => 'a'], '8'),
				'x@' => $this->party('x', ['moeder' => 'a']),
				'y@' => $this->party('y', ['moeder' => '']),
			],
			[],
			false,
			[$this->schema(7), $this->schema(8, 3, false)]
		);

		$service->assertParentAllowed('a', null);
		$service->assertParentAllowed('a', '   ');
		$service->assertParentAllowed('a', 'b');
		$service->assertParentAllowed('a', 'n');
		$service->assertParentAllowed('a', 'y');

		$refusals = [];
		foreach ([['a', 'a'], ['a', 'x']] as [$party, $parent]) {
			try {
				$service->assertParentAllowed($party, $parent);
			} catch (Exception $e) {
				$refusals[] = [$e->getCode(), $e->getMessage()];
			}
		}

		$this->assertSame(
			[
				[400, 'Party "a" cannot be its own parent'],
				[400, 'Party "x" cannot be the parent of "a": it would close a cycle through "a"'],
			],
			$refusals
		);
	}//end testTheParentCheckOutcomes()

	/**
	 * The depth bound counts from the proposed parent.
	 *
	 * @return void
	 */
	public function testTheDepthBound(): void {
		$service = $this->service(
			['p1@' => $this->party('p1', ['moeder' => 'p2']), 'p2@' => $this->party('p2', ['moeder' => 'p3']), 'p3@' => $this->party('p3', [])],
			[],
			false,
			[$this->schema(7, 2)]
		);

		try {
			$service->assertParentAllowed('a', 'p1');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertSame('Party "a" would sit deeper than the declared maximum of 2', $e->getMessage());
		}

		$this->assertSame(['find:p1@:', 'find:p2@:'], $this->calls);
	}//end testTheDepthBound()

	/**
	 * Stored addresses and indicators normalise; malformed entries drop; a non-list reads as none.
	 *
	 * @return void
	 */
	public function testAddressesAndIndicatorsNormalise(): void {
		$service = $this->service([]);
		$party = $this->party(
			'p',
			[
				'adressen' => ['a@x.org', 5, ['email' => 'b@x.org', 'kind' => 'home', 'type' => 'email', 'label' => 'Thuis'], ['address' => 'Straat 1', 'type' => 'post'], ['label' => 'leeg']],
				'indicatoren' => ['kort', 7, ['key' => ''], ['key' => 'k', 'effect' => 'refuse-publication', 'note' => 'n', 'label' => 'L'], ['key' => 'w', 'effect' => 'boom']],
			]
		);

		$this->assertSame(
			[
				['kind' => 'correspondence', 'type' => 'email', 'value' => 'a@x.org', 'label' => null],
				['kind' => 'home', 'type' => 'email', 'value' => 'b@x.org', 'label' => 'Thuis'],
				['kind' => 'correspondence', 'type' => 'post', 'value' => 'Straat 1', 'label' => null],
			],
			$service->addresses($party)
		);
		$this->assertSame(
			[
				['key' => 'kort', 'label' => 'kort', 'effect' => 'warn', 'note' => null],
				['key' => 'k', 'label' => 'L', 'effect' => 'refuse-publication', 'note' => 'n'],
				['key' => 'w', 'label' => 'w', 'effect' => 'warn', 'note' => null],
			],
			$service->indicators($party)
		);

		$flat = $this->party('f', ['adressen' => 'a@x.org', 'indicatoren' => 'kort']);
		$this->assertSame([], $service->addresses($flat));
		$this->assertSame([], $service->indicators($flat));
	}//end testAddressesAndIndicatorsNormalise()
}//end class
