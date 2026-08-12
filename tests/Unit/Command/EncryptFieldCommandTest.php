<?php

/**
 * Unit tests for the `openregister:encrypt-field` migration command.
 *
 * Covers:
 *  - --property is required.
 *  - A schema that does not flag the given property is skipped entirely.
 *  - Full round trip: a plaintext value in the magic table's `object` blob is
 *    encrypted and persisted as an envelope.
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
	/** @var array<int, array{id:int, object:string}> */
	private array $rows = [];

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $magicMapper;

	private IDBConnection&MockObject $db;

	private ICrypto&MockObject $crypto;

	protected function setUp(): void {
		$this->rows = [
			1 => ['id' => 1, 'object' => json_encode(['name' => 'Jan', 'bsn' => '123456789'])],
			2 => ['id' => 2, 'object' => json_encode(['name' => 'Piet', 'bsn' => '987654321'])],
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

	private function buildQb(): IQueryBuilder&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id_eq');
		$expr->method('isNotNull')->willReturn('col_not_null');

		$qb = $this->createMock(IQueryBuilder::class);
		$ctx = ['type' => null, 'set' => [], 'lastId' => null];

		$qb->method('select')->willReturn($qb);
		$qb->method('from')->willReturn($qb);
		$qb->method('where')->willReturnCallback(function ($cond) use ($qb, &$ctx) {
			if ($cond === 'col_not_null') {
				$ctx['type'] = 'nullify';
			}

			return $qb;
		});
		$qb->method('expr')->willReturn($expr);

		$qb->method('update')->willReturnCallback(function (string $table) use ($qb, &$ctx) {
			if ($ctx['type'] !== 'nullify') {
				$ctx['type'] = 'update';
			}

			return $qb;
		});

		$qb->method('set')->willReturnCallback(function (string $col, mixed $val) use ($qb, &$ctx) {
			$ctx['set'][$col] = $val;
			if ($col !== 'object') {
				// Nullify branch (legacy column) — no matching column in the test
				// table, simulate the "unknown column" failure that is deliberately
				// swallowed by the command.
				throw new DbException('column does not exist');
			}

			return $qb;
		});

		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$ctx) {
				if (is_int($value) === true) {
					$ctx['lastId'] = $value;
				}

				return $value;
			}
		);

		$qb->method('executeQuery')->willReturnCallback(function () {
			$remaining = array_values($this->rows);
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
			if ($ctx['type'] === 'update' && $ctx['lastId'] !== null && isset($ctx['set']['object']) === true) {
				$this->rows[$ctx['lastId']]['object'] = $ctx['set']['object'];
				return 1;
			}

			return 0;
		});

		return $qb;
	}

	private function makeCommand(): EncryptFieldCommand {
		$logger = $this->createMock(LoggerInterface::class);
		$fieldEncryptionHandler = new FieldEncryptionHandler($this->crypto, $logger);

		return new EncryptFieldCommand(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			magicMapper: $this->magicMapper,
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

		foreach ($this->rows as $row) {
			$decoded = json_decode($row['object'], true);
			$this->assertStringStartsWith(FieldEncryptionHandler::ENVELOPE_PREFIX, $decoded['bsn']);
		}
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
