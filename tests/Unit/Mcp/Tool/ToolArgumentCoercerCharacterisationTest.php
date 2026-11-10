<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use OCA\OpenRegister\Mcp\Tool\ToolArgumentCoercer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins how a tool call's arguments are matched to a method and cast.
 *
 * @covers \OCA\OpenRegister\Mcp\Tool\ToolArgumentCoercer
 */
final class ToolArgumentCoercerCharacterisationTest extends TestCase {

	/**
	 * A method with an int, a defaulted string and a defaulted bool.
	 *
	 * @param int    $limit  A count.
	 * @param string $query  A text.
	 * @param bool   $strict A flag.
	 *
	 * @return void
	 */
	public function target(int $limit, string $query='all', bool $strict=false): void {
	}//end target()

	/**
	 * The arguments the coercer builds for the target method.
	 *
	 * @param array $arguments The call's arguments.
	 *
	 * @return array The positional, cast arguments.
	 */
	private function coerce(array $arguments): array {
		return (new ToolArgumentCoercer())->argumentsFor(method: new ReflectionMethod($this, 'target'), arguments: $arguments);
	}//end coerce()

	/**
	 * Positional arguments are cast in order.
	 *
	 * @return void
	 */
	public function testPositionalArgumentsAreCastInOrder(): void {
		$this->assertSame([5, 'x', true], $this->coerce(['5', 'x', 'true']));
	}//end testPositionalArgumentsAreCastInOrder()

	/**
	 * Named arguments are matched by name.
	 *
	 * @return void
	 */
	public function testNamedArgumentsAreMatchedByName(): void {
		$this->assertSame([7, 'all', true], $this->coerce(['strict' => '1', 'limit' => '7']));
	}//end testNamedArgumentsAreMatchedByName()

	/**
	 * Missing and string null take the default.
	 *
	 * @return void
	 */
	public function testMissingAndStringNullTakeTheDefault(): void {
		$this->assertSame([null, 'all', false], $this->coerce([]));
		$this->assertSame([3, 'all', false], $this->coerce([3, 'null', null]));
	}//end testMissingAndStringNullTakeTheDefault()

	/**
	 * A named null falls back to the positional slot.
	 *
	 * @return void
	 */
	public function testANamedNullFallsBackToThePositionalSlot(): void {
		$this->assertSame([2, 'all', false], $this->coerce(['limit' => '2', 'query' => null]));
	}//end testANamedNullFallsBackToThePositionalSlot()
}//end class
