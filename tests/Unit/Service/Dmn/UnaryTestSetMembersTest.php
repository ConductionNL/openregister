<?php

/**
 * Characterisation tests for how UnaryTestEvaluator splits the members of `in (...)`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Dmn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class UnaryTestSetMembersTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function sets(): array {
		return [
			'nothing'                   => ['', []],
			'blank'                     => ['   ', []],
			'one'                       => [' a ', ['a']],
			'several, trimmed'          => ['a , b,c', ['a', 'b', 'c']],
			'trailing comma'            => ['a,', ['a']],
			'empty member kept'         => ['a,,b', ['a', '', 'b']],
			'leading comma'             => [',a', ['', 'a']],
			'only commas'               => [',,', ['', '']],
			'quoted comma'              => ['"b,c", d', ['b,c', 'd']],
			'quoted, escaped quote'     => ['"say \"hi\"", x', ['say "hi"', 'x']],
			'unbalanced quote'          => ['"a,b', ['"a,b']],
			'quote mid-member'          => ['a"b,c"d, e', ['a"b,c"d', 'e']],
			'lone quote member'         => ['", a', ['", a']],
			'empty quotes'              => ['"", a', ['', 'a']],
		];
	}

	#[DataProvider('sets')]
	public function testTheMembersOfASet(string $inner, array $members): void {
		$evaluator = (new ReflectionClass(UnaryTestEvaluator::class))->newInstanceWithoutConstructor();

		$this->assertSame($members, (new ReflectionMethod(UnaryTestEvaluator::class, 'parseSetMembers'))->invoke($evaluator, $inner));
	}
}//end class
