<?php

/**
 * OpenRegister - the entity MagicSearchHandler hydrates from one magic-table row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/underscore-properties-and-config-paths/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionObject;
use Stringable;

/**
 * Pins every entity field, the set of fields marked as updated, the object data
 * and the error log for a range of rows.
 *
 * Expected values live in `fixtures/row-to-entity.php`, one entry per case.
 */
class MagicSearchHandlerRowToEntityTest extends TestCase {

	/**
	 * Schema properties every case reads with.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PROPERTIES = [
		'title'        => ['type' => 'string'],
		'e-mailadres'  => ['type' => 'string'],
		'count'        => ['type' => 'integer'],
		'price'        => ['type' => 'number'],
		'active'       => ['type' => 'boolean'],
		'tags'         => ['type' => 'array'],
		'meta'         => ['type' => 'object'],
		'occurredAt'   => ['type' => 'string', 'format' => 'date-time'],
		'closeDate'    => ['type' => 'string', 'format' => 'date'],
		'otherFormat'  => ['type' => 'string', 'format' => 'email'],
		'_custom'      => ['type' => 'string'],
		'_owner'       => ['type' => 'string'],
		'untyped'      => [],
	];

	/**
	 * Every case: the row and, optionally, the register and schema ids.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$jsonFields = ['relations', 'files', 'locked', 'groups', 'authorization', 'validation', 'geo', 'retention'];
		$cases      = [
			'empty row'               => ['row' => []],
			'scalar metadata'         => [
				'row' => [
					'_uuid'         => 'u-1',
					'_name'         => 'Name',
					'_description'  => 'Desc',
					'_summary'      => 'Sum',
					'_image'        => 'img.png',
					'_slug'         => 'slug-1',
					'_uri'          => 'https://x/1',
					'_owner'        => 'alice',
					'_organisation' => 'org-1',
					'_version'      => '1.2.3',
					'_folder'       => '42',
					'_application'  => 'app-1',
					'_size'         => '1234',
				],
			],
			'null metadata'           => [
				'row' => [
					'_uuid'               => null,
					'_name'               => null,
					'_created'            => null,
					'_deleted'            => null,
					'_relevance'          => null,
					'_relations'          => null,
					'_locked'             => null,
					'_size'               => null,
					'_last_user_activity' => null,
				],
			],
			'empty string metadata'   => [
				'row' => ['_uuid' => '', '_name' => '', '_version' => '', '_folder' => ''],
			],
			'dates'                   => [
				'row' => [
					'_created'            => '2026-01-02 03:04:05',
					'_updated'            => '2026-02-03T04:05:06+02:00',
					'_last_user_activity' => '2026-03-04 05:06:07',
				],
			],
			'deleted as json'         => ['row' => ['_deleted' => '{"deletedAt":"2026-01-01T00:00:00+00:00","deletedBy":"bob"}']],
			'deleted as array'        => ['row' => ['_deleted' => ['deletedBy' => 'carol']]],
			'deleted as timestamp'    => ['row' => ['_deleted' => '2026-01-05 10:00:00']],
			'relevance'               => ['row' => ['_relevance' => '87.5']],
			'relevance int'           => ['row' => ['_relevance' => 3]],
			'unhandled metadata'      => ['row' => ['_schema_version' => '2', '_status' => 'open', '_archived' => '{}', '_register' => '9']],
			'properties typed'        => [
				'row' => [
					'title'        => 'Hello',
					'e_mailadres'  => 'a@b.nl',
					'count'        => '12',
					'price'        => '3.50',
					'active'       => '1',
					'tags'         => '["a","b"]',
					'meta'         => '{"k":"v"}',
					'other_format' => 'x@y.nl',
					'untyped'      => '5',
				],
			],
			'properties null'         => ['row' => ['title' => null, 'count' => null, 'occurred_at' => null, 'close_date' => null]],
			'date-time format'        => ['row' => ['occurred_at' => '2026-05-26 09:15:00']],
			'date format'             => ['row' => ['close_date' => '2026-07-15 00:00:00']],
			'date format unparsable'  => ['row' => ['close_date' => 'not a date']],
			'date format non-string'  => ['row' => ['close_date' => 20260715]],
			'date-time non-string'    => ['row' => ['occurred_at' => ['x']]],
			'date-time empty'         => ['row' => ['occurred_at' => '']],
			'unknown column'          => ['row' => ['some_unknown_col' => 'v', 'plain' => 7]],
			'underscore property'     => ['row' => ['_custom' => 'mine', '_owner' => 'metadata-wins']],
			'bad created date throws' => ['row' => ['_uuid' => 'u-err', '_created' => 'not-a-date', 'title' => 'x']],
			'bad deleted throws'      => ['row' => ['_deleted' => 'garbage-date']],
			'ids'                     => ['row' => ['_uuid' => 'u-ids'], 'registerId' => 7, 'schemaId' => 11],
			'null ids'                => ['row' => ['_uuid' => 'u-null'], 'registerId' => null, 'schemaId' => null],
		];

		foreach ($jsonFields as $field) {
			$cases["$field json array"]   = ['row' => ["_$field" => '{"a":1,"b":[2]}']];
			$cases["$field json list"]    = ['row' => ["_$field" => '[1,2]']];
			$cases["$field array"]        = ['row' => ["_$field" => ['x' => 'y']]];
			$cases["$field invalid json"] = ['row' => ["_$field" => '{not json']];
			$cases["$field json scalar"]  = ['row' => ["_$field" => '5']];
			$cases["$field empty string"] = ['row' => ["_$field" => '']];
			$cases["$field integer"]      = ['row' => ["_$field" => 5]];
		}

		return $cases;
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/row-to-entity.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The handler hydrates the recorded entity from the row.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded outcome
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testRowHydratesTheRecordedEntity(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testRowHydratesTheRecordedEntity()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/row-to-entity.php';
		$this->assertSame(expected: array_keys(self::cases()), actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * Run one case and describe its outcome.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	public function runCase(array $case): array {
		$logger  = new class extends AbstractLogger {
			/**
			 * The logged levels and messages.
			 *
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->lines[] = $level.': '.$message.' '.implode(',', array_keys($context));
			}//end log()
		};
		$handler = new MagicSearchHandler(
			db: $this->createMock(originalClassName: IDBConnection::class),
			logger: $logger,
			rbacHandler: $this->createMock(originalClassName: MagicRbacHandler::class),
			organizationHandler: $this->createMock(originalClassName: MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);

		$register = new Register();
		$register->setId($case['registerId'] ?? 3);
		$schema = new Schema();
		$schema->setId($case['schemaId'] ?? 5);
		$schema->setProperties(self::PROPERTIES);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'convertRowToObjectEntity');
		$entity = $method->invoke($handler, $case['row'], $register, $schema, 'oc_table');

		return [
			'entity' => $this->describe(entity: $entity),
			'log'    => $logger->lines,
		];
	}//end runCase()

	/**
	 * Every property of the entity and the fields marked as updated.
	 *
	 * @param ObjectEntity|null $entity The entity
	 *
	 * @return array<string, mixed>|null
	 */
	private function describe(?ObjectEntity $entity): ?array {
		if ($entity === null) {
			return null;
		}

		$fields = [];
		foreach ((new ReflectionObject($entity))->getProperties() as $property) {
			if ($property->isStatic() === true || $property->getDeclaringClass()->getName() !== ObjectEntity::class) {
				continue;
			}

			$value = $property->getValue($entity);
			if ($value instanceof DateTimeInterface) {
				$value = $value->format('Y-m-d\TH:i:sP');
			}

			$fields[$property->getName()] = $value;
		}

		return [
			'fields'  => $fields,
			'updated' => array_keys($entity->getUpdatedFields()),
		];
	}//end describe()
}//end class
