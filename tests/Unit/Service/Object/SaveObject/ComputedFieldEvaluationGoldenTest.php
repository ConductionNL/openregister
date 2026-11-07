<?php

/**
 * Golden tests for ComputedFieldHandler expression evaluation (sandbox, modes, casting).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins rendered and cast values, evaluation-mode selection and the sandbox.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler
 */
class ComputedFieldEvaluationGoldenTest extends TestCase {

	/**
	 * Evaluate one computed property `out` against the input.
	 *
	 * @param string $expression The expression.
	 * @param array $input The object data.
	 *
	 * @return mixed The value of `out`.
	 */
	private function evaluate(string $expression, array $input = []): mixed {
		$schema = new Schema();
		$schema->setProperties(['out' => ['type' => 'string', 'computed' => ['expression' => $expression]]]);

		$result = $this->handler()->evaluateComputedFields($input, $schema);
		$this->assertArrayHasKey('out', $result);

		return $result['out'];
	}//end evaluate()

	/**
	 * Build the handler.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ComputedFieldHandler
	 */
	private function handler(?LoggerInterface $logger = null): ComputedFieldHandler {
		return new ComputedFieldHandler(
			objectMapper: $this->createMock(MagicMapperInterface::class),
			mappingExtension: new MappingExtension(),
			mappingRuntimeLoader: $this->createMock(MappingRuntimeLoader::class),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * Expressions and the value they yield.
	 *
	 * @return array<string, array{0: string, 1: array, 2: mixed}>
	 */
	public static function expressions(): array {
		return [
			'integer' => ['{{ a + 1 }}', ['a' => 41], 42],
			'negative integer' => ['{{ 0 - 3 }}', [], -3],
			'float' => ['{{ a / 2 }}', ['a' => 3], 1.5],
			'trimmed integer' => [' {{ a }} ', ['a' => '7'], 7],
			'exponent counts as integer' => ['1e3', [], 1000],
			'empty' => ['{{ missing }}', [], ''],
			'text' => ['{{ a }}-{{ b }}', ['a' => 'x', 'b' => 'y'], 'x-y'],
			'no autoescape' => ['{{ a }}', ['a' => '<b>&</b>'], '<b>&</b>'],
			'allowed filter' => ['{{ a|upper }}', ['a' => 'abc'], 'ABC'],
			'allowed function' => ['{{ max(a, 9) }}', ['a' => 3], 9],
			'allowed round filter' => ['{{ a|round }}', ['a' => 2.6], 3],
			'allowed date filter' => ["{{ '2020-01-02'|date('Y') }}", [], 2020],
			'disallowed filter' => ['{{ a|capitalize }}', ['a' => 'abc'], null],
			'disallowed tag' => ['{% set x = 1 %}{{ x }}', [], null],
			'disallowed function' => ['{{ cycle([1, 2], 0) }}', [], null],
			'syntax error' => ['{{ a ', ['a' => 1], null],
		];
	}//end expressions()

	/**
	 * Evaluate an expression.
	 *
	 * @param string $expression The expression.
	 * @param array $input The object data.
	 * @param mixed $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('expressions')]
	public function testExpression(string $expression, array $input, mixed $expected): void {
		$this->assertSame($expected, $this->evaluate($expression, $input));
	}//end testExpression()

	public function testAFailingExpressionIsLoggedAndNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringStartsWith('Computed field evaluation error: '),
			$this->callback(
				static fn (array $c): bool => $c['app'] === 'openregister' && $c['propertyName'] === 'out'
					&& $c['expression'] === '{{ a|capitalize }}' && is_string($c['error']) === true
			)
		);
		$schema = new Schema();
		$schema->setProperties(['out' => ['computed' => ['expression' => '{{ a|capitalize }}']]]);

		$this->assertSame(['a' => 'x', 'out' => null], $this->handler($logger)->evaluateComputedFields(['a' => 'x'], $schema));
	}//end testAFailingExpressionIsLoggedAndNull()

	public function testModesSelectionAndOrder(): void {
		$schema = new Schema();
		$schema->setProperties([
			'base' => ['type' => 'integer'],
			'first' => ['computed' => ['expression' => '{{ base * 2 }}']],
			'second' => ['computed' => ['expression' => '{{ first + 1 }}', 'evaluateOn' => 'save']],
			'onRead' => ['computed' => ['expression' => '{{ base }}r', 'evaluateOn' => 'read']],
			'blank' => ['computed' => ['expression' => '']],
			'zero' => ['computed' => ['expression' => '0']],
			'noExpression' => ['computed' => []],
			'notArray' => ['computed' => '{{ base }}'],
		]);
		$handler = $this->handler();

		$this->assertSame(
			['base' => 5, 'first' => 10, 'second' => 11],
			$handler->evaluateComputedFields(['base' => 5], $schema)
		);
		$this->assertSame(['base' => 5, 'onRead' => '5r'], $handler->evaluateComputedFields(['base' => 5], $schema, 'read'));
		$this->assertSame(['first', 'second', 'blank', 'zero', 'noExpression'], $handler->getComputedPropertyNames($schema));
		$this->assertSame(['onRead'], $handler->getComputedPropertyNames($schema, 'read'));
		$this->assertSame([], $handler->getComputedPropertyNames($schema, 'other'));
		$this->assertTrue($handler->hasComputedProperties($schema));
	}//end testModesSelectionAndOrder()

	public function testWithoutComputedProperties(): void {
		$schema = new Schema();
		$schema->setProperties(['a' => ['type' => 'string'], 'b' => ['computed' => 'x'], 'c' => 'scalar']);
		$handler = $this->handler();

		$this->assertFalse($handler->hasComputedProperties($schema));
		$this->assertSame([], $handler->getComputedPropertyNames($schema));
		$this->assertSame(['a' => 1], $handler->evaluateComputedFields(['a' => 1], $schema));
		$this->assertFalse($handler->hasComputedProperties(new Schema()));
	}//end testWithoutComputedProperties()
}//end class
