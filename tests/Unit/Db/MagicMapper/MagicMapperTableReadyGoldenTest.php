<?php

/**
 * Golden test for what MagicMapper does when a register+schema table is missing.
 *
 * Pins the existence check, the magic-mapping check, the log calls and the
 * table creation of searchObjectsInRegisterSchemaTable(), resolveCountTable()
 * and ensureTableWhenMagicEnabled().
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Closure;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperTableReadyGoldenTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * @param bool $exists Whether the table exists.
	 *
	 * @return MagicMapper The mapper, with the table checks recorded.
	 */
	private function mapper(bool $exists): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['existsTableForRegisterSchema', 'ensureTableForRegisterSchema', 'getTableNameForRegisterSchema'])
			->getMock();
		$mapper->method('existsTableForRegisterSchema')->willReturnCallback(
			function () use ($exists): bool {
				$this->log[] = 'exists? ' . var_export($exists, true);
				return $exists;
			}
		);
		$mapper->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (): bool {
				$this->log[] = 'ensure table';
				return true;
			}
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_table_3_7');

		$log    = &$this->log;
		$logger = new class ($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string) $context['file']);
				$this->log[] = $level . ' ' . $message . ' ' . json_encode($context);
			}
		};
		Closure::bind(static function (MagicMapper $subject) use ($logger): void {
			$subject->logger = $logger;
		}, null, MagicMapper::class)($mapper);

		return $mapper;
	}//end mapper()

	private function register(bool $magic): Register {
		$register = $this->getMockBuilder(Register::class)->onlyMethods(['isMagicMappingEnabledForSchema'])->getMock();
		$register->setId(3);
		$register->method('isMagicMappingEnabledForSchema')->willReturnCallback(
			function (int $schemaId, ?string $schemaSlug) use ($magic): bool {
				$this->log[] = 'magic? ' . $schemaId . ' ' . $schemaSlug . ' ' . var_export($magic, true);
				return $magic;
			}
		);
		return $register;
	}//end register()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('person');
		return $schema;
	}//end schema()

	public function testSearchOnAMissingTableWithoutMagicMappingFindsNothing(): void {
		$mapper = $this->mapper(exists: false);
		$result = $mapper->searchObjectsInRegisterSchemaTable(query: [], register: $this->register(magic: false), schema: $this->schema());

		$this->assertSame([], $result);
		$this->assertSame(
			[
				'exists? false',
				'magic? 7 person false',
				'debug [MagicMapper] Register+schema table does not exist, should use generic storage {"file":"MagicMapper.php","registerId":3,"schemaId":7}',
			],
			$this->log
		);
	}//end testSearchOnAMissingTableWithoutMagicMappingFindsNothing()

	/**
	 * @return array<string, array{bool, bool, string|null, array<int, string>}>
	 */
	public static function countCases(): array {
		$create = 'debug [MagicMapper] Register+schema table does not exist but magic mapping enabled, creating table'
			. ' {"file":"MagicMapper.php","registerId":3,"schemaId":7}';
		return [
			'table exists'          => [true, false, 'oc_openregister_table_3_7', ['exists? true']],
			'missing, no magic'     => [
				false,
				false,
				null,
				[
					'exists? false',
					'magic? 7 person false',
					'debug [MagicMapper] Register+schema table does not exist for count, returning 0 {"file":"MagicMapper.php","registerId":3,"schemaId":7}',
				],
			],
			'missing, magic on'     => [false, true, 'oc_openregister_table_3_7', ['exists? false', 'magic? 7 person true', $create, 'ensure table']],
		];
	}//end countCases()

	/**
	 * @param bool        $exists   Whether the table exists.
	 * @param bool        $magic    Whether magic mapping is on.
	 * @param string|null $expected The table resolveCountTable() returns.
	 * @param array       $log      The recorded calls.
	 *
	 * @return void
	 *
	 * @dataProvider countCases
	 */
	public function testCountTable(bool $exists, bool $magic, ?string $expected, array $log): void {
		$mapper = $this->mapper(exists: $exists);
		$count  = new ReflectionMethod(MagicMapper::class, 'resolveCountTable');
		$table  = $count->invoke($mapper, $this->register(magic: $magic), $this->schema())?->tableName;

		$this->assertSame($expected, $table);
		$this->assertSame($log, $this->log);
	}//end testCountTable()

	/**
	 * @param bool        $exists   Whether the table exists.
	 * @param bool        $magic    Whether magic mapping is on.
	 * @param string|null $expected Unused here: the count table.
	 * @param array       $log      The recorded calls.
	 *
	 * @return void
	 *
	 * @dataProvider countCases
	 */
	public function testEnsureTableWhenMagicEnabled(bool $exists, bool $magic, ?string $expected, array $log): void {
		unset($expected);
		$mapper = $this->mapper(exists: $exists);
		$ensure = new ReflectionMethod(MagicMapper::class, 'ensureTableWhenMagicEnabled');
		$ensure->invoke($mapper, $this->register(magic: $magic), $this->schema());

		// Without magic mapping this path logs nothing.
		$quiet = array_filter($log, static fn (string $line): bool => str_contains($line, 'returning 0') === false);
		$this->assertSame(array_values($quiet), $this->log);
	}//end testEnsureTableWhenMagicEnabled()
}//end class
