<?php

/**
 * Golden test for the rules engine's value objects and declaration checks.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Rules\ExpressionValueSources;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every outcome of the rules value objects and the schema-save checks,
 * compared with fixtures/rules-value-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class RulesValueGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/rules-value-golden.json';

	public function testVocabulary(): void {
		$vocabulary = new RuleVocabulary();
		$orders = [];
		foreach (array_merge(array_keys(RuleVocabulary::KINDS), ['unknown']) as $kind) {
			$orders[$kind] = $vocabulary->orderOf($kind);
		}

		$verdicts = [];
		foreach (['fired', 'no_match', 'refused', 'error', 'nope', ''] as $verdict) {
			$verdicts[$verdict] = $vocabulary->hasVerdict($verdict);
		}

		$this->assertGolden(
			'vocabulary',
			[
				'all' => $vocabulary->all(),
				'orders' => $orders,
				'verdicts' => $verdicts,
				'KINDS' => RuleVocabulary::KINDS,
				'VERDICTS' => RuleVocabulary::VERDICTS,
				'ACTIONS' => RuleVocabulary::ACTIONS,
			]
		);
	}//end testVocabulary()

	public function testDescriptor(): void {
		$full = new RuleDescriptor(
			kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION,
			schemaSlug: 'bezwaar',
			key: 'sluiten',
			label: 'Sluiten',
			source: 'x-openregister-lifecycle.transitions.sluiten.condition',
			enabled: false,
			actions: [RuleVocabulary::ACTION_REFUSE_TRANSITION],
			condition: ['>' => [['var' => 'bedrag'], 5]],
			maxObjects: 12,
			scope: ['property' => 'zaaktype', 'value' => 'a']
		);
		$minimal = new RuleDescriptor(kind: 'other', schemaSlug: '', key: 'k', label: 'l', source: 's');
		$this->assertGolden(
			'descriptor',
			[
				'full' => $full->jsonSerialize(),
				'minimal' => $minimal->jsonSerialize(),
				'idFor' => RuleDescriptor::idFor('flow', 'zaak', 'uuid-1'),
				'getters' => [
					$full->getId(),
					$full->getKind(),
					$full->getSchemaSlug(),
					$full->getKey(),
					$full->isEnabled(),
					$full->getCondition(),
					$full->getMaxObjects(),
					$full->getScope(),
				],
			]
		);
	}//end testDescriptor()

	public function testTrace(): void {
		$values = [
			'true' => true,
			'false' => false,
			'null' => null,
			'int' => 12,
			'float' => 1.5,
			'string' => 'abc',
			'exactly limit' => str_repeat('a', 120),
			'long' => str_repeat('é', 121),
			'array' => ['a' => 1, 'b' => [true]],
			'unrenderable' => [INF],
		];
		$rendered = [];
		foreach ($values as $name => $value) {
			$rendered[$name] = RuleTrace::renderValue($value);
		}

		$this->assertGolden(
			'trace',
			[
				'rendered' => $rendered,
				'fired' => RuleTrace::fired()->jsonSerialize(),
				'firedMessage' => RuleTrace::fired('ok')->jsonSerialize(),
				'errored' => RuleTrace::errored('broken')->jsonSerialize(),
				'full' => (new RuleTrace('refused', 'bedrag', '12', 'said'))->jsonSerialize(),
			]
		);
	}//end testTrace()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function dependentSchemas(): array {
		$controlling = ['type' => 'string', 'enum' => ['a', 'b', 3, ['x']]];
		$dependent = ['type' => 'string', 'enum' => ['x', 'y', 7]];
		$table = static fn (mixed $annotation, array $own = []): array => [
			'properties' => [
				'zaaktype' => $controlling,
				'resultaat' => array_merge($dependent, $own, [DependentValueTable::ANNOTATION => $annotation]),
				'vrij' => ['type' => 'string'],
			],
		];
		return [
			'no properties' => [],
			'properties not array' => ['properties' => 'x'],
			'no annotation' => ['properties' => ['a' => ['type' => 'string'], 'b' => 'scalar']],
			'not an array' => $table('x'),
			'empty' => $table([]),
			'no controlledBy' => $table(['allowed' => ['a' => ['x']]]),
			'empty controlledBy' => $table(['controlledBy' => '', 'allowed' => ['a' => ['x']]]),
			'self controlled' => $table(['controlledBy' => 'resultaat', 'allowed' => ['a' => ['x']]]),
			'unknown property' => $table(['controlledBy' => 'ontbreekt', 'allowed' => ['a' => ['x']]]),
			'no allowed' => $table(['controlledBy' => 'zaaktype']),
			'empty allowed' => $table(['controlledBy' => 'zaaktype', 'allowed' => []]),
			'sound' => $table(['controlledBy' => 'zaaktype', 'allowed' => ['a' => ['x'], 'b' => ['y', 7], 3 => ['x']]]),
			'bad pairs' => $table(
				[
					'controlledBy' => 'zaaktype',
					'allowed' => [
						'a' => 'x',
						'b' => [],
						'c' => ['x', 'z', ['nested'], null, 1.5, true],
						'x' => ['y'],
					],
				]
			),
			'scalar pairs' => $table(['controlledBy' => 'zaaktype', 'allowed' => ['a' => ['x', null, 1.5, true], 'b' => 'y']]),
			'own enum absent' => $table(['controlledBy' => 'zaaktype', 'allowed' => ['zz' => ['anything']]], ['enum' => null]),
			'own enum not scalar' => $table(['controlledBy' => 'zaaktype', 'allowed' => ['a' => ['q']]], ['enum' => [['a']]]),
			'controlled by unconstrained' => [
				'properties' => [
					'vrij' => 'scalar',
					'resultaat' => array_merge(
						$dependent,
						[DependentValueTable::ANNOTATION => ['controlledBy' => 'vrij', 'allowed' => ['q' => ['x', 'w']]]]
					),
				],
			],
			'two tables' => [
				'properties' => [
					'zaaktype' => $controlling,
					'a' => [DependentValueTable::ANNOTATION => ['controlledBy' => 'zaaktype', 'allowed' => ['a' => ['1', 2]]]],
					'b' => [DependentValueTable::ANNOTATION => 'x'],
					'c' => [DependentValueTable::ANNOTATION => ['controlledBy' => 'zaaktype', 'allowed' => ['b' => 'x', 'a' => ['3']]]],
				],
			],
		];
	}//end dependentSchemas()

	public function testDependentValueValidator(): void {
		$validator = new DependentValueValidator();
		$actual = [];
		foreach (self::dependentSchemas() as $name => $schema) {
			$actual[$name] = $validator->validate($schema);
		}

		$this->assertGolden('dependentValueValidator', $actual);
	}//end testDependentValueValidator()

	public function testDependentValueTable(): void {
		$table = new DependentValueTable();
		$payloads = [
			'empty' => [[], []],
			'allowed' => [['zaaktype' => 'a', 'resultaat' => 'x'], []],
			'refused' => [['zaaktype' => 'b', 'resultaat' => 'x', 'a' => 1, 'c' => '9'], []],
			'stored controlling' => [['resultaat' => 'y', 'a' => '1', 'c' => 3], ['zaaktype' => 'a']],
			'stored refused' => [['resultaat' => 'q', 'a' => 5], ['zaaktype' => 'a']],
			'unlisted controlling' => [['zaaktype' => 'zz', 'resultaat' => 'q'], []],
			'non scalar value' => [['zaaktype' => 'a', 'resultaat' => ['x']], []],
			'non scalar controlling' => [['zaaktype' => ['a'], 'resultaat' => 'q'], []],
			'empty value' => [['zaaktype' => 'a', 'resultaat' => ''], []],
			'numeric controlling' => [['zaaktype' => 3, 'resultaat' => 'y', 'a' => true], []],
		];
		$actual = [];
		foreach (self::dependentSchemas() as $name => $schema) {
			$properties = ($schema['properties'] ?? []);
			// A nested list among the allowed values is cast with a PHP warning; schema save refuses it first.
			if (is_array($properties) === false || $name === 'bad pairs') {
				continue;
			}

			$actual[$name]['declaresAny'] = $table->declaresAny($properties);
			$actual[$name]['tablesOf'] = $table->tablesOf($properties);
			foreach ($payloads as $payload => [$data, $stored]) {
				$actual[$name]['violations'][$payload] = $table->violations($properties, $data, $stored);
			}
		}

		$this->assertGolden('dependentValueTable', $actual);
	}//end testDependentValueTable()

	public function testExpressionDefaults(): void {
		$expression = ['dateAdd' => [['prop' => 'ontvangstdatum'], 42, 'days']];
		$properties = [
			'scalar' => 'x',
			'none' => ['type' => 'string'],
			'scalarExpression' => [ExpressionDefaultResolver::ANNOTATION => 'x'],
			'emptyExpression' => [ExpressionDefaultResolver::ANNOTATION => []],
			'unknownOperator' => [ExpressionDefaultResolver::ANNOTATION => ['nope' => [1]]],
			'two' => [ExpressionDefaultResolver::ANNOTATION => $expression, 'default' => 'x'],
			'twoNull' => [ExpressionDefaultResolver::ANNOTATION => $expression, 'default' => null],
			'sound' => [ExpressionDefaultResolver::ANNOTATION => $expression],
			'list' => [ExpressionDefaultResolver::ANNOTATION => [['prop' => 'a'], 1]],
		];
		$actual = [
			'validate' => ExpressionDefaultResolver::validateDeclarations($properties),
			'validateEmpty' => ExpressionDefaultResolver::validateDeclarations([]),
		];

		$evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$evaluator->method('evaluate')->willReturnCallback(
			static function (array $data, mixed $node): mixed {
				$op = (string)array_key_first((array)$node);
				if ($op === 'throw') {
					throw new RuntimeException('kapot');
				}

				if ($op === 'nothing') {
					return null;
				}

				return ['derived' => $node, 'from' => $data];
			}
		);
		$resolver = new ExpressionDefaultResolver(evaluator: $evaluator);
		$applyProperties = [
			'a' => [ExpressionDefaultResolver::ANNOTATION => ['ok' => 1]],
			'b' => [ExpressionDefaultResolver::ANNOTATION => ['ok' => 2]],
			'c' => ['type' => 'string'],
			'd' => 'scalar',
			'e' => [ExpressionDefaultResolver::ANNOTATION => ['ok' => 3]],
		];
		$actual['declaresAny'] = [
			$resolver->declaresAny($properties),
			$resolver->declaresAny(['a' => ['type' => 'x'], 'b' => 'y']),
			$resolver->declaresAny([]),
			$resolver->declaresAny(['a' => [ExpressionDefaultResolver::ANNOTATION => null]]),
		];
		$supplied = ['a' => 'given', 'b' => '', 'e' => null];
		$allSupplied = ['a' => 1, 'b' => 0, 'e' => false];
		$actual['apply'] = [
			'nothing supplied' => $resolver->apply($applyProperties, ['z' => 1]),
			'supplied' => $resolver->apply($applyProperties, $supplied),
			'all supplied' => $resolver->apply($applyProperties, $allSupplied),
			'none declared' => $resolver->apply(['c' => ['type' => 'x']], ['q' => 1]),
		];
		foreach (['throw', 'nothing'] as $failure) {
			try {
				$resolver->apply(['p' => [ExpressionDefaultResolver::ANNOTATION => [$failure => 1]]], []);
				$actual['refusal'][$failure] = 'no refusal';
			} catch (ExpressionDefaultException $e) {
				$actual['refusal'][$failure] = [get_class($e), $e->getMessage(), $e->getCode()];
			}
		}

		$this->assertGolden('expressionDefaults', $actual);
	}//end testExpressionDefaults()

	public function testConditionDialect(): void {
		$nodes = [
			'scalar' => 5,
			'string' => 'x',
			'null' => null,
			'empty' => [],
			'list' => [['prop' => 'a'], 1],
			'bad list' => [['nope' => 1]],
			'two keys' => ['gt' => [1, 2], 'lt' => [1, 2]],
			'unknown op' => ['nope' => [1]],
			'lit' => ['lit' => ['nope' => 1]],
			'dict ok' => ['dateAdd' => [['prop' => 'a'], 42, 'days']],
			'dict named' => ['dateAdd' => ['date' => ['prop' => 'a'], 'amount' => 1, 'unit' => 'days']],
			'dict scalar' => ['dateAdd' => 'x'],
			'dict bad' => ['dateAdd' => ['date' => ['nope' => 1]]],
			'nested ok' => ['gt' => [['add' => [['prop' => 'a'], 1]], 5]],
			'nested bad' => ['gt' => [['add' => [['nope' => 1], 1]], 5]],
			'shared and' => ['and' => [['gt' => [1, 0]]]],
			'jsonlogic' => ['>' => [['var' => 'a'], 5]],
			'jsonlogic invalid' => ['>' => 'x'],
			'jsonlogic unknown' => ['zzz' => [1]],
			'ast eq' => ['eq' => [['prop' => 'a'], 7]],
			'ast throws' => ['div' => [1, 0]],
		];
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);
		$actual = [];
		foreach ($nodes as $name => $node) {
			$actual[$name] = [
				'wellFormed' => ConditionDialect::isWellFormedAst($node),
				'valid' => ConditionDialect::isValidCondition($node),
				'holdsA7' => $dialect->holds($node, ['a' => 7]),
				'holdsEmpty' => $dialect->holds($node, []),
			];
		}

		foreach (['and', 'gt', '>', 'lit', 'nope', 'if', 'prop'] as $op) {
			$actual['ops'][$op] = [$dialect->isAst($op), ConditionDialect::isAstOperator($op)];
		}

		$this->assertGolden('conditionDialect', $actual);
	}//end testConditionDialect()

	public function testValueSources(): void {
		$registry = new class () {
			public function resolve(string $reference): mixed {
				if ($reference === 'env:REFUSED') {
					throw new RuntimeException('refused');
				}

				return 'value-of-' . $reference;
			}
		};
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$nodes = [
			'scalar' => 5,
			'plain' => ['eq' => [['prop' => 'a'], 1]],
			'source' => ['source' => 'env:HOST'],
			'nested' => ['eq' => [['source' => 'env:HOST'], ['source' => 'env:PORT']], 'x' => 1],
			'not a reference' => ['source' => 'nocolon'],
			'not a string' => ['source' => 5],
			'two keys' => ['source' => 'env:HOST', 'x' => 1],
			'refused' => ['and' => [['source' => 'env:OK'], ['source' => 'env:REFUSED']]],
		];
		$containers = [
			'registry' => [true, $registry],
			'absent' => [false, null],
			'not resolvable' => [true, new \stdClass()],
			'throws' => [true, 'throw'],
		];
		$actual = [];
		foreach ($containers as $containerName => [$has, $service]) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('has')->willReturn($has);
			$container->method('get')->willReturnCallback(
				static function () use ($service): mixed {
					if ($service === 'throw') {
						throw new RuntimeException('container');
					}

					return $service;
				}
			);
			$sources = new ExpressionValueSources(container: $container, logger: $logger);
			foreach ($nodes as $name => $node) {
				$logged = [];
				$actual[$containerName][$name] = [
					'mentions' => $sources->mentionsSource($node),
					'substitute' => $sources->substitute($node),
					'logged' => $logged,
				];
			}
		}

		$this->assertGolden('valueSources', $actual);
	}//end testValueSources()

	/**
	 * Compare an outcome with its recording, or record it under UPDATE_GOLDEN=1.
	 *
	 * @param string $name   The recording's key.
	 * @param mixed  $actual The outcome.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
