<?php

/**
 * OpenRegister - the columns MagicMapper writes for one object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Stringable;

/**
 * Pins every prepared column and every log line for a range of objects.
 *
 * Expected values live in `fixtures/prepare-object-data.php`, one entry per case.
 * A `_created` or `_updated` stamped with the current time is recorded as `<now>`.
 */
class MagicMapperPrepareObjectDataTest extends TestCase {

	/**
	 * Schema properties most cases write with.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PROPERTIES = [
		'title'       => ['type' => 'string'],
		'e-mailadres' => ['type' => 'string'],
		'camelName'   => ['type' => 'string'],
		'count'       => ['type' => 'integer'],
		'price'       => ['type' => 'number'],
		'active'      => ['type' => 'boolean'],
		'tags'        => ['type' => 'array'],
		'meta'        => ['type' => 'object'],
		'occurredAt'  => ['type' => 'string', 'format' => 'date-time'],
		'dueDate'     => ['type' => 'string', 'format' => 'date'],
		'attachment'  => ['type' => 'file'],
		'attachments' => ['type' => 'array', 'items' => ['type' => 'file']],
		'_owner'      => ['type' => 'string'],
		'_custom'     => ['type' => 'string'],
	];

	/**
	 * Every case: the object data and, optionally, schema properties and linked types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$amsterdam = new DateTimeZone('Europe/Amsterdam');

		return [
			'empty object'                => ['data' => []],
			'scalar metadata'             => [
				'data' => [
					'@self' => [
						'uuid'         => 'u-1',
						'slug'         => 's-1',
						'uri'          => 'https://x/1',
						'version'      => '1.0.0',
						'owner'        => 'alice',
						'organisation' => 'org-1',
						'application'  => 'app-1',
						'folder'       => '12',
						'name'         => 'N',
						'description'  => 'D',
						'summary'      => 'S',
						'image'        => 'i.png',
						'size'         => '99',
						'status'       => 'draft',
						'id'           => 'ignored',
					],
				],
			],
			'server fields forced'        => [
				'data' => ['@self' => ['register' => 999, 'schema' => 998, 'authorization' => ['read' => ['x']], 'owner' => ['uid' => 'mallory']]],
			],
			'schemaVersion camel'         => ['data' => ['@self' => ['schemaVersion' => '2.0', 'schema_version' => '1.0']]],
			'schema_version snake'        => ['data' => ['@self' => ['schema_version' => '1.0']]],
			'last user activity camel'    => ['data' => ['@self' => ['lastUserActivity' => '2026-01-02T03:04:05+00:00']]],
			'last user activity snake'    => ['data' => ['@self' => ['last_user_activity' => '2026-01-02T03:04:05+00:00']]],
			'dates as DateTime'           => [
				'data' => [
					'@self' => [
						'created' => new DateTime('2026-01-02 10:00:00', $amsterdam),
						'updated' => new DateTimeImmutable('2026-06-02 10:00:00', $amsterdam),
						'expires' => new DateTime('2027-01-01 00:00:00', new DateTimeZone('UTC')),
					],
				],
			],
			'dates as strings'            => [
				'data' => ['@self' => ['created' => '2026-01-02T10:00:00+02:00', 'updated' => '2026-01-03 04:05:06', 'expires' => '2027-02-02T00:00:00Z']],
			],
			'dates empty strings'         => ['data' => ['@self' => ['created' => '', 'updated' => '   ', 'expires' => '']]],
			'dates unparsable'            => ['data' => ['@self' => ['created' => 'not a date', 'expires' => 'nope']]],
			'dates other types'           => ['data' => ['@self' => ['created' => 12345, 'expires' => ['x']]]],
			'json metadata arrays'        => [
				'data' => [
					'@self' => [
						'files'      => [1, 2],
						'relations'  => ['a' => 'b'],
						'locked'     => ['user' => 'x'],
						'validation' => ['ok' => true],
						'quality'    => ['score' => 3],
						'deleted'    => ['deletedBy' => 'y'],
						'archived'   => ['at' => 'z'],
						'frozen'     => ['by' => 'q'],
						'geo'        => ['lat' => 1.5],
						'retention'  => ['years' => 7],
						'groups'     => ['g1'],
						'tmlo'       => ['k' => 'v'],
					],
				],
			],
			'json metadata empty arrays'  => ['data' => ['@self' => ['files' => [], 'relations' => [], 'groups' => [], 'tmlo' => []]]],
			'json metadata strings'       => ['data' => ['@self' => ['files' => '[1]', 'relations' => '', 'locked' => 'x']]],
			'json metadata other types'   => ['data' => ['@self' => ['files' => 5, 'relations' => true, 'geo' => 1.5, 'retention' => new stdClass()]]],
			'linked types'                => [
				'data'        => ['@self' => ['mail' => ['m1'], 'notes' => [], 'deck' => 'raw', 'unknown' => ['u']]],
				'linkedTypes' => ['mail', 'notes', 'deck', 'unknown'],
			],
			'linked types absent'         => ['data' => [], 'linkedTypes' => ['contacts']],
			'properties typed'            => [
				'data' => [
					'title'       => 'Hello',
					'e-mailadres' => 'a@b.nl',
					'camelName'   => 'c',
					'count'       => 3,
					'price'       => 1.25,
					'active'      => true,
					'tags'        => ['a', 'b'],
					'meta'        => ['k' => 'v'],
				],
			],
			'properties false and empty'  => ['data' => ['active' => false, 'tags' => [], 'meta' => new stdClass(), 'title' => '']],
			'properties null'             => ['data' => ['title' => null, 'occurredAt' => null, 'dueDate' => null, 'attachment' => null]],
			'date-time property'          => [
				'data' => ['occurredAt' => '2026-05-26T09:15:00+02:00', 'dueDate' => '2026-10-20T00:00:00+02:00'],
			],
			'date-time property objects'  => [
				'data' => [
					'occurredAt' => new DateTime('2026-05-26 09:15:00', $amsterdam),
					'dueDate'    => new DateTimeImmutable('2026-10-20 23:30:00', new DateTimeZone('America/Chicago')),
				],
			],
			'date property odd values'    => ['data' => ['occurredAt' => '', 'dueDate' => 'garbage']],
			'date property non-string'    => ['data' => ['occurredAt' => 5, 'dueDate' => ['x']]],
			'file data url'               => ['data' => ['attachment' => 'data:text/plain;base64,SGVsbG8=']],
			'file reference'              => ['data' => ['attachment' => 'file-123']],
			'file as array'               => ['data' => ['attachment' => ['id' => 1]]],
			'files mixed'                 => ['data' => ['attachments' => ['data:image/png;base64,AAA', 'file-1', 7, 'data:x']]],
			'files all data urls'         => ['data' => ['attachments' => ['data:a', 'data:b']]],
			'files not an array'          => ['data' => ['attachments' => 'data:a']],
			'files empty'                 => ['data' => ['attachments' => []]],
			'metadata-named property'     => ['data' => ['_owner' => 'mallory', '_custom' => 'kept', '@self' => ['owner' => 'alice']]],
			'undeclared keys'             => ['data' => ['extra' => 1, 'another' => 'x', 'id' => 5, 'uuid' => 'u', '@type' => 't', '_meta' => 'm', '' => 'e']],
			'properties not an array'     => ['data' => ['title' => 'x'], 'properties' => null],
			'property without type'       => [
				'data'       => ['loose' => ['a' => 1], 'flag' => true],
				'properties' => ['loose' => [], 'flag' => ['format' => 'date']],
			],
			'everything together'         => [
				'data' => [
					'@self'      => ['uuid' => 'u-9', 'owner' => 'bob', 'relations' => ['r'], 'created' => '2025-12-31T23:00:00Z'],
					'title'      => 'T',
					'occurredAt' => '2026-01-01 00:00:00',
					'extra'      => 'dropped',
				],
				'linkedTypes' => ['talk'],
			],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/prepare-object-data.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The mapper prepares the recorded columns for the object.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded outcome
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testObjectPreparesTheRecordedColumns(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testObjectPreparesTheRecordedColumns()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/prepare-object-data.php';
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
		$logger = new class extends AbstractLogger {
			/**
			 * The logged levels, messages and context keys.
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
				unset($context['file'], $context['line']);
				$this->lines[] = $level.': '.$message.' '.json_encode($context);
			}//end log()
		};

		$schema = $this->getMockBuilder(className: Schema::class)->onlyMethods(methods: ['getLinkedTypes'])->getMock();
		$schema->method('getLinkedTypes')->willReturn($case['linkedTypes'] ?? []);
		$schema->setId(7);
		$properties = self::PROPERTIES;
		if (array_key_exists('properties', $case) === true) {
			$properties = $case['properties'];
		}

		$schema->setProperties($properties);
		$register = new Register();
		$register->setId(3);

		$before   = gmdate('Y-m-d H:i:s');
		$prepared = $this->invokePrepare(
			mapper: $this->mapper(logger: $logger),
			data: $case['data'],
			register: $register,
			schema: $schema
		);
		$after    = gmdate('Y-m-d H:i:s');

		foreach (['_created', '_updated'] as $column) {
			$value = ($prepared[$column] ?? null);
			if (is_string($value) === true && $value >= $before && $value <= $after) {
				$prepared[$column] = '<now>';
			}
		}

		return ['prepared' => $prepared, 'log' => $logger->lines];
	}//end runCase()

	/**
	 * Call the private prepareObjectDataForTable() with a clean warning cache.
	 *
	 * @param MagicMapper          $mapper   The mapper
	 * @param array<string, mixed> $data     The object data
	 * @param Register             $register The register
	 * @param Schema               $schema   The schema
	 *
	 * @return array<string, mixed>
	 */
	private function invokePrepare(MagicMapper $mapper, array $data, Register $register, Schema $schema): array {
		$warned = new \ReflectionProperty(MagicMapper::class, 'warnedMetadataNamedProperties');
		$warned->setValue(null, []);

		$method = new ReflectionMethod(MagicMapper::class, 'prepareObjectDataForTable');
		return $method->invoke($mapper, $data, $register, $schema);
	}//end invokePrepare()

	/**
	 * A MagicMapper without its constructor, with the collaborators the method reads.
	 *
	 * @param AbstractLogger $logger The logger
	 *
	 * @return MagicMapper
	 */
	private function mapper(AbstractLogger $logger): MagicMapper {
		$config = $this->createMock(originalClassName: IConfig::class);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnMap([[DateTimeNormalizer::class, new DateTimeNormalizer(logger: $logger)]]);

		$reflection = new ReflectionClass(objectOrClass: MagicMapper::class);
		$mapper     = $reflection->newInstanceWithoutConstructor();
		$collaborators = [
			'db'        => $this->createMock(originalClassName: IDBConnection::class),
			'config'    => $config,
			'logger'    => $logger,
			'container' => $container,
		];
		foreach ($collaborators as $name => $value) {
			$reflection->getProperty($name)->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()
}//end class
