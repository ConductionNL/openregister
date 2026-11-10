<?php

/**
 * Unit tests for the `openregister:encrypt-field` migration command.
 *
 * Covers:
 *  - --property is required.
 *  - A schema that does not flag the given property is skipped entirely.
 *  - Full round trip: a plaintext value in the property's own column of the magic
 *    table is encrypted and persisted as an envelope.
 *  - The fake table has the columns of a real magic table, and refuses any other.
 *  - --dry-run reports counts without writing.
 *  - Idempotency: re-running only encrypts remaining plaintext rows, not
 *    already-encrypted ones.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-existing-plaintext-values-can-be-migrated-to-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\EncryptFieldCommand;
use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class EncryptFieldCommandTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private ?string $failingUpdateOf = null;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $magicMapper;

	private IDBConnection&MockObject $db;

	private ICrypto&MockObject $crypto;

	protected function setUp(): void {
		$this->rows = [
			1 => ['_id' => 1, 'name' => 'Jan', 'bsn' => '123456789', 'bsn_nummer' => 'A1'],
			2 => ['_id' => 2, 'name' => 'Piet', 'bsn' => '987654321', 'bsn_nummer' => null],
		];

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(
			fn (string $plain): string => 'CIPHER(' . $plain . ')'
		);
	}

	private function makeSchema(int $id, array $properties): Schema {
		$schema = new Schema();
		$ref = new \ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, $id);
		$schema->setSlug('test-schema');
		$schema->setProperties($properties);
		return $schema;
	}

	private function makeRegister(int $id, array $schemaIds): Register {
		$register = new Register();
		$ref = new \ReflectionClass($register);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($register, $id);
		$register->setSlug('test-register');
		$register->setSchemas($schemaIds);
		return $register;
	}

	private function buildDb(): IDBConnection&MockObject {
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->buildQb());
		return $this->db;
	}

	private function assertColumnExists(string $column): void {
		if (array_key_exists($column, $this->rows[1]) === false) {
			throw new DbException('column does not exist: ' . $column);
		}
	}

	private function buildQb(): IQueryBuilder&MockObject {
		$ctx = ['columns' => [], 'set' => [], 'id' => null, 'notNull' => null];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (string $column, mixed $value) use (&$ctx): string {
			$this->assertColumnExists($column);
			$ctx['id'] = $value;
			return 'eq';
		});
		$expr->method('isNotNull')->willReturnCallback(function (string $column) use (&$ctx): string {
			$this->assertColumnExists($column);
			$ctx['notNull'] = $column;
			return 'not null';
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnCallback(function (string ...$columns) use ($qb, &$ctx) {
			array_map($this->assertColumnExists(...), $columns);
			$ctx['columns'] = $columns;
			return $qb;
		});
		$qb->method('from')->willReturn($qb);
		$qb->method('where')->willReturn($qb);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturn($qb);
		$qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb, &$ctx) {
			$this->assertColumnExists($column);
			$ctx['set'][$column] = $value;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnArgument(0);

		$qb->method('executeQuery')->willReturnCallback(function () use (&$ctx) {
			$remaining = array_filter(
				array_map(fn (array $row): array => array_intersect_key($row, array_flip($ctx['columns'])), $this->rows),
				fn (array $row): bool => $row[$ctx['notNull']] !== null
			);
			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturnCallback(
				function () use (&$remaining) {
					return (empty($remaining) === true) ? false : array_shift($remaining);
				}
			);
			$result->method('closeCursor')->willReturn(true);
			return $result;
		});

		$qb->method('executeStatement')->willReturnCallback(function () use (&$ctx) {
			if ($this->rows[$ctx['id']]['name'] === $this->failingUpdateOf) {
				throw new DbException('value too long');
			}

			$this->rows[$ctx['id']] = array_replace($this->rows[$ctx['id']], $ctx['set']);
			return 1;
		});

		return $qb;
	}

	private function makeCommand(): EncryptFieldCommand {
		$logger = $this->createMock(LoggerInterface::class);
		$fieldEncryptionHandler = new FieldEncryptionHandler($this->crypto, $logger);

		return new EncryptFieldCommand(
			selection: new MagicTableSelection(new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->magicMapper)),
			fieldEncryptionHandler: $fieldEncryptionHandler,
			db: $this->buildDb()
		);
	}

	public function testPropertyOptionIsRequired(): void {
		$command = $this->makeCommand();
		$tester = new CommandTester($command);
		$exitCode = $tester->execute([]);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString('--property is required', $tester->getDisplay());
	}

	public function testSchemaNotFlaggingThePropertyIsSkipped(): void {
		$register = $this->makeRegister(1, [10]);
		$schema = $this->makeSchema(10, ['bsn' => ['type' => 'string']]); // NOT flagged encrypted

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->magicMapper->expects($this->never())->method('tableExistsForRegisterSchema');

		$command = $this->makeCommand();
		$tester = new CommandTester($command);
		$exitCode = $tester->execute(['--property' => 'bsn']);

		$this->assertSame(0, $exitCode);
	}

	public function testEncryptsPlaintextValuesAndPersistsEnvelope(): void {
		$register = $this->makeRegister(1, [10]);
		$schema = $this->makeSchema(10, ['bsn' => ['type' => 'string', 'x-openregister-encrypted' => true]]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_10');

		$command = $this->makeCommand();
		$tester = new CommandTester($command);
		$exitCode = $tester->execute(['--property' => 'bsn']);

		$this->assertSame(0, $exitCode);
		$this->assertStringContainsString('scanned=2', $tester->getDisplay());
		$this->assertStringContainsString('encrypted=2', $tester->getDisplay());

		$this->assertSame(FieldEncryptionHandler::ENVELOPE_PREFIX . 'CIPHER(123456789)', $this->rows[1]['bsn']);
		$this->assertSame(FieldEncryptionHandler::ENVELOPE_PREFIX . 'CIPHER(987654321)', $this->rows[2]['bsn']);
		$this->assertSame('Jan', $this->rows[1]['name']);
	}

	private function flagged(string $property): void {
		$schema = $this->makeSchema(10, [$property => ['type' => 'string', 'x-openregister-encrypted' => true]]);
		$this->registerMapper->method('findAll')->willReturn([$this->makeRegister(1, [10])]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_10');
	}

	public function testACamelCasePropertyIsFoundInItsSnakeCaseColumn(): void {
		$this->flagged('bsnNummer');

		$tester = new CommandTester($this->makeCommand());
		$exitCode = $tester->execute(['--property' => 'bsnNummer']);

		$this->assertSame(0, $exitCode);
		$this->assertStringContainsString('scanned=1 encrypted=1 failed=0', $tester->getDisplay());
		$this->assertSame(FieldEncryptionHandler::ENVELOPE_PREFIX . 'CIPHER(A1)', $this->rows[1]['bsn_nummer']);
		$this->assertNull($this->rows[2]['bsn_nummer']);
	}

	public function testAnEmptyValueIsNotCounted(): void {
		$this->rows[2]['bsn'] = '';
		$this->flagged('bsn');

		$tester = new CommandTester($this->makeCommand());
		$tester->execute(['--property' => 'bsn']);

		$this->assertStringContainsString('scanned=1 encrypted=1 failed=0', $tester->getDisplay());
		$this->assertSame('', $this->rows[2]['bsn']);
	}

	public function testARowThatCannotBeWrittenFailsTheRunAndTheOthersStillEncrypt(): void {
		$this->failingUpdateOf = 'Jan';
		$this->flagged('bsn');

		$tester = new CommandTester($this->makeCommand());
		$exitCode = $tester->execute(['--property' => 'bsn']);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString("row id=1: failed to encrypt 'bsn': value too long", $tester->getDisplay());
		$this->assertStringContainsString('scanned=2 encrypted=1 failed=1', $tester->getDisplay());
		$this->assertSame('123456789', $this->rows[1]['bsn']);
		$this->assertSame(FieldEncryptionHandler::ENVELOPE_PREFIX . 'CIPHER(987654321)', $this->rows[2]['bsn']);
	}

	public function testAValueThatCannotBeEncryptedFailsTheRun(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willThrowException(new \RuntimeException('no key'));
		$this->crypto = $crypto;
		$this->flagged('bsn');

		$tester = new CommandTester($this->makeCommand());
		$exitCode = $tester->execute(['--property' => 'bsn']);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString('encrypted=0 failed=2', $tester->getDisplay());
		$this->assertSame('123456789', $this->rows[1]['bsn']);
	}

	public function testDryRunDoesNotWrite(): void {
		$register = $this->makeRegister(1, [10]);
		$schema = $this->makeSchema(10, ['bsn' => ['type' => 'string', 'x-openregister-encrypted' => true]]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_10');

		$originalRows = $this->rows;

		$command = $this->makeCommand();
		$tester = new CommandTester($command);
		$tester->execute(['--property' => 'bsn', '--dry-run' => true]);

		$this->assertSame($originalRows, $this->rows, 'Dry run must not write to the store');
	}

	public function testReRunIsIdempotentAndSkipsAlreadyEncryptedRows(): void {
		$register = $this->makeRegister(1, [10]);
		$schema = $this->makeSchema(10, ['bsn' => ['type' => 'string', 'x-openregister-encrypted' => true]]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_10');

		$command = $this->makeCommand();

		$first = new CommandTester($command);
		$first->execute(['--property' => 'bsn']);
		$this->assertStringContainsString('encrypted=2', $first->getDisplay());

		$second = new CommandTester($command);
		$second->execute(['--property' => 'bsn']);
		$this->assertStringContainsString('encrypted=0', $second->getDisplay(), 'Second run must not re-encrypt');
	}
}
