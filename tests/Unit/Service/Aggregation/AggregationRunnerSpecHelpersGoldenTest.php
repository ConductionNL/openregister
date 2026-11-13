<?php

/**
 * Golden test for the spec-reading helpers of AggregationRunner.
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
use ReflectionMethod;
use Throwable;

/**
 * Each case calls one spec-reading helper (group and metric fields,
 * native-path eligibility, join key and select parsing, joined-group
 * indexing) via reflection. The result (or exception) is compared with
 * fixtures/spec-helpers-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class AggregationRunnerSpecHelpersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/spec-helpers-golden.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: string, 1: list<mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		$groupBys = [
			'string' => 'status',
			'null' => null,
			'int' => 7,
			'list' => ['status', 'team'],
			'objects' => [['field' => 'status'], ['field' => 7], ['nope' => 1]],
			'keyed' => ['status' => ['order' => 'asc'], 'team' => true],
			'mixed' => ['a', 'k' => ['field' => 'b'], 'c' => 1, 3 => ['x' => 1]],
			'empty' => [],
		];
		foreach ($groupBys as $name => $groupBy) {
			$cases['groupByFields ' . $name] = ['groupByFields', [$groupBy]];
		}

		$metricLists = [
			'null' => null,
			'empty' => [],
			'fields' => [['metric' => 'sum', 'field' => 'a'], ['metric' => 'count'], 'x', ['field' => 3], ['field' => 'b']],
		];
		foreach ($metricLists as $name => $metrics) {
			$cases['metricFields ' . $name] = ['metricFields', [$metrics]];
		}

		$needs = [
			'plain' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'a']],
			'expression' => [['metric' => 'count'], ['metric' => 'expression', 'expression' => '1', 'as' => 'x']],
			'condition' => [['metric' => 'count', 'condition' => ['a' => 1]]],
			'empty-condition' => [['metric' => 'count', 'condition' => []]],
			'condition-not-array' => [['metric' => 'count', 'condition' => 'a']],
			'alias' => [['metric' => 'count', 'as' => 'n']],
			'empty-alias' => [['metric' => 'count', 'as' => '']],
			'alias-not-string' => [['metric' => 'count', 'as' => 3]],
			'not-arrays' => ['count', 3, null],
			'empty' => [],
		];
		foreach ($needs as $name => $metrics) {
			$cases['metricsNeedPhpPath ' . $name] = ['metricsNeedPhpPath', [$metrics]];
		}

		$entries = [
			'string' => 'Budget.amount',
			'plain-string' => 'amount',
			'empty-string' => '',
			'object' => ['field' => 'Budget.amount'],
			'object-alias' => ['field' => 'amount', 'alias' => 'total', 'metric' => 'max'],
			'object-each-metric' => ['field' => 'amount', 'metric' => 'count'],
			'object-bad-metric' => ['field' => 'amount', 'metric' => 'median'],
			'object-metric-int' => ['field' => 'amount', 'metric' => 3],
			'object-no-field' => ['metric' => 'sum'],
			'object-field-int' => ['field' => 3],
			'object-field-empty' => ['field' => ''],
			'int' => 3,
			'null' => null,
		];
		foreach ($entries as $name => $raw) {
			$cases['parseJoinSelectEntry ' . $name] = ['parseJoinSelectEntry', [$raw]];
		}

		$joins = [
			'string' => [['on' => 'Budget.code'], ['status', 'code']],
			'string-first-group' => [['on' => 'Budget.code'], ['status', 'team']],
			'string-no-prefix' => [['on' => 'code'], ['code']],
			'string-empty-column' => [['on' => 'Budget.'], ['code']],
			'empty-string' => [['on' => ''], ['code']],
			'missing' => [[], ['code']],
			'int' => [['on' => 3], ['code']],
			'list' => [['on' => ['code']], ['code']],
			'empty-map' => [['on' => []], ['code']],
			'map' => [['on' => ['code' => 'Budget.code', 'team' => 'team']], ['code', 'team']],
			'map-unknown-parent' => [['on' => ['other' => 'code']], ['code']],
			'map-empty-joined' => [['on' => ['code' => 'Budget.']], ['code']],
			'map-int-key' => [['on' => [0 => 'a', 'code' => 'b']], ['code']],
		];
		foreach ($joins as $name => $args) {
			$cases['resolveJoinKey ' . $name] = ['resolveJoinKey', $args];
		}

		$perAlias = [
			'a' => [['key' => 'x', 'value' => 1], ['key' => null, 'value' => 2], 'junk', ['key' => true, 'value' => 3], ['key' => 7, 'value' => 4]],
			'b' => [['key' => 'x', 'value' => 9], ['key' => ['l' => 1], 'value' => 8], ['value' => 5]],
		];
		$multiAlias = [
			'a' => [
				['keys' => ['code' => 'x', 'team' => 'y'], 'value' => 1],
				['keys' => ['team' => 'y'], 'value' => 2],
				['keys' => 'flat', 'value' => 3],
				['value' => 4],
				['keys' => ['code' => false, 'team' => 1.5], 'value' => 5],
			],
		];
		$cases['indexJoinedGroups single'] = ['indexJoinedGroups', [$perAlias, ['code']]];
		$cases['indexJoinedGroups multi'] = ['indexJoinedGroups', [$multiAlias, ['code', 'team']]];
		$cases['indexJoinedGroups empty'] = ['indexJoinedGroups', [[], ['code', 'team']]];

		$tuples = [
			'mixed' => [null, true, false, 0, '0', 1.5, 'a', ['x' => 1], [1, 2]],
			'empty' => [],
			'one' => ['only'],
		];
		foreach ($tuples as $name => $tuple) {
			$cases['joinTupleKey ' . $name] = ['joinTupleKey', [$tuple]];
		}

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testHelperMatchesTheGolden(string $method, array $args): void {
		$name = (string)$this->dataName();
		$actual = $this->observe(method: $method, args: $args);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'no golden recorded for ' . $name);
		$this->assertSame(json_encode($golden[$name]), json_encode($actual));
	}//end testHelperMatchesTheGolden()

	/**
	 * Call the helper and record its result or exception.
	 *
	 * @param string      $method The helper.
	 * @param list<mixed> $args   Its arguments, positional.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(string $method, array $args): array {
		$reflection = new ReflectionMethod(AggregationRunner::class, $method);
		try {
			$result = $reflection->invoke($this->runner(), ...$args);
			if (is_string($result) === true) {
				$result = bin2hex($result);
			}

			return ['result' => $result];
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
