<?php

/**
 * Golden test for MagicFacetHandler::getSimpleFacets().
 *
 * Records the facets it answers (without timings), the column lookups it makes
 * and its log lines, for metadata and property facets of each type, string and
 * list facet configuration, titles, unknown types and a property the caller may
 * not read. The columns do not exist, so each facet answers its empty shape; a
 * date facet on an existing column reaches the unwired search handler. Re-record
 * with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetFieldLabel
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableTermsFacet
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\PropertyRbacHandler
 * @uses   \OCA\OpenRegister\Service\PropertyAccessRequest
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses   \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 */
class MagicFacetHandlerSimpleFacetsGoldenTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private const FIXTURE = __DIR__ . '/fixtures/simple-facets-golden.json';

	private array $events = [];

	private static function cases(): array {
		return [
			'empty config' => [['_facets' => []], []],
			'no facets key' => [['name' => 'x'], []],
			'metadata terms and dates' => [['_facets' => ['@self' => ['register' => ['type' => 'terms'], 'created' => ['type' => 'date_histogram', 'interval' => 'year'], 'updated' => ['type' => 'date_histogram'], 'owner' => [], 'odd' => ['type' => 'range']]], 'name' => 'x'], []],
			'metadata config not an array' => [['_facets' => ['@self' => 'terms', 'name' => ['type' => 'terms']]], []],
			'property facets' => [['_facets' => ['name' => ['type' => 'terms', 'title' => 'Naam'], 'birthDate' => ['type' => 'date_histogram', 'title' => 'Born'], 'odd' => ['type' => 'range', 'title' => 'Odd'], 'plain' => [], 'weird' => ['type' => 'range']]], []],
			'withheld property' => [['_facets' => ['contactPerson' => ['type' => 'terms'], 'name' => ['type' => 'terms']]], []],
			'string config' => [['_facets' => 'name,birthDate'], []],
			'list config' => [['_facets' => ['name', 'contactPerson']], []],
			'metadata date on an existing column' => [['_facets' => ['@self' => ['created' => ['type' => 'date_histogram']]]], ['_created']],
			'property date on an existing column' => [['_facets' => ['birthDate' => ['type' => 'date_histogram']]], ['birth_date']],
		];
	}//end cases()

	public function testGetSimpleFacetsMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$query, $columns]) {
			$this->events = [];
			try {
				$facets = $this->handler(columns: $columns)->getSimpleFacets(
					tableName: 'openregister_table_3_7',
					query: $query,
					register: new Register(),
					schema: $this->schema()
				);
				if (isset($facets['_metrics']) === true) {
					$facets['_metrics']['total_ms'] = 'T';
					$facets['_metrics']['per_facet_ms'] = array_keys($facets['_metrics']['per_facet_ms']);
				}

				$actual[$name] = ['events' => $this->events, 'facets' => $facets];
			} catch (\Throwable $e) {
				$actual[$name] = ['events' => $this->events, 'exception' => $e::class];
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
	}//end testGetSimpleFacetsMatchesGolden()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string', 'facetable' => true, 'title' => 'Name'],
				'birthDate' => ['type' => 'string', 'format' => 'date', 'facetable' => true],
				'contactPerson' => ['type' => 'string', 'facetable' => true, 'authorization' => ['read' => ['authenticated']]],
				'plain' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	private function handler(array $columns): MagicFacetHandler {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$groupManager = $this->createMock(IGroupManager::class);

		$rbac = new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($userSession, $groupManager), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($this->createMock(ConditionMatcher::class), self::stateFieldRuleResolver($userSession, $groupManager)), logger: new \Psr\Log\NullLogger());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($rbac);

		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($columns): IPreparedStatement {
				$rows = array_map(static fn (string $col): array => ['col' => $col], $columns);
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function (?array $params = null): IResult {
						$this->events[] = ['columns of' => $params];
						return $this->createMock(IResult::class);
					}
				);
				$statement->method('fetch')->willReturnCallback(static function () use (&$rows) {
					return array_shift($rows) ?? false;
				});
				return $statement;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		return new MagicFacetHandler($db, $logger, null, null, null, $container, null);
	}//end handler()
}//end class
