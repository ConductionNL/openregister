<?php

/**
 * Golden tests for the identifiers ComputedFieldHandler reads out of a Twig expression.
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

use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins which names count as dependencies of a computed expression.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler
 */
class ComputedFieldTwigVariablesGoldenTest extends TestCase {

	/**
	 * Expressions and the identifiers they reference.
	 *
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function expressions(): array {
		return [
			'plain text only' => ['price total', []],
			'empty' => ['', []],
			'empty block' => ['{{}}', []],
			'one variable' => ['{{ price }}', ['price']],
			'arithmetic, order of first use' => ['{{ qty * price + qty }}', ['qty', 'price']],
			'keywords and helpers dropped' => ['{{ a and b or not c in d is e as f with g }}', ['a', 'b', 'c', 'd', 'e', 'f', 'g']],
			'literals and functions dropped' => ['{{ true ? max(x, 1) : min(y, null) ~ date(now) ~ range(1, n)|length ~ random() ~ count }}', ['x', 'y', 'n']],
			'statement block' => ['{% if total > 10 %}{{ label }}{% else %}x{% endif %}', ['label', 'total']],
			'for and set' => ['{% for item in items %}{% set s = item.amount %}{% endfor %}{% do x %}{% block b %}{% endblock %}', ['item', 'items', 's', 'x', 'b']],
			'properties after a dot are not names' => ['{{ order.total + _ref.customer.name }}', ['order']],
			'ref alone dropped' => ['{{ _ref }}', []],
			'underscore and digits' => ['{{ _tmp1 + v2 - 3x }}', ['_tmp1', 'v2']],
			'multiline block' => ["{{\n  first\n  ~ last\n}}", ['first', 'last']],
			'filter names count' => ['{{ name|upper }}', ['name', 'upper']],
			'elseif branch' => ['{% if a %}{% elseif b %}{% endif %}', ['a', 'b']],
		];
	}//end expressions()

	/**
	 * The identifiers of each expression.
	 *
	 * @param string       $expression The Twig expression.
	 * @param list<string> $expected   The identifiers.
	 *
	 * @return void
	 */
	#[DataProvider('expressions')]
	public function testIdentifiers(string $expression, array $expected): void {
		$handler = (new ReflectionClass(ComputedFieldHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ComputedFieldHandler::class, 'extractTwigVariables');

		$this->assertSame($expected, $method->invoke($handler, $expression));
	}//end testIdentifiers()
}//end class
