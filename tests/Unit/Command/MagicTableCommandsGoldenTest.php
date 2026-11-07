<?php

/**
 * Golden test for the console output, exit code and writes of the magic table commands.
 *
 * Re-record with RECORD_GOLDEN=1.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\BackfillSystemOwnerCommand;
use OCA\OpenRegister\Command\EncryptFieldCommand;
use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers no metadata on purpose: it drives each command through every collaborator.
 */
class MagicTableCommandsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/Golden/magic-table-commands.json';

	private array $log = [];

	private array $scenario = [];

	private static function register(int $id, ?string $slug, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setSchemas($schemas);

		return $register;
	}//end register()

	private static function schema(int $id, ?string $slug, bool $flagged): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties(['bsn' => ['type' => 'string', 'x-openregister-encrypted' => $flagged]]);

		return $schema;
	}//end schema()

	public static function scenarios(): array {
		return [
			'all tables'        => [[]],
			'dry run'           => [['--dry-run' => true]],
			'one register'      => [['--register' => 'r1']],
			'one schema'        => [['--schema' => 's1']],
			'empty references'  => [['--register' => '', '--schema' => '']],
			'lookup fails'      => [['--register' => 'gone']],
			'no registers'      => [[], ['registers' => []]],
			'no schemas'        => [[], ['schemas' => []]],
			'missing table'     => [[], ['missing' => [11]]],
			'no slugs'          => [[], ['registers' => [[1, null, [11]]], 'schemas' => [[11, null, true]]]],
			'unknown schema id' => [[], ['registers' => [[1, 'r1', [99, '11']]]]],
			'empty table'       => [[], ['rows' => []]],
			'unflagged schema'  => [[], ['schemas' => [[11, 's1', false], [12, 's2', true]]]],
			'failing write'     => [[], ['failing' => 2]],
			'failing crypto'    => [[], ['crypto' => false]],
		];
	}//end scenarios()

	private function registerMapper(): RegisterMapperInterface {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$all = array_map(static fn (array $row): Register => self::register(...$row), ($this->scenario['registers'] ?? [[1, 'r1', [11, 12]], [2, 'r2', [12]]]));
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($all): array {
				$this->log[] = 'registers.findAll ' . json_encode(array_slice($args, -2));
				return $all;
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (mixed ...$args) use ($all): Register {
				$this->log[] = 'registers.find ' . $args[0] . ' ' . json_encode(array_slice($args, -2));
				if ($args[0] === 'gone') {
					throw new RuntimeException('Register gone not found');
				}

				return $all[0];
			}
		);

		return $mapper;
	}//end registerMapper()

	private function schemaMapper(): SchemaMapperInterface {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$all = array_map(static fn (array $row): Schema => self::schema(...$row), ($this->scenario['schemas'] ?? [[11, 's1', true], [12, 's2', true]]));
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($all): array {
				$this->log[] = 'schemas.findAll ' . json_encode(array_slice($args, -2));
				return $all;
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (mixed ...$args) use ($all): Schema {
				$this->log[] = 'schemas.find ' . $args[0] . ' ' . json_encode(array_slice($args, -2));
				return $all[0];
			}
		);

		return $mapper;
	}//end schemaMapper()

	private function magicMapper(): MagicMapperInterface {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('tableExistsForRegisterSchema')->willReturnCallback(
			fn (Register $register, Schema $schema): bool => in_array($schema->getId(), ($this->scenario['missing'] ?? []), true) === false
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_' . $register->getId() . '_' . $schema->getId()
		);

		return $mapper;
	}//end magicMapper()

	private function resultOf(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return (array_shift($rows) ?? false);
		});
		$result->method('closeCursor')->willReturnCallback(function (): bool {
			$this->log[] = 'closeCursor';
			return true;
		});

		return $result;
	}//end resultOf()

	private function queryBuilder(): IQueryBuilder {
		$rows = ($this->scenario['rows'] ?? [
			['_id' => 1, 'bsn' => 'plain-1', 'cnt' => 2],
			['_id' => 2, 'bsn' => 'plain-2'],
			['_id' => 3, 'bsn' => ''],
			['_id' => 4, 'bsn' => 42],
			['_id' => 5, 'bsn' => FieldEncryptionHandler::ENVELOPE_PREFIX . 'done'],
		]);
		$id = null;
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (string $column, mixed $value) use (&$id): string {
			$id = $value;
			return "$column = " . json_encode($value);
		});
		$expr->method('isNotNull')->willReturnCallback(static fn (string $column): string => "$column IS NOT NULL");
		$function = $this->createMock(IFunctionBuilder::class);
		$function->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($function);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		foreach (['select', 'from', 'where', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnCallback(function (mixed ...$args) use ($method, $qb): IQueryBuilder {
				$this->log[] = $method . ' ' . json_encode(array_map(static fn (mixed $arg): mixed => is_object($arg) === true ? 'expr' : $arg, $args));
				return $qb;
			});
		}

		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->resultOf($rows));
		$qb->method('executeStatement')->willReturnCallback(function () use (&$id): int {
			if ($id === ($this->scenario['failing'] ?? null)) {
				throw new RuntimeException('value too long');
			}

			$this->log[] = 'write';
			return 7;
		});

		return $qb;
	}//end queryBuilder()

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return $db;
	}//end db()

	private function encryption(): FieldEncryptionHandler {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			function (string $plain): string {
				if (($this->scenario['crypto'] ?? true) === false) {
					throw new RuntimeException('no key');
				}

				return "cipher($plain)";
			}
		);

		return new FieldEncryptionHandler($crypto, new NullLogger());
	}//end encryption()

	private function selection(): MagicTableSelection {
		return new MagicTableSelection($this->registerMapper(), $this->schemaMapper(), $this->magicMapper());
	}//end selection()

	private function backfill(): Command {
		return new BackfillSystemOwnerCommand($this->selection(), $this->db());
	}//end backfill()

	private function encrypt(): Command {
		return new EncryptFieldCommand($this->selection(), $this->encryption(), $this->db());
	}//end encrypt()

	private function execute(Command $command, array $input): array {
		$tester = new CommandTester($command);
		$exit = $tester->execute($input);

		return ['exit' => $exit, 'output' => explode("\n", $tester->getDisplay()), 'calls' => $this->log];
	}//end execute()

	private function recorded(string $key, array $actual): void {
		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$key] = $actual;
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertNotSame([], $actual['output']);
		$this->assertSame($golden[$key] ?? null, $actual, $key);
	}//end recorded()

	#[DataProvider('scenarios')]
	public function testBackfillMatchesTheGolden(array $input, array $scenario = []): void {
		$this->scenario = $scenario;
		$this->recorded('backfill ' . $this->dataName(), $this->execute($this->backfill(), $input));
	}//end testBackfillMatchesTheGolden()

	#[DataProvider('scenarios')]
	public function testEncryptMatchesTheGolden(array $input, array $scenario = []): void {
		$this->scenario = $scenario;
		$this->recorded('encrypt ' . $this->dataName(), $this->execute($this->encrypt(), ['--property' => 'bsn'] + $input));
	}//end testEncryptMatchesTheGolden()

	public function testEncryptWithoutAPropertyMatchesTheGolden(): void {
		$this->recorded('encrypt no property', $this->execute($this->encrypt(), []));
	}//end testEncryptWithoutAPropertyMatchesTheGolden()
}//end class
