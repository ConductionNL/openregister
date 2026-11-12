<?php

/**
 * Golden test for the `mcp` scope helpers moved out of PermissionHandler.
 *
 * The strip and the offered-actions read were static methods on
 * PermissionHandler, which made the SQL-layer interpreter (MagicRbacHandler)
 * and the grantable-rights index name the 1500-line class just to reach two
 * pure functions. They now live in McpScope. This suite pins the outputs of
 * the old methods — for blocks that grant, blocks that refuse, and odd values —
 * and asserts the new class and the old entry points both produce them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\McpScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\McpScope
 */
final class McpScopeGoldenTest extends TestCase {

	/**
	 * Strip inputs and the outputs the original PermissionHandler::stripMcpScope() produced.
	 *
	 * @return array<string, array{0: array|null, 1: array|null}>
	 */
	public static function stripCases(): array {
		$object = new stdClass();

		return [
			'null'                       => [null, null],
			'empty'                      => [[], []],
			'offers only stays a block'  => [['read' => ['mcp']], ['read' => []]],
			'object spelling'            => [['read' => [['group' => 'mcp'], 'users']], ['read' => ['users']]],
			'reindexes a rule list'      => [['read' => [5 => 'mcp', 7 => 'staff']], ['read' => ['staff']]],
			'no mcp, untouched'          => [['read' => ['public'], 'update' => ['admin']], ['read' => ['public'], 'update' => ['admin']]],
			'scalar toggles carried'     => [
				['public' => true, 'inheritFromPublic' => null, 'read' => ['mcp']],
				['public' => true, 'inheritFromPublic' => null, 'read' => []],
			],
			'object toggle carried'      => [['objectScope' => 'x', 'read' => 'mcp'], ['objectScope' => 'x', 'read' => 'mcp']],
			'deny block recurses'        => [
				['deny' => ['read' => ['mcp', 'guests'], 'delete' => [['group' => 'mcp']]]],
				['deny' => ['read' => ['guests'], 'delete' => []]],
			],
			'deny keeps scalar actions'  => [['deny' => ['read' => 'mcp']], ['deny' => ['read' => 'mcp']]],
			'deny empty'                 => [['deny' => []], ['deny' => []]],
			'matrix not reindexed'       => [
				['matrix' => ['field' => 'department', 'rows' => ['mcp']]],
				['matrix' => ['field' => 'department', 'rows' => ['mcp']]],
			],
			'roles not reindexed'        => [['roles' => ['clerk' => ['mcp']]], ['roles' => ['clerk' => ['mcp']]]],
			'odd entries'                => [
				['read' => [42, null, true, 'MCP', ['group' => ['mcp']], ['group' => 'mcp', 'x' => 1], ['user' => 'mcp']]],
				['read' => [42, null, true, 'MCP', ['group' => ['mcp']], ['user' => 'mcp']]],
			],
			'object entry kept'          => [['read' => [$object, 'mcp']], ['read' => [$object]]],
			'numeric action key'         => [[0 => ['mcp', 'a'], 1 => 'b'], [0 => ['a'], 1 => 'b']],
			'custom verb'                => [['besluit_nemen' => [], 'update' => ['mcp']], ['besluit_nemen' => [], 'update' => []]],
		];
	}//end stripCases()

	/**
	 * Offered-actions inputs and the outputs the original PermissionHandler::mcpOfferedActions() produced.
	 *
	 * @return array<string, array{0: array|null, 1: list<string>}>
	 */
	public static function offeredCases(): array {
		return [
			'null'                 => [null, []],
			'empty'                => [[], []],
			'none offered'         => [['read' => ['users'], 'public' => true], []],
			'both spellings'       => [['read' => ['mcp'], 'update' => [['group' => 'mcp']], 'delete' => ['admin']], ['read', 'update']],
			'declaration order'    => [['update' => ['x', 'mcp'], 'read' => ['mcp', 'mcp']], ['update', 'read']],
			'numeric key skipped'  => [[0 => ['mcp'], 'read' => ['mcp']], ['read']],
			'scalar skipped'       => [['read' => 'mcp', 'public' => 'mcp'], []],
			'deny block not read'  => [['deny' => ['read' => ['mcp']]], []],
			'control array listed' => [['roles' => ['mcp']], ['roles']],
			'odd entries'          => [['read' => [42, null, 'MCP', ['group' => ['mcp']]]], []],
		];
	}//end offeredCases()

	/**
	 * The new strip matches the recorded output of the old one.
	 *
	 * @param array|null $input    Block.
	 * @param array|null $expected Recorded output.
	 *
	 * @return void
	 */
	#[DataProvider('stripCases')]
	public function testStripMatchesTheOldOutput(?array $input, ?array $expected): void {
		$this->assertSame(expected: $expected, actual: McpScope::strip(authorization: $input));
	}//end testStripMatchesTheOldOutput()

	/**
	 * The old entry point still produces the recorded output.
	 *
	 * @param array|null $input    Block.
	 * @param array|null $expected Recorded output.
	 *
	 * @return void
	 */
	#[DataProvider('stripCases')]
	public function testTheOldStripEntryPointIsUnchanged(?array $input, ?array $expected): void {
		$this->assertSame(expected: $expected, actual: PermissionHandler::stripMcpScope(authorization: $input));
	}//end testTheOldStripEntryPointIsUnchanged()

	/**
	 * The new offered-actions read matches the recorded output of the old one.
	 *
	 * @param array|null   $input    Block.
	 * @param list<string> $expected Recorded output.
	 *
	 * @return void
	 */
	#[DataProvider('offeredCases')]
	public function testOfferedActionsMatchTheOldOutput(?array $input, array $expected): void {
		$this->assertSame(expected: $expected, actual: McpScope::offeredActions(authorization: $input));
	}//end testOfferedActionsMatchTheOldOutput()

	/**
	 * The old entry point still produces the recorded output.
	 *
	 * @param array|null   $input    Block.
	 * @param list<string> $expected Recorded output.
	 *
	 * @return void
	 */
	#[DataProvider('offeredCases')]
	public function testTheOldOfferedEntryPointIsUnchanged(?array $input, array $expected): void {
		$this->assertSame(expected: $expected, actual: PermissionHandler::mcpOfferedActions(authorization: $input));
	}//end testTheOldOfferedEntryPointIsUnchanged()

	/**
	 * The scope name is the one PermissionHandler has always used.
	 *
	 * @return void
	 */
	public function testTheScopeNameIsUnchanged(): void {
		$this->assertSame(expected: 'mcp', actual: McpScope::SCOPE);
		$this->assertSame(expected: McpScope::SCOPE, actual: PermissionHandler::SCOPE_MCP);
	}//end testTheScopeNameIsUnchanged()

	/**
	 * Both entry spellings name the scope; nothing else does.
	 *
	 * @return void
	 */
	public function testScopeEntryRecognition(): void {
		$this->assertTrue(condition: McpScope::isScopeEntry(entry: 'mcp'));
		$this->assertTrue(condition: McpScope::isScopeEntry(entry: ['group' => 'mcp']));
		$this->assertTrue(condition: McpScope::isScopeEntry(entry: ['group' => 'mcp', 'extra' => 1]));
		foreach (['MCP', ' mcp', 42, null, true, ['group' => ['mcp']], ['user' => 'mcp'], [], new stdClass()] as $entry) {
			$this->assertFalse(condition: McpScope::isScopeEntry(entry: $entry));
		}
	}//end testScopeEntryRecognition()
}//end class
