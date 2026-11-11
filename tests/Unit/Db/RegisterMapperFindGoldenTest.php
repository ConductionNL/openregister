<?php

/**
 * Golden test for RegisterMapper::find().
 *
 * Records the query each lookup builds, the log lines it writes, which rows the
 * request cache answers and what it returns or throws, for numeric, slug and
 * uuid identifiers, both flags and a row that exists before or after the
 * filters. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\RegisterMapperServices;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 */
class RegisterMapperFindGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/register-mapper-find-golden.json';

	private const ROW = ['id' => 7, 'uuid' => 'UUID-Seven', 'slug' => 'Seven-Slug', 'title' => 'Seven'];

	private array $logs = [];

	private static function cases(): array {
		$found = [[self::ROW], [self::ROW]];
		$cases = [];
		foreach (['7', 7, 'Seven-Slug', 'uuid-seven', '7abc'] as $id) {
			foreach ([[false, false], [true, false], [false, true], [true, true]] as [$rbac, $mt]) {
				$cases[var_export($id, true) . ' rbac ' . var_export($rbac, true) . ' mt ' . var_export($mt, true)] = [$id, $rbac, $mt, $found];
			}
		}

		$cases['missing everywhere'] = ['missing', false, false, [[], []]];
		$cases['filtered away'] = ['7', false, false, [[self::ROW], []]];
		$cases['null slug and uuid'] = ['7', false, false, [[['id' => 7, 'uuid' => null, 'slug' => null]], [['id' => 7, 'uuid' => null, 'slug' => null]]]];

		return $cases;
	}//end cases()

	public function testFindMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$id, $rbac, $mt, $rows]) {
			$this->queryLog = [];
			$this->logs = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;
			$mapper = $this->makeMapper();
			$outcome = [];
			try {
				$register = $mapper->find($id, $rbac, $mt);
				$outcome['found'] = $register->getId();
			} catch (\Exception $e) {
				$outcome['thrown'] = get_class($e);
			}

			$cache = (new \ReflectionProperty(RegisterMapper::class, 'findCache'))->getValue($mapper);
			$outcome['cacheKeys'] = array_keys($cache);
			$outcome['queries'] = $this->queryLog;
			$outcome['logs'] = $this->logs;

			// A second lookup by every cached key must be answered without a query.
			$this->queryLog = [];
			foreach (array_keys($cache) as $key) {
				[$cachedId, $r, $m] = explode(':', $key);
				$mapper->find($cachedId, $r === '1', $m === '1');
			}

			$outcome['queriesOnCacheHit'] = count($this->queryLog);
			$actual[$name] = $outcome;
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
	}//end testFindMatchesGolden()

	private function makeMapper(): RegisterMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['error']);
					$this->logs[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		return new RegisterMapper(
			db: $db,
			tenancy: new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $logger
			)
		);
	}//end makeMapper()
}//end class
