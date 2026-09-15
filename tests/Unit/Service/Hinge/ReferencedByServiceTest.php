<?php

/**
 * Unit tests for ReferencedByService — the reverse view.
 *
 * Covers the grouping by schema, the summary each referencing record carries,
 * the access flag reaching the query rather than a post-load filter, and the
 * empty group being left out.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\ReferencedByService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReferencedByServiceTest extends TestCase {

	private function register(int $id, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setTitle(ucfirst($slug));
		return $register;
	}

	private function schema(int $id, string $slug, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	private function object(string $uuid, ?string $name = null, array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setObject($data);
		$object->setUpdated(new DateTime('2026-09-14T10:00:00+00:00'));
		return $object;
	}

	/**
	 * Build the service over mapper doubles.
	 *
	 * @param array $tables  Table descriptors the magic mapper reports.
	 * @param array $rowsBy  Map of schema id to the rows that table returns.
	 * @param array $capture Receives every filter array the mapper was asked for.
	 * @param array $schemas Schemas the schema mapper resolves, keyed by id.
	 * @param array $registers Registers the register mapper resolves, keyed by id.
	 *
	 * @return ReferencedByService The service under test.
	 */
	private function service(array $tables, array $rowsBy, array &$capture, array $schemas, array $registers): ReferencedByService {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getExistingRegisterSchemaTables')->willReturn($tables);
		$magicMapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query, Register $register, Schema $schema) use ($rowsBy, &$capture): int {
				$capture[] = $query;
				return count($rowsBy[$schema->getId()] ?? []);
			}
		);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			static function (Register $register, Schema $schema, ?int $limit, ?int $offset, ?array $filters, array $sort) use ($rowsBy, &$capture): array {
				$capture[] = $filters;
				return array_slice(($rowsBy[$schema->getId()] ?? []), (int)$offset, (int)$limit);
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static fn ($id): Register => $registers[(int)$id]
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static fn ($id): Schema => $schemas[(int)$id]
		);

		return new ReferencedByService(
			magicMapper: $magicMapper,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: new NullLogger()
		);
	}

	public function testAnAddressHasAHistoryGroupedBySchema(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [
			10 => $this->schema(10, 'melding', ['x-openregister-lifecycle' => ['field' => 'status']]),
			11 => $this->schema(11, 'inspectie', ['x-openregister-lifecycle' => ['field' => 'status']]),
			12 => $this->schema(12, 'vergunning'),
		];
		$rowsBy = [
			10 => [$this->object('uuid-melding', 'Melding 1', ['status' => 'open'])],
			11 => [$this->object('uuid-inspectie', 'Inspectie 1', ['status' => 'gepland'])],
			12 => [$this->object('uuid-vergunning', 'Vergunning 1', ['status' => 'verleend'])],
		];
		$tables = [
			['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10'],
			['registerId' => 1, 'schemaId' => 11, 'tableName' => 't11'],
			['registerId' => 1, 'schemaId' => 12, 'tableName' => 't12'],
		];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$result = $service->getReferencingGroups($this->object('uuid-address', 'Dorpsstraat 1'));

		$this->assertSame(3, $result['total']);
		$this->assertCount(3, $result['groups']);

		$bySlug = [];
		foreach ($result['groups'] as $group) {
			$bySlug[$group['schema']['slug']] = $group;
		}

		$this->assertSame(['melding', 'inspectie', 'vergunning'], array_keys($bySlug));
		$this->assertSame('Melding 1', $bySlug['melding']['results'][0]['title']);
		$this->assertSame('open', $bySlug['melding']['results'][0]['status']);
		$this->assertSame('2026-09-14T10:00:00+00:00', $bySlug['melding']['results'][0]['updated']);

		// A schema declaring no lifecycle has no status to report, and that is
		// not an error: the group still carries its title and its last change.
		$this->assertNull($bySlug['vergunning']['results'][0]['status']);
		$this->assertSame('Vergunning 1', $bySlug['vergunning']['results'][0]['title']);
	}

	public function testTheReverseViewAsksTheQueryToApplyAccess(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [10 => $this->schema(10, 'melding')];
		$rowsBy = [10 => [$this->object('uuid-melding', 'Melding 1')]];
		$tables = [['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10']];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$service->getReferencingGroups($this->object('uuid-address'), [], true);

		$this->assertNotEmpty($capture);
		foreach ($capture as $filters) {
			$this->assertTrue($filters['_rbac']);
			$this->assertSame('uuid-address', $filters['_relations_contains']);
		}
	}

	public function testAdminReadsWithAccessFilteringOff(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [10 => $this->schema(10, 'melding')];
		$rowsBy = [10 => [$this->object('uuid-melding', 'Melding 1')]];
		$tables = [['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10']];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$service->getReferencingGroups($this->object('uuid-address'), [], false);

		foreach ($capture as $filters) {
			$this->assertFalse($filters['_rbac']);
		}
	}

	public function testASchemaThatReferencesNothingIsLeftOut(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [
			10 => $this->schema(10, 'melding'),
			11 => $this->schema(11, 'inspectie'),
		];
		$rowsBy = [10 => [$this->object('uuid-melding', 'Melding 1')], 11 => []];
		$tables = [
			['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10'],
			['registerId' => 1, 'schemaId' => 11, 'tableName' => 't11'],
		];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$result = $service->getReferencingGroups($this->object('uuid-address'));

		$this->assertCount(1, $result['groups']);
		$this->assertSame('melding', $result['groups'][0]['schema']['slug']);
	}

	public function testOneGroupCanBePagedOnItsOwn(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [
			10 => $this->schema(10, 'melding'),
			11 => $this->schema(11, 'inspectie'),
		];
		$rowsBy = [
			10 => [
				$this->object('uuid-a', 'A'),
				$this->object('uuid-b', 'B'),
				$this->object('uuid-c', 'C'),
			],
			11 => [$this->object('uuid-inspectie', 'Inspectie 1')],
		];
		$tables = [
			['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10'],
			['registerId' => 1, 'schemaId' => 11, 'tableName' => 't11'],
		];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$result = $service->getReferencingGroups(
			$this->object('uuid-address'),
			['_schema' => 'melding', '_limit' => 2, '_offset' => 1]
		);

		$this->assertCount(1, $result['groups']);
		$this->assertSame(3, $result['groups'][0]['total']);
		$this->assertSame(2, $result['groups'][0]['limit']);
		$this->assertSame(1, $result['groups'][0]['offset']);
		$this->assertSame(['B', 'C'], array_column($result['groups'][0]['results'], 'title'));
	}

	public function testTheHingeItselfIsNeverListedAsItsOwnReference(): void {
		$registers = [1 => $this->register(1, 'zaken')];
		$schemas = [10 => $this->schema(10, 'adres')];
		$rowsBy = [10 => [$this->object('uuid-address', 'Dorpsstraat 1'), $this->object('uuid-other', 'Dorpsstraat 2')]];
		$tables = [['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10']];

		$capture = [];
		$service = $this->service($tables, $rowsBy, $capture, $schemas, $registers);

		$result = $service->getReferencingGroups($this->object('uuid-address', 'Dorpsstraat 1'));

		$this->assertSame(['Dorpsstraat 2'], array_column($result['groups'][0]['results'], 'title'));
	}
}
