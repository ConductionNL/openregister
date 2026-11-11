<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Db;

use OCA\OpenRegister\Mcp\Db\AgentColumns;
use PHPUnit\Framework\TestCase;

/**
 * Pins how an agent array is read into its hydrated columns.
 *
 * @covers \OCA\OpenRegister\Mcp\Db\AgentColumns
 */
final class AgentColumnsCharacterisationTest extends TestCase {

	/**
	 * Camel case wins over the snake case fallback.
	 *
	 * @return void
	 */
	public function testCamelCaseWinsOverTheSnakeCaseFallback(): void {
		$values = AgentColumns::hydrationValues(object: ['maxTokens' => 10, 'max_tokens' => 20]);
		$this->assertSame(10, $values['maxTokens']);
	}//end testCamelCaseWinsOverTheSnakeCaseFallback()

	/**
	 * The snake case fallback is read when camel case is missing.
	 *
	 * @return void
	 */
	public function testTheSnakeCaseFallbackIsReadWhenCamelCaseIsMissing(): void {
		$values = AgentColumns::hydrationValues(object: ['enable_rag' => true, 'search_files' => false]);
		$this->assertTrue($values['enableRag']);
		$this->assertFalse($values['searchFiles']);
	}//end testTheSnakeCaseFallbackIsReadWhenCamelCaseIsMissing()

	/**
	 * Missing values take their default.
	 *
	 * @return void
	 */
	public function testMissingValuesTakeTheirDefault(): void {
		$values = AgentColumns::hydrationValues(object: ['uuid' => 'u-1']);
		$this->assertSame('u-1', $values['uuid']);
		$this->assertNull($values['name']);
		$this->assertTrue($values['active']);
		$this->assertFalse($values['enableRag']);
		$this->assertTrue($values['isPrivate']);
		$this->assertNull($values['maxTokens']);
	}//end testMissingValuesTakeTheirDefault()

	/**
	 * A column without a fallback ignores other keys.
	 *
	 * @return void
	 */
	public function testAColumnWithoutAFallbackIgnoresOtherKeys(): void {
		$values = AgentColumns::hydrationValues(object: ['active' => null, 'name' => null]);
		$this->assertTrue($values['active']);
		$this->assertNull($values['name']);
	}//end testAColumnWithoutAFallbackIgnoresOtherKeys()
}//end class
