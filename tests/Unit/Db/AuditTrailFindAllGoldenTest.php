<?php

/**
 * Golden test for AuditTrailMapper::findAll().
 *
 * Records every query-builder call per filter set, sort, search term and
 * page, so a decomposition keeps the exact query. Re-record with
 * UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses   \OCA\OpenRegister\Db\AuditTrailFilterTerm
 * @uses   \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 */
class AuditTrailFindAllGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-find-all-golden.json';

	private static function filterSets(): array {
		return [
			'none' => [],
			'null' => null,
			'scalars' => ['object' => 5, 'user' => 'alice', 'register' => '3'],
			'system and unknown' => ['_limit' => 3, '_search' => 'x', 'bogus' => 'y', 'schema' => 2],
			'null checks' => ['object_uuid' => 'IS NOT NULL', 'session' => 'IS NULL'],
			'action prefix' => ['action' => 'portaliq.*'],
			'non-action prefix' => ['user' => 'bob.*'],
			'comma list' => ['action' => 'create, update ,delete', 'register' => 4],
			'list' => ['action' => ['create', 'update']],
			'not in' => ['action' => ['notIn' => 'read,lock']],
			'empty strings' => ['user' => '', 'action' => ','],
		];
	}//end filterSets()

	private static function sorts(): array {
		return [
			'default' => ['created' => 'DESC'],
			'null' => null,
			'mixed' => ['action' => 'desc', 'bogus' => 'DESC', 'user' => 'asc', 'id' => null],
		];
	}//end sorts()

	public function testFindAllMatchesGolden(): void {
		$actual = [];
		foreach (self::filterSets() as $filterName => $filters) {
			foreach (self::sorts() as $sortName => $sort) {
				foreach ([null, '', 'zaak'] as $search) {
					foreach ([[null, null], [10, 20]] as [$limit, $offset]) {
						$name = "{$filterName} / {$sortName} / " . var_export($search, true) . " / {$limit}-{$offset}";
						$actual[$name] = $this->find(args: [$limit, $offset, $filters, $sort, $search]);
					}
				}
			}
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
	}//end testFindAllMatchesGolden()

	private function find(array $args): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [[]];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $value): string => addcslashes($value, '%_\\'));

		$mapper = new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), new NullLogger(), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);

		$outcome = [];
		try {
			$outcome['count'] = count($mapper->findTrail(new ListQuery(($args[0] ?? ListQuery::UNBOUNDED), $args[1], ($args[2] ?? []), ($args[3] ?? []), $args[4])));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end find()
}//end class
