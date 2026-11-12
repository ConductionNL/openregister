<?php

/**
 * The shared base of the built-in MCP tool providers: they answer as the
 * openregister app, list the tools their subclass declares, and refuse a
 * call that leaves out a required parameter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\BuiltIn\AbstractBuiltInToolProvider;
use PHPUnit\Framework\TestCase;

/**
 * AbstractBuiltInToolProvider.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\AbstractBuiltInToolProvider
 */
class AbstractBuiltInToolProviderTest extends TestCase {

	private function provider(): AbstractBuiltInToolProvider {
		return new class extends AbstractBuiltInToolProvider {
			protected const TOOLS = [
				['id' => 'probe.echo', 'description' => 'Echo the given text'],
			];

			public function invokeTool(string $toolId, array $arguments): array {
				$this->requireParam(arguments: $arguments, param: 'text');
				return ['echo' => $arguments['text']];
			}
		};
	}//end provider()

	public function testABuiltInProviderAnswersAsOpenRegister(): void {
		$this->assertSame('openregister', $this->provider()->getAppId());
	}//end testABuiltInProviderAnswersAsOpenRegister()

	public function testTheToolsAreThoseTheSubclassDeclares(): void {
		$this->assertSame([['id' => 'probe.echo', 'description' => 'Echo the given text']], $this->provider()->getTools());
	}//end testTheToolsAreThoseTheSubclassDeclares()

	public function testAProviderWithoutDeclaredToolsHasNone(): void {
		$bare = new class extends AbstractBuiltInToolProvider {
			public function invokeTool(string $toolId, array $arguments): array {
				return [];
			}
		};

		$this->assertSame([], $bare->getTools());
	}//end testAProviderWithoutDeclaredToolsHasNone()

	public function testAPresentParameterIsAccepted(): void {
		$this->assertSame(['echo' => 'hi'], $this->provider()->invokeTool('probe.echo', ['text' => 'hi']));
	}//end testAPresentParameterIsAccepted()

	public function testAMissingOrNullParameterIsRefusedByName(): void {
		foreach ([[], ['text' => null]] as $arguments) {
			try {
				$this->provider()->invokeTool('probe.echo', $arguments);
				$this->fail('Expected the call to be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Missing required parameter: text', $e->getMessage());
			}
		}
	}//end testAMissingOrNullParameterIsRefusedByName()
}//end class
