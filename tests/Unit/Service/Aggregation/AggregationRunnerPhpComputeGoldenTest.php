<?php

/**
 * Golden test for the PHP computation helpers of AggregationRunner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationAccess;
use OCA\OpenRegister\Service\Aggregation\AggregationCaller;
use OCA\OpenRegister\Service\Aggregation\AggregationPresentation;
use OCA\OpenRegister\Service\Aggregation\AggregationSources;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Aggregation\RowAggregation;
use ReflectionMethod;
use Throwable;

/**
 * Each case calls applyFilter(), computeMetrics() or computeGrouped() on a
 * fixed row set. The result (or exception) is compared with
 * fixtures/php-compute-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class AggregationRunnerPhpComputeGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/php-compute-golden.json';

	private const ROWS = [
		['status' => 'open', 'amount' => '10', 'team' => 'a', 'tags' => ['x', 'y'], 'at' => '2026-05-01T10:00:00Z', 'n' => null],
		['status' => 'open', 'amount' => 20, 'team' => 'b', 'tags' => ['y'], 'at' => '2026-05-03T10:00:00Z', 'n' => 1],
		['status' => 'done', 'amount' => 5.5, 'team' => 'a', 'tags' => [], 'at' => '2026-04-01T00:00:00Z', 'n' => 0],
		['status' => 'done', 'amount' => 'x', 'team' => null, 'tags' => 'z', 'at' => 'later', 'n' => ''],
		['status' => 1, 'team' => 'c'],
	];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$filters = [
			'scalar' => ['status' => 'open'],
			'scalar-int-vs-string' => ['status' => '1'],
			'list' => ['team' => ['a', 'c']],
			'multi-value-row' => ['tags' => 'y'],
			'eq' => ['team' => ['eq' => 'a']],
			'ne' => ['team' => ['ne' => 'a']],
			'in' => ['team' => ['in' => ['b', 'c']]],
			'in-not-array' => ['team' => ['in' => 'b']],
			'notIn' => ['team' => ['notIn' => ['a']]],
			'notIn-not-array' => ['team' => ['notIn' => 'a']],
			'gt' => ['amount' => ['gt' => 5.5]],
			'gte' => ['amount' => ['gte' => 10]],
			'lt' => ['amount' => ['lt' => 20]],
			'lte' => ['amount' => ['lte' => '10']],
			'range-pair' => ['amount' => ['gte' => 5, 'lt' => 20]],
			'date-gt' => ['at' => ['gt' => '2026-05-02']],
			'date-lte' => ['at' => ['lte' => '2026-05-01T10:00:00Z']],
			'null-operand' => ['amount' => ['gt' => null]],
			'isnull-true' => ['n' => ['isnull' => true]],
			'isnull-false' => ['n' => ['isnull' => false]],
			'isnull-string' => ['n' => ['isnull' => 'true']],
			'is-null-scalar' => ['n' => 'IS NULL'],
			'is-not-null-scalar' => ['n' => 'IS NOT NULL'],
			'unknown-operator' => ['team' => ['not-in' => ['a']]],
			'unknown-after-known' => ['team' => ['eq' => 'a', 'between' => [1, 2]]],
			'unknown-on-missing-row' => ['missing' => ['equals' => 1]],
			'int-operator-key' => ['team' => [0 => 'a', 'x' => 1]],
		];
		$cases = [];
		foreach ($filters as $name => $filter) {
			$cases['filter ' . $name] = ['applyFilter', ['filter' => $filter]];
		}

		$metrics = [
			'plain' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount'], ['metric' => 'avg', 'field' => 'amount']],
			'alias' => [['metric' => 'sum', 'field' => 'amount', 'as' => 'total'], ['metric' => 'max', 'field' => 'amount', 'as' => '']],
			'condition' => [
				['metric' => 'count', 'condition' => ['status' => 'open'], 'as' => 'open'],
				['metric' => 'count', 'condition' => [], 'as' => 'all'],
			],
			'condition-not-array' => [['metric' => 'count', 'condition' => 'open']],
			'expression' => [
				['metric' => 'sum', 'field' => 'amount', 'as' => 'a'],
				['metric' => 'count', 'as' => 'c'],
				['metric' => 'expression', 'expression' => 'a / c', 'as' => 'mean'],
			],
			'expression-no-alias' => [['metric' => 'expression', 'expression' => '1 + 1']],
			'expression-empty-alias' => [['metric' => 'expression', 'expression' => '1 + 1', 'as' => '']],
			'expression-later-alias' => [['metric' => 'expression', 'expression' => 'b + 1', 'as' => 'x'], ['metric' => 'count', 'as' => 'b']],
			'duplicate-key' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount'], ['metric' => 'count']],
			'unknown-metric' => [['metric' => 'median', 'field' => 'amount'], ['metric' => 'count_distinct', 'field' => 'team']],
			'min-max' => [['metric' => 'min', 'field' => 'amount'], ['metric' => 'max', 'field' => 'missing']],
			'condition-unknown-operator' => [['metric' => 'count', 'condition' => ['team' => ['nope' => 1]]]],
		];
		foreach ($metrics as $name => $list) {
			$cases['metrics ' . $name] = ['computeMetrics', ['metrics' => $list]];
		}

		$groupings = [
			'single' => ['metric' => 'sum', 'field' => 'amount', 'groupFields' => ['status']],
			'single-count' => ['metric' => 'count', 'field' => null, 'groupFields' => ['team']],
			'multi' => ['metric' => 'count', 'field' => null, 'groupFields' => ['status', 'team']],
			'missing-field' => ['metric' => 'max', 'field' => 'amount', 'groupFields' => ['nowhere']],
			'multi-metric' => [
				'metric' => 'count',
				'field' => null,
				'groupFields' => ['status'],
				'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount', 'as' => 's']],
			],
			'multi-metric-multi-field' => [
				'metric' => 'count',
				'field' => null,
				'groupFields' => ['status', 'team'],
				'metrics' => [['metric' => 'count'], ['metric' => 'min', 'field' => 'amount']],
			],
			'one-metric-list' => ['metric' => 'avg', 'field' => 'amount', 'groupFields' => ['status'], 'metrics' => [['metric' => 'count']]],
			'array-group-value' => ['metric' => 'count', 'field' => null, 'groupFields' => ['tags']],
			'unknown-metric' => ['metric' => 'median', 'field' => 'amount', 'groupFields' => ['status']],
		];
		foreach ($groupings as $name => $grouping) {
			$cases['grouped ' . $name] = ['computeGrouped', $grouping];
		}

		$cases['grouped no rows'] = ['computeGrouped', ['metric' => 'count', 'field' => null, 'groupFields' => ['status'], 'rows' => []]];
		$cases['metrics no rows'] = ['computeMetrics', ['metrics' => $metrics['plain'], 'rows' => []]];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testComputeMatchesTheGolden(string $method, array $args): void {
		$name = (string)$this->dataName();
		$actual = $this->observe(method: $method, args: $args);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'no golden recorded for ' . $name);
		$this->assertSame(
			json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION),
			json_encode($actual, JSON_PRESERVE_ZERO_FRACTION)
		);
	}//end testComputeMatchesTheGolden()

	/**
	 * Call the helper and record its result or exception.
	 *
	 * @param string               $method The helper.
	 * @param array<string, mixed> $args   Its arguments; `rows` defaults to the fixed row set.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(string $method, array $args): array {
		$args['rows'] = ($args['rows'] ?? self::ROWS);
		if ($method === 'computeGrouped') {
			$args = [
				'rows'        => $args['rows'],
				'aggregation' => new RowAggregation(
					metric: $args['metric'],
					field: $args['field'],
					groupFields: $args['groupFields'],
					metrics: ($args['metrics'] ?? null)
				),
			];
		}

		$reflection = new ReflectionMethod(AggregationRunner::class, $method);
		try {
			return ['result' => $reflection->invoke($this->runner(), ...$args)];
		} catch (Throwable $e) {
			return ['error' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end observe()

	/**
	 * A runner with inert collaborators.
	 *
	 * @return AggregationRunner
	 */
	private function runner(): AggregationRunner {
		$session = $this->createMock(IUserSession::class);
		return new AggregationRunner(
			sources: new AggregationSources(
				catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
				db: $this->createMock(IDBConnection::class),
				cache: $this->createMock(AggregationCache::class)
			),
			access: new AggregationAccess(
				permissionHandler: $this->createMock(PermissionHandler::class),
				organizationHandler: $this->createMock(MagicOrganizationHandler::class)
			),
			caller: new AggregationCaller(
				userSession: $session,
				organisationService: $this->createMock(OrganisationService::class),
				placeholders: new PlaceholderResolver($session)
			),
			presentation: new AggregationPresentation(
				translationHandler: $this->createMock(TranslationHandler::class),
				languageService: $this->createMock(LanguageService::class)
			)
		);
	}//end runner()
}//end class
