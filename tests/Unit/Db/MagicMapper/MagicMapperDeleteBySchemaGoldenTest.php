<?php

/**
 * Golden test for MagicMapper::deleteObjectsBySchema().
 *
 * Records the query each bulk delete builds, its log lines and the count it
 * returns, for hard and soft delete, a missing table and a signed-in or
 * anonymous caller. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperDeleteBySchemaGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/delete-by-schema-golden.json';

	private array $events = [];

	private static function cases(): array {
		return [
			'hard delete' => [true, true, 'alice'],
			'soft delete / signed in' => [false, true, 'alice'],
			'soft delete / anonymous' => [false, true, null],
			'hard delete / no table' => [true, false, 'alice'],
			'soft delete / no table' => [false, false, null],
		];
	}//end cases()

	public function testDeleteObjectsBySchemaMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$hard, $exists, $uid]) {
			$this->events = [];
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$register = new Register();
			$register->setId(3);
			$schema = new Schema();
			$schema->setId(7);

			$count = $this->makeMapper(exists: $exists, uid: $uid)->deleteObjectsBySchema($register, $schema, $hard);
			$actual[$name] = [
				'events' => $this->events,
				'queries' => preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', 'TIME', $this->queryLog),
				'count' => $count,
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testDeleteObjectsBySchemaMatchesGolden()

	private function makeMapper(bool $exists, ?string $uid): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$tables = $this->createMock(MagicTableHandler::class);
		$tables->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_7');
		$tables->method('tableExistsForRegisterSchema')->willReturn($exists);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'userSession' => $session, 'tableHandler' => $tables] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()
}//end class
