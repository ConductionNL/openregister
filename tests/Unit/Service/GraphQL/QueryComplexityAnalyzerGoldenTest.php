<?php

/**
 * Golden test for QueryComplexityAnalyzer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   Unit\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use OCA\OpenRegister\Search\Service\GraphQL\QueryComplexityAnalyzer;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case analyses one query under a config and records the result or the
 * error (message and extensions), plus every config read. Compared with
 * fixtures/query-complexity-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\QueryComplexityAnalyzer
 */
class QueryComplexityAnalyzerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/query-complexity-golden.json';

	/**
	 * Case => [query, variables, config, schema costs].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>|null, 2: array<string, string>, 3: array<string, int>}>
	 */
	public static function cases(): array {
		$nested = '{ a { b { c { d } } } }';
		$lists = '{ items(first: 5) { name owner(first: 3) { name } } other(first: 0) { x } neg(first: -4) { y } }';
		return [
			'flat'                         => ['{ a b c }', null, [], []],
			'nested defaults'              => [$nested, null, [], []],
			'introspection skipped'        => ['{ __schema { types { name } } __typename a }', null, [], []],
			'list multipliers'             => [$lists, null, [], []],
			'variable multiplier'          => ['query Q($n: Int) { items(first: $n) { a b } }', ['n' => '7'], [], []],
			'variable not numeric'         => ['query Q($n: Int) { items(first: $n) { a } }', ['n' => 'x'], [], []],
			'variable missing'             => ['query Q($n: Int) { items(first: $n) { a } }', ['m' => 3], [], []],
			'variable without variables'   => ['query Q($n: Int) { items(first: $n) { a } }', null, [], []],
			'variable null'                => ['query Q($n: Int) { items(first: $n) { a } }', ['n' => null], [], []],
			'variable then literal'        => ['query Q($n: Int) { items(first: $n, first: 4) { a } }', [], [], []],
			'other arguments'              => ['{ items(last: 9, after: "x", first: "5") { a } }', null, [], []],
			'schema cost override'         => ['{ items(first: 2) { a } people { b } }', null, [], ['items' => 3, 'people' => 0]],
			'inline fragments'             => ['{ node { ... on A { x { y } } ... on B { z } } }', null, [], []],
			'top-level inline fragment'    => ['{ ... on Query { a { b } } }', null, [], []],
			'fragment spread ignored'      => ['query { a { ...F } } fragment F on A { b { c } }', null, [], []],
			'two operations'               => ['query A { a { b } } query B { c { d { e } } }', null, [], []],
			'depth over limit'             => [$nested, null, ['graphql_max_depth' => '2'], []],
			'depth at limit'               => [$nested, null, ['graphql_max_depth' => '3'], []],
			'cost over limit'              => [$lists, null, ['graphql_max_cost' => '50'], []],
			'depth and cost over'          => [$nested, null, ['graphql_max_depth' => '1', 'graphql_max_cost' => '1'], []],
			'non-numeric limits'           => ['{ a }', null, ['graphql_max_depth' => 'abc', 'graphql_max_cost' => '5x'], []],
			'zero cost limit, field only'  => ['{ a }', null, ['graphql_max_cost' => '0'], []],
		];
	}

	/**
	 * @param string                    $query     The query.
	 * @param array<string, mixed>|null $variables The variables.
	 * @param array<string, string>     $config    App config values.
	 * @param array<string, int>        $costs     Schema cost overrides.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAnalyze(string $query, ?array $variables, array $config, array $costs): void {
		$reads = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($config, &$reads): string {
				$reads[] = [$app, $key, $default];
				return ($config[$key] ?? $default);
			}
		);

		$analyzer = new QueryComplexityAnalyzer($appConfig);
		if ($costs !== []) {
			$analyzer->setSchemaCosts($costs);
		}

		try {
			$result = ['result' => $analyzer->analyze(Parser::parse($query), $variables)];
		} catch (Error $e) {
			$result = ['error' => $e->getMessage(), 'extensions' => $e->getExtensions()];
		}

		$this->assertGolden((string) $this->dataName(), $result + ['reads' => $reads]);
	}

	/**
	 * @param string $case   The case name.
	 * @param mixed  $actual The recorded value.
	 *
	 * @return void
	 */
	private function assertGolden(string $case, mixed $actual): void {
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
