<?php

/**
 * The ObjectEntity convertRowToObjectEntity builds from one magic-table row, field by field.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every metadata attribute, property value, coercion call and log line of a row conversion.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicStatisticsHandlerRowConversionTest extends TestCase {

	/**
	 * Every call to the type converter and the date normalizer.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Every log line, as [level, message, context without file and line].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * A handler with recording collaborators.
	 *
	 * @return MagicStatisticsHandler The handler.
	 */
	private function handler(): MagicStatisticsHandler {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$converter = $this->createMock(SchemaTypeConverter::class);
		$converter->method('convertValue')->willReturnCallback(
			function (mixed $value, string $schemaType): mixed {
				$this->calls[] = 'convert('.json_encode($value).', '.$schemaType.')';
				if ($schemaType === 'integer' && is_numeric($value) === true) {
					return (int)$value;
				}

				return $value;
			}
		);

		$normalizer = $this->createMock(DateTimeNormalizer::class);
		$normalizer->method('normalize')->willReturnCallback(
			function (mixed $value): ?DateTimeImmutable {
				$this->calls[] = 'normalize('.json_encode($value).')';
				if (trim((string)$value) === '') {
					return null;
				}

				return new DateTimeImmutable('2026-03-04 05:06:07');
			}
		);
		$normalizer->method('formatDatabaseValueForIso8601')->willReturnCallback(
			function (mixed $value): ?string {
				$this->calls[] = 'iso('.json_encode($value).')';
				return 'iso:'.$value;
			}
		);

		return new MagicStatisticsHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			dateTimeNormalizer: $normalizer,
			schemaTypeConverter: $converter
		);
	}//end handler()

	/**
	 * A register and a schema with the given properties.
	 *
	 * @param array<string, array> $properties The schema's property definitions.
	 *
	 * @return array{0: Register, 1: Schema} The context.
	 */
	private function context(array $properties): array {
		$register = new Register();
		$register->setId(4);
		$schema = new Schema();
		$schema->setId(9);
		$schema->setProperties($properties);
		return [$register, $schema];
	}//end context()

	/**
	 * What an entity holds, as plain values.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return array<string, mixed> The values.
	 */
	private function snapshot(ObjectEntity $entity): array {
		$out = [];
		foreach (['id', 'uuid', 'register', 'schema', 'object', 'owner', 'organisation', 'files', 'relations', 'locked', 'authorization', 'validation', 'deleted', 'geo', 'retention', 'groups', 'created', 'updated', 'expires', 'schemaVersion', 'slug'] as $attribute) {
			$value = $entity->{'get'.ucfirst($attribute)}();
			if ($value instanceof DateTimeInterface) {
				$value = 'date:'.$value->format('Y-m-d H:i:s');
			}

			$out[$attribute] = $value;
		}

		return $out;
	}//end snapshot()

	/**
	 * A full row: metadata dates and JSON, mapped and unmapped property columns,
	 * typed and formatted values, and the NULL placeholders of a UNION.
	 *
	 * @return void
	 */
	public function testAFullRow(): void {
		[$register, $schema] = $this->context(
			[
				'e-mailadres' => ['type' => 'string'],
				'aantal' => ['type' => 'integer'],
				'geboortedatum' => ['type' => 'string', 'format' => 'date'],
				'leeg' => ['type' => 'string', 'format' => 'date'],
				'gezien' => ['type' => 'string', 'format' => 'date-time'],
				'opmerking' => ['type' => 'string'],
				'getal' => ['type' => 'number', 'format' => 'date'],
			]
		);
		$row = [
			'_id' => '12',
			'_uuid' => 'u-12',
			'_register' => '4',
			'_schema' => '9',
			'_owner' => 'alice',
			'_organisation' => null,
			'_slug' => 'twaalf',
			'_created' => '2026-01-02 03:04:05',
			'_updated' => null,
			'_expires' => '2027-01-01 00:00:00',
			'_files' => '[1,2]',
			'_relations' => '{"a":"b"}',
			'_locked' => null,
			'_authorization' => '{"read":["g"]}',
			'_groups' => '["g1"]',
			'_deleted' => '{"by":"bob"}',
			'_schema_version' => '2.0.0',
			'_row_conversion_probe' => 'x',
			'e_mailadres' => 'a@b.nl',
			'aantal' => '3',
			'geboortedatum' => '1990-05-06',
			'leeg' => ' ',
			'gezien' => '2026-02-03 10:11:12',
			'opmerking' => null,
			'getal' => 7,
			'other_schema_column' => null,
			'loose_column' => 'x',
		];

		$entity = $this->handler()->convertRowToObjectEntity($row, $register, $schema);

		$this->assertNotNull($entity);
		$this->assertSame(
			[
				'id' => 12,
				'uuid' => 'u-12',
				'register' => '4',
				'schema' => '9',
				'object' => [
					'id' => 'u-12',
					'e-mailadres' => 'a@b.nl',
					'aantal' => 3,
					'geboortedatum' => '2026-03-04',
					'leeg' => null,
					'gezien' => 'iso:2026-02-03 10:11:12',
					'opmerking' => null,
					'getal' => 7,
					'looseColumn' => 'x',
				],
				'owner' => 'alice',
				'organisation' => null,
				'files' => [1, 2],
				'relations' => ['a' => 'b'],
				'locked' => null,
				'authorization' => ['read' => ['g']],
				'validation' => [],
				'deleted' => ['by' => 'bob'],
				'geo' => [],
				'retention' => [],
				'groups' => ['g1'],
				'created' => 'date:2026-01-02 03:04:05',
				'updated' => null,
				'expires' => 'date:2027-01-01 00:00:00',
				'schemaVersion' => '2.0.0',
				'slug' => 'twaalf',
			],
			$this->snapshot($entity)
		);
		$this->assertSame(
			[
				'convert("a@b.nl", string)',
				'convert("3", integer)',
				'convert("1990-05-06", string)',
				'normalize("1990-05-06")',
				'convert(" ", string)',
				'normalize(" ")',
				'convert("2026-02-03 10:11:12", string)',
				'iso("2026-02-03 10:11:12")',
				'convert(null, string)',
				'convert(7, number)',
				'convert("x", string)',
			],
			$this->calls
		);
		$this->assertSame(
			[['debug', '[MagicStatisticsHandler] Skipped a metadata column ObjectEntity has no attribute for', ['field' => 'row_conversion_probe']]],
			$this->logs
		);
	}//end testAFullRow()

	/**
	 * A row without an id or uuid still converts, and says so.
	 *
	 * @return void
	 */
	public function testANullIdentityIsLogged(): void {
		[$register, $schema] = $this->context([]);
		$entity = $this->handler()->convertRowToObjectEntity(['_id' => null, '_uuid' => null, '_owner' => null], $register, $schema);

		$this->assertNotNull($entity);
		$this->assertNull($entity->getId());
		$this->assertNull($entity->getUuid());
		$this->assertSame(['id' => null], $entity->getObject());
		$this->assertSame(
			[
				['warning', '[MagicStatisticsHandler] Critical metadata field is null', ['field' => 'id']],
				['warning', '[MagicStatisticsHandler] Critical metadata field is null', ['field' => 'uuid']],
			],
			$this->logs
		);
	}//end testANullIdentityIsLogged()

	/**
	 * A non-numeric id reaches the entity only through its setter, as 0.
	 *
	 * @return void
	 */
	public function testANonNumericIdIsCastBySetter(): void {
		[$register, $schema] = $this->context([]);
		$entity = $this->handler()->convertRowToObjectEntity(['_id' => 'abc', '_uuid' => 'u-1'], $register, $schema);

		$this->assertNotNull($entity);
		$this->assertSame(0, $entity->getId());
		$this->assertSame('u-1', $entity->getUuid());
	}//end testANonNumericIdIsCastBySetter()

	/**
	 * A metadata date that does not parse fails the row with a logged error.
	 *
	 * @return void
	 */
	public function testAnUnparsableDateFailsTheRow(): void {
		[$register, $schema] = $this->context([]);
		$entity = $this->handler()->convertRowToObjectEntity(['_uuid' => 'u-bad', '_updated' => 'not a date'], $register, $schema);

		$this->assertNull($entity);
		$this->assertCount(1, $this->logs);
		$this->assertSame('error', $this->logs[0][0]);
		$this->assertSame('[MagicStatisticsHandler] Failed to convert row to ObjectEntity', $this->logs[0][1]);
		$this->assertSame('u-bad', $this->logs[0][2]['uuid']);
		$this->assertStringContainsString('not a date', $this->logs[0][2]['error']);
	}//end testAnUnparsableDateFailsTheRow()
}//end class
